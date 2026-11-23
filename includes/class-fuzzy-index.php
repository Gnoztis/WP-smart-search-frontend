<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Fuzzy index: resilience to typos.
 *
 * Two independent mechanisms, both of them over a dictionary of stems:
 *   1. BK-tree (Burkhard-Keller): a metric tree for Levenshtein search.
 *   2. N-gram index: 3-char grams with overlap/Jaccard scoring.
 *
 * Where the dictionary comes from
 * ------------------------------
 * The authoritative list of stems is the plugin's own terms table
 * (`wp_wssf_terms`), the same table autocomplete already reads: one row per
 * stem with its document frequency, a PRIMARY KEY on `term`, and no LONGTEXT
 * columns. The dictionary is the 5000 most frequent `word` stems, ordered by
 * post_count.
 *
 * Until 5.4.1 the dictionary was read with
 * `SELECT title_stem, tags_stem FROM wp_wssf_index LIMIT 5000` on *every*
 * request where the cache was cold — a 5000-row scan of a table whose rows
 * hold the whole stemmed post text, repeated on every autocomplete miss.
 *
 * Caching
 * -------
 * - The dictionary is stored in a transient keyed by the cache generation
 *   (`wssf_fuzzy_dict_v2_<generation>`), TTL 6 hours. Any index change bumps
 *   the generation, so the dictionary is rebuilt once per index change and
 *   never on a warm request.
 * - The BK-tree and the n-gram index keep living in WSSF_Cache (Redis) and
 *   are keyed by the generation as well.
 * - When the site cannot keep the dictionary transient (a non-persistent
 *   object cache, a filter that drops transients), the full dictionary is
 *   never built: each token is answered from a bounded prefix query on
 *   `wp_wssf_terms` (LIMIT 500 on the token's first PREFIX_LEN characters).
 *   That keeps the request at one index-backed query instead of a scan, at
 *   the cost of recall: a typo inside the first two characters of a word is
 *   not corrected on such a site. Sites with working storage keep the full
 *   recall they had before.
 *
 * Trade-off of the cap: only the DICT_LIMIT most frequent stems are used for
 * typo correction — a typo in a rare stem that never appears in the top 5000
 * is not corrected. That is deliberate: the dictionary has to stay bounded in
 * memory, and frequent words are the ones users actually misspell.
 */
final class WSSF_Fuzzy_Index {

    private const DICT_CACHE_TTL  = 6 * HOUR_IN_SECONDS;
    private const BK_CACHE_TTL    = 6 * HOUR_IN_SECONDS;
    private const NGRAM_CACHE_TTL = 6 * HOUR_IN_SECONDS;

    /** Maximum number of stems in the in-memory dictionary. */
    private const DICT_LIMIT = 5000;

    /** Maximum rows of one lazy prefix query (no-cache path). */
    private const PREFIX_LIMIT = 500;

    /** Characters of the token used by the lazy prefix query. */
    private const PREFIX_LEN = 2;

    private const TERM_TYPE = 'word';
    private const MIN_TERM_LEN = 4;
    private const MAX_TERM_LEN = 24;
    private const NGRAM_SIZE   = 3;

    /** Transient key prefix: dictionary, then "<generation>". */
    private const DICT_KEY = 'wssf_fuzzy_dict_v2_';

    private static ?bool $dict_cache_usable = null;

    /** Dictionary memo for the current request (see get_dictionary()). */
    private ?array $dict_memo = null;

    public function __construct( private WSSF_Cache $cache ) {}

    /**
     * Returns alternatives for the words of a query.
     *
     * @param string[] $tokens      query stems
     * @param int      $max_results maximum candidates per word
     * @return string[]             alternative stems (the input ones excluded)
     */
    public function find_alternatives( array $tokens, int $max_results = 3 ): array {
        $out = [];
        foreach ( $tokens as $tok ) {
            $tok = (string) $tok;
            $len = mb_strlen( $tok );
            if ( $len < self::MIN_TERM_LEN || $len > self::MAX_TERM_LEN ) { continue; }

            $max_dist = ( $len <= 6 ) ? 1 : 2;

            [ $dict, $is_full ] = $this->dictionary_for_token( $tok );
            if ( empty( $dict ) ) { continue; }

            $bk  = $this->bk_search( $tok, $max_dist, $max_results, $is_full ? null : $dict );
            $ngr = $this->ngram_search( $tok, $max_results, $is_full ? null : $dict );

            // BK-tree is more precise, n-grams are wider: merge both.
            foreach ( array_merge( $bk, $ngr ) as $cand ) {
                $cand = (string) $cand;
                if ( $cand === '' || $cand === $tok ) { continue; }
                if ( isset( $out[ $cand ] ) ) { continue; }
                $out[ $cand ] = true;
                if ( count( $out ) >= $max_results * 2 ) { break 2; }
            }
        }
        return array_keys( $out );
    }

    /* ============================================================
     *  Dictionary
     * ============================================================ */

    /**
     * Dictionary to search for one token.
     *
     * @return array{0:array<string,int>,1:bool} Dictionary and whether it is
     *         the full one (its structures are cached in Redis).
     */
    private function dictionary_for_token( string $tok ): array {
        if ( self::$dict_cache_usable === false ) {
            return [ $this->get_dictionary_by_prefix( $tok ), false ];
        }

        $dict = $this->get_dictionary();
        if ( ! empty( $dict ) ) {
            return [ $dict, true ];
        }

        // Empty index: a prefix lookup is the only thing left.
        return [ $this->get_dictionary_by_prefix( $tok ), false ];
    }

    /**
     * Full dictionary for the current index generation.
     *
     * Built at most once per generation: the transient carries both the data
     * and the generation in its key, so an index change makes it unreachable.
     * Returns an empty array when the site cannot keep the transient — the
     * caller then uses the bounded prefix lookup instead of selecting the
     * whole dictionary on every request.
     *
     * @return array<string,int> term => frequency
     */
    private function get_dictionary(): array {
        if ( $this->dict_memo !== null ) {
            return $this->dict_memo;
        }

        $key    = self::DICT_KEY . $this->cache->generation();
        $cached = get_transient( $key );

        if ( is_array( $cached ) && isset( $cached['dict'] ) && is_array( $cached['dict'] ) ) {
            $this->dict_memo = $this->cap_dictionary( $cached['dict'] );
            return $this->dict_memo;
        }

        // Cold dictionary: build it only where the storage really keeps it.
        if ( ! self::transient_cache_works() ) {
            return [];
        }

        $dict = $this->cap_dictionary( $this->query_dictionary() );
        if ( empty( $dict ) ) {
            return [];
        }

        set_transient( $key, [ 'dict' => $dict ], self::DICT_CACHE_TTL );

        // A write that does not survive the request means the full dictionary
        // would be rebuilt on every request: fall back to prefix lookups.
        $check = get_transient( $key );
        if ( ! is_array( $check ) || ! isset( $check['dict'] ) ) {
            delete_transient( $key );
            self::$dict_cache_usable = false;
            $this->debug_log( 'the transient cache did not keep the fuzzy dictionary, using prefix lookups' );
            return [];
        }

        $this->dict_memo = $dict;

        return $this->dict_memo;
    }

    /**
     * Probes once per request whether transients survive a write.
     *
     * An object cache without persistence accepts set_transient() and answers
     * false right after; without this probe such a site would rebuild the
     * whole dictionary on every request.
     */
    private static function transient_cache_works(): bool {
        if ( self::$dict_cache_usable !== null ) {
            return self::$dict_cache_usable;
        }

        $probe = 'wssf_fuzzy_probe';
        set_transient( $probe, 1, MINUTE_IN_SECONDS );
        self::$dict_cache_usable = ( 1 === (int) get_transient( $probe ) );
        delete_transient( $probe );

        if ( ! self::$dict_cache_usable ) {
            self::debug_log( 'the transient cache is not persistent, falling back to prefix lookups' );
        }

        return self::$dict_cache_usable;
    }

    private static function debug_log( string $message ): void {
        if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG || ! function_exists( 'error_log' ) ) {
            return;
        }
        error_log( '[WSSF] fuzzy dictionary: ' . sanitize_text_field( $message ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG only.
    }

    /**
     * Bounded dictionary for one token: stems sharing its first characters.
     *
     * One index-backed query on PRIMARY KEY(term), LIMIT PREFIX_LIMIT, cached
     * in a transient keyed by generation and prefix.
     *
     * @return array<string,int>
     */
    private function get_dictionary_by_prefix( string $tok ): array {
        $prefix = mb_substr( mb_strtolower( $tok ), 0, self::PREFIX_LEN );
        if ( mb_strlen( $prefix ) < self::PREFIX_LEN ) {
            return [];
        }

        $key    = self::DICT_KEY . 'p_' . $this->cache->generation() . '_' . md5( $prefix );
        $cached = get_transient( $key );
        if ( is_array( $cached ) && isset( $cached['dict'] ) && is_array( $cached['dict'] ) ) {
            return $cached['dict'];
        }

        $dict = $this->cap_dictionary( $this->query_dictionary( $prefix ) );

        if ( ! empty( $dict ) && self::transient_cache_works() ) {
            set_transient( $key, [ 'dict' => $dict ], self::DICT_CACHE_TTL );
        }

        return $dict;
    }

    /**
     * Reads stems from the terms table.
     *
     * @param string|null $prefix When set, only stems starting with it.
     * @return array<string,int> term => frequency
     */
    private function query_dictionary( ?string $prefix = null ): array {
        global $wpdb;

        $table = (string) $wpdb->prefix . 'wssf_terms';
        if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', $table ) ) {
            return [];
        }

        try {
            if ( $prefix === null ) {
                $rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- literal SQL; only an int constant is interpolated and the table name is validated with /^[a-zA-Z0-9_]+$/.
                    "SELECT term, post_count FROM `{$table}`
                     WHERE term_type = '" . self::TERM_TYPE . "'
                     ORDER BY post_count DESC, term ASC
                     LIMIT " . (int) self::DICT_LIMIT
                );
            } else {
                $rows = $wpdb->get_results( $wpdb->prepare(
                    "SELECT term, post_count FROM `{$table}`
                     WHERE term_type = %s AND term LIKE %s
                     ORDER BY post_count DESC, term ASC
                     LIMIT " . (int) self::PREFIX_LIMIT,
                    self::TERM_TYPE,
                    $wpdb->esc_like( $prefix ) . '%'
                ) );
            }
        } catch ( \Throwable $e ) {
            return [];
        }

        if ( ! is_array( $rows ) ) { return []; }

        $dict = [];
        foreach ( $rows as $row ) {
            $term = (string) ( $row->term ?? '' );
            if ( $term === '' || mb_strlen( $term ) < self::MIN_TERM_LEN ) { continue; }
            $dict[ $term ] = (int) ( $row->post_count ?? 0 );
        }

        arsort( $dict );

        return $dict;
    }

    /**
     * Keeps the dictionary bounded: the most frequent terms survive.
     *
     * @param array<string,int> $dict
     * @return array<string,int>
     */
    private function cap_dictionary( array $dict ): array {
        if ( count( $dict ) <= self::DICT_LIMIT ) {
            return $dict;
        }
        return array_slice( $dict, 0, self::DICT_LIMIT, true );
    }

    /* ============================================================
     *  BK-tree
     * ============================================================ */

    /**
     * BK-tree lookup.
     *
     * @param array<string,int>|null $dict Search this dictionary in memory
     *                                     (bounded, no shared structure) or
     *                                     the cached full tree when null.
     * @return string[]
     */
    private function bk_search( string $needle, int $max_dist, int $limit, ?array $dict = null ): array {
        $tree = $dict === null ? $this->get_bk_tree() : $this->build_bk_tree( $dict );
        if ( empty( $tree ) || ! isset( $tree['nodes'][ $tree['root'] ] ) ) { return []; }

        $results = [];
        $stack   = [ $tree['root'] ];
        $visited = [];

        while ( ! empty( $stack ) ) {
            $node_id = (int) array_pop( $stack );
            if ( isset( $visited[ $node_id ] ) ) { continue; }
            $visited[ $node_id ] = true;

            $node = $tree['nodes'][ $node_id ] ?? null;
            if ( ! is_array( $node ) ) { continue; }

            $term = (string) ( $node['term'] ?? '' );
            if ( $term === '' ) { continue; }

            $dist = $this->levenshtein_mb( $needle, $term );

            if ( $dist > 0 && $dist <= $max_dist ) {
                $results[] = $term;
            }

            // BK-tree pruning: only children within [dist - max, dist + max].
            $children = $node['children'] ?? [];
            if ( is_array( $children ) ) {
                $min = $dist - $max_dist;
                $max = $dist + $max_dist;
                foreach ( $children as $edge => $child_id ) {
                    $edge = (int) $edge;
                    if ( $edge >= $min && $edge <= $max ) {
                        $stack[] = (int) $child_id;
                    }
                }
            }

            if ( count( $results ) >= $limit * 3 ) { break; }
        }

        usort( $results, function ( $a, $b ) use ( $needle ) {
            return $this->levenshtein_mb( $needle, (string) $a ) <=> $this->levenshtein_mb( $needle, (string) $b );
        } );
        return array_slice( $results, 0, $limit );
    }

    /**
     * Cached BK-tree of the full dictionary.
     *
     * @return array{root:int, nodes:array<int,array{term:string, children:array<int,int>}>}
     */
    private function get_bk_tree(): array {
        $key    = 'wssf_bk_tree_v2_' . $this->cache->generation();
        $cached = $this->cache->get( $key );
        if ( is_array( $cached ) && isset( $cached['tree'] ) && is_array( $cached['tree'] ) ) {
            return $cached['tree'];
        }

        $tree = $this->build_bk_tree( $this->get_dictionary() );
        if ( ! empty( $tree ) ) {
            $this->cache->set( $key, [ 'tree' => $tree ], self::BK_CACHE_TTL );
        }
        return $tree;
    }

    /**
     * @param array<string,int> $dict
     * @return array{root:int, nodes:array<int,array{term:string, children:array<int,int>}>}
     */
    private function build_bk_tree( array $dict ): array {
        if ( empty( $dict ) ) { return []; }

        $nodes   = [];
        $root_id = -1;
        $next_id = 0;

        foreach ( array_keys( $dict ) as $term ) {
            $term = (string) $term;
            if ( mb_strlen( $term ) < self::MIN_TERM_LEN ) { continue; }

            if ( $root_id === -1 ) {
                $nodes[ $next_id ] = [ 'term' => $term, 'children' => [] ];
                $root_id = $next_id;
                $next_id++;
                continue;
            }

            $current = $root_id;
            $guard   = 0;
            while ( $guard++ < 100 ) {
                $cur_term = (string) $nodes[ $current ]['term'];
                $dist     = $this->levenshtein_mb( $term, $cur_term );
                if ( $dist === 0 ) { break; } // duplicate
                $children = $nodes[ $current ]['children'];
                if ( isset( $children[ $dist ] ) ) {
                    $current = (int) $children[ $dist ];
                    continue;
                }
                $nodes[ $next_id ] = [ 'term' => $term, 'children' => [] ];
                $nodes[ $current ]['children'][ $dist ] = $next_id;
                $next_id++;
                break;
            }
        }

        return [ 'root' => $root_id, 'nodes' => $nodes ];
    }

    /* ============================================================
     *  N-gram
     * ============================================================ */

    /**
     * @param array<string,int>|null $dict See bk_search().
     * @return string[]
     */
    private function ngram_search( string $needle, int $limit, ?array $dict = null ): array {
        $index = $dict === null ? $this->get_ngram_index() : $this->build_ngram_index( $dict );
        if ( empty( $index ) ) { return []; }

        $needle_grams = $this->make_ngrams( $needle );
        if ( empty( $needle_grams ) ) { return []; }

        $scores = [];
        foreach ( $needle_grams as $gram ) {
            if ( ! isset( $index[ $gram ] ) ) { continue; }
            foreach ( $index[ $gram ] as $term ) {
                $term = (string) $term;
                $scores[ $term ] = ( $scores[ $term ] ?? 0 ) + 1;
            }
        }

        if ( empty( $scores ) ) { return []; }

        // Normalize by the number of needle grams (Jaccard-like).
        $needle_count = count( $needle_grams );
        foreach ( $scores as $term => $count ) {
            $scores[ $term ] = $count / $needle_count;
        }
        arsort( $scores );

        $out = [];
        foreach ( $scores as $term => $score ) {
            if ( $score < 0.4 ) { continue; } // drop the noise
            if ( (string) $term === $needle ) { continue; }
            $out[] = (string) $term;
            if ( count( $out ) >= $limit ) { break; }
        }
        return $out;
    }

    /**
     * @return array<string, string[]> ngram => [terms]
     */
    private function get_ngram_index(): array {
        $key    = 'wssf_ngram_index_v2_' . $this->cache->generation();
        $cached = $this->cache->get( $key );
        if ( is_array( $cached ) && isset( $cached['index'] ) && is_array( $cached['index'] ) ) {
            return $cached['index'];
        }

        $index = $this->build_ngram_index( $this->get_dictionary() );
        if ( ! empty( $index ) ) {
            $this->cache->set( $key, [ 'index' => $index ], self::NGRAM_CACHE_TTL );
        }
        return $index;
    }

    /**
     * @param array<string,int> $dict
     * @return array<string, string[]>
     */
    private function build_ngram_index( array $dict ): array {
        if ( empty( $dict ) ) { return []; }

        $index = [];
        foreach ( array_keys( $dict ) as $term ) {
            $term  = (string) $term;
            $grams = $this->make_ngrams( $term );
            foreach ( $grams as $gram ) {
                $index[ $gram ][ (string) $term ] = true;
            }
        }
        // Turn the set into a list.
        foreach ( $index as $gram => $set ) {
            $index[ $gram ] = array_keys( $set );
        }

        return $index;
    }

    /**
     * @return string[]
     */
    private function make_ngrams( string $term ): array {
        $term = mb_strtolower( $term );
        $len  = mb_strlen( $term );
        $size = self::NGRAM_SIZE;
        if ( $len < $size ) { return [ $term ]; }

        $out = [];
        for ( $i = 0; $i <= $len - $size; $i++ ) {
            $out[] = mb_substr( $term, $i, $size );
        }
        return array_values( array_unique( $out ) );
    }

    /* ============================================================
     *  Levenshtein
     * ============================================================ */

    private function levenshtein_mb( string $a, string $b ): int {
        if ( $a === $b ) { return 0; }
        $la = mb_strlen( $a );
        $lb = mb_strlen( $b );
        if ( $la === 0 ) { return $lb; }
        if ( $lb === 0 ) { return $la; }

        $prev = range( 0, $lb );
        for ( $i = 1; $i <= $la; $i++ ) {
            $curr = [ $i ];
            $ca   = mb_substr( $a, $i - 1, 1 );
            for ( $j = 1; $j <= $lb; $j++ ) {
                $cb = mb_substr( $b, $j - 1, 1 );
                $cost = ( $ca === $cb ) ? 0 : 1;
                $curr[ $j ] = min(
                    $curr[ $j - 1 ] + 1,
                    $prev[ $j ] + 1,
                    $prev[ $j - 1 ] + $cost
                );
            }
            $prev = $curr;
        }
        return $prev[ $lb ];
    }
}
