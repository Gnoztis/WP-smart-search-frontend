<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Fuzzy-индекс для устойчивости к опечаткам.
 *
 * Два независимых механизма:
 *   1. BK-tree (Burkhard-Keller): метрическое дерево для поиска
 *      по расстоянию Левенштейна за O(log N) при малых dist.
 *      Строится один раз из словаря стемов индекса.
 *   2. N-gram индекс: разбивает слова на символьные n-граммы,
 *      сопоставляет по overlap/Jaccard. Устойчив к перестановкам
 *      и пропускам символов.
 *
 * Используется как fallback при total == 0 в поиске (вместо
 * текущего перебора всего словаря).
 *
 * Все структуры кешируются в WSSF_Cache (Redis или transient).
 */
final class WSSF_Fuzzy_Index {

    private const DICT_CACHE_TTL  = 6 * HOUR_IN_SECONDS;
    private const BK_CACHE_TTL    = 6 * HOUR_IN_SECONDS;
    private const NGRAM_CACHE_TTL = 6 * HOUR_IN_SECONDS;

    private const DICT_LIMIT     = 20000;
    private const MIN_TERM_LEN   = 4;
    private const MAX_TERM_LEN   = 24;
    private const NGRAM_SIZE     = 3;

    public function __construct( private WSSF_Cache $cache ) {}

    /**
     * Возвращает альтернативы для слов запроса.
     *
     * @param string[] $tokens      стемы запроса
     * @param int      $max_results максимум кандидатов на слово
     * @return string[]             альтернативные стемы (без исходных)
     */
    public function find_alternatives( array $tokens, int $max_results = 3 ): array {
        $out = [];
        foreach ( $tokens as $tok ) {
            $tok = (string) $tok;
            $len = mb_strlen( $tok );
            if ( $len < self::MIN_TERM_LEN || $len > self::MAX_TERM_LEN ) { continue; }

            $max_dist = ( $len <= 6 ) ? 1 : 2;

            $bk   = $this->bk_search( $tok, $max_dist, $max_results );
            $ngr  = $this->ngram_search( $tok, $max_results );

            // Объединяем результаты (BK-tree точнее, n-gram — шире).
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
     *  BK-tree
     * ============================================================ */

    /**
     * Поиск в BK-tree. Дерево строится из словаря и кешируется
     * в виде сериализованного массива (adjacency list).
     *
     * Формат: ['root' => int, 'nodes' => [id => ['term' => string, 'children' => [dist => id]]]]
     *
     * @return string[]
     */
    private function bk_search( string $needle, int $max_dist, int $limit ): array {
        $tree = $this->get_bk_tree();
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

            // BK-tree pruning: посещаем только детей в диапазоне
            // [dist - max_dist, dist + max_dist].
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

        // Сортируем по расстоянию, отдаём top-$limit.
        usort( $results, function ( $a, $b ) use ( $needle ) {
            return $this->levenshtein_mb( $needle, (string) $a ) <=> $this->levenshtein_mb( $needle, (string) $b );
        } );
        return array_slice( $results, 0, $limit );
    }

    /**
     * Возвращает BK-tree, кешированный в WSSF_Cache.
     * @return array{root:int, nodes:array<int,array{term:string, children:array<int,int>}>}
     */
    private function get_bk_tree(): array {
        $cached = $this->cache->get( 'wssf_bk_tree_v1' );
        if ( is_array( $cached ) && isset( $cached['tree'] ) && is_array( $cached['tree'] ) ) {
            return $cached['tree'];
        }

        $dict = $this->get_dictionary();
        if ( empty( $dict ) ) { return []; }

        // Строим BK-tree.
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
                if ( $dist === 0 ) { break; } // дубликат
                $children = $nodes[ $current ]['children'];
                if ( isset( $children[ $dist ] ) ) {
                    $current = (int) $children[ $dist ];
                    continue;
                }
                // Добавляем нового ребёнка.
                $nodes[ $next_id ] = [ 'term' => $term, 'children' => [] ];
                $nodes[ $current ]['children'][ $dist ] = $next_id;
                $next_id++;
                break;
            }
        }

        $tree = [ 'root' => $root_id, 'nodes' => $nodes ];
        if ( $root_id !== -1 ) {
            $this->cache->set( 'wssf_bk_tree_v1', [ 'tree' => $tree ], self::BK_CACHE_TTL );
        }
        return $tree;
    }

    /* ============================================================
     *  N-gram
     * ============================================================ */

    /**
     * N-gram поиск по словарю.
     * @return string[]
     */
    private function ngram_search( string $needle, int $limit ): array {
        $index = $this->get_ngram_index();
        if ( empty( $index ) ) { return []; }

        $needle_grams = $this->make_ngrams( $needle );
        if ( empty( $needle_grams ) ) { return []; }

        // Считаем overlap для каждого термина.
        $scores = [];
        foreach ( $needle_grams as $gram ) {
            if ( ! isset( $index[ $gram ] ) ) { continue; }
            foreach ( $index[ $gram ] as $term ) {
                $term = (string) $term;
                $scores[ $term ] = ( $scores[ $term ] ?? 0 ) + 1;
            }
        }

        if ( empty( $scores ) ) { return []; }

        // Нормализуем по длине needle_grams (Jaccard-подобно).
        $needle_count = count( $needle_grams );
        foreach ( $scores as $term => $count ) {
            $scores[ $term ] = $count / $needle_count;
        }
        arsort( $scores );

        $out = [];
        foreach ( $scores as $term => $score ) {
            if ( $score < 0.4 ) { continue; } // отсечение мусора
            if ( (string) $term === $needle ) { continue; }
            $out[] = (string) $term;
            if ( count( $out ) >= $limit ) { break; }
        }
        return $out;
    }

    /**
     * @return array<string, string[]>  ngram => [terms]
     */
    private function get_ngram_index(): array {
        $cached = $this->cache->get( 'wssf_ngram_index_v1' );
        if ( is_array( $cached ) && isset( $cached['index'] ) && is_array( $cached['index'] ) ) {
            return $cached['index'];
        }

        $dict = $this->get_dictionary();
        if ( empty( $dict ) ) { return []; }

        $index = [];
        foreach ( array_keys( $dict ) as $term ) {
            $term = (string) $term;
            $grams = $this->make_ngrams( $term );
            foreach ( $grams as $gram ) {
                $index[ $gram ][ (string) $term ] = true;
            }
        }
        // Превращаем set в list.
        foreach ( $index as $gram => $set ) {
            $index[ $gram ] = array_keys( $set );
        }

        $this->cache->set( 'wssf_ngram_index_v1', [ 'index' => $index ], self::NGRAM_CACHE_TTL );
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
     *  Словарь
     * ============================================================ */

    /**
     * @return array<string,int>  term => freq
     */
    private function get_dictionary(): array {
        $cached = $this->cache->get( 'wssf_fuzzy_dict_v1' );
        if ( is_array( $cached ) && isset( $cached['dict'] ) && is_array( $cached['dict'] ) ) {
            return $cached['dict'];
        }

        global $wpdb;
        $table = (string) $wpdb->prefix . 'wssf_index';
        if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', $table ) ) { return []; }

        try {
            $rows = $wpdb->get_results( "SELECT title_stem, tags_stem FROM `{$table}` LIMIT 5000" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- literal SQL; the table name is validated with /^[a-zA-Z0-9_]+$/.
        } catch ( \Throwable $e ) {
            return [];
        }
        if ( ! is_array( $rows ) ) { return []; }

        $dict = [];
        foreach ( $rows as $row ) {
            $text = (string) $row->title_stem . ' ' . (string) $row->tags_stem;
            $tokens = preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
            if ( ! is_array( $tokens ) ) { continue; }
            foreach ( $tokens as $t ) {
                $t = (string) $t;
                if ( mb_strlen( $t ) < self::MIN_TERM_LEN ) { continue; }
                $dict[ $t ] = ( $dict[ $t ] ?? 0 ) + 1;
                if ( count( $dict ) >= self::DICT_LIMIT ) { break 2; }
            }
        }
        arsort( $dict );

        $this->cache->set( 'wssf_fuzzy_dict_v1', [ 'dict' => $dict ], self::DICT_CACHE_TTL );
        return $dict;
    }

    /* ============================================================
     *  Левенштейн
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