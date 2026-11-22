<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Локальный «семантический» слой. Без внешних API.
 *
 * Техники:
 *   1. PRF (Rocchio/RM3 + биграммы) — через WSSF_PRF_Expander.
 *   2. Синонимия через теги — co-occurrence.
 *   3. Опечатки — через WSSF_Fuzzy_Index (BK-tree + n-gram).
 *
 * Всё кешируется в WSSF_Cache. Никаких сетевых вызовов.
 */
final class WSSF_Semantic_Search {

    private const SYNONYM_CACHE_TTL = HOUR_IN_SECONDS;
    private const PRF_CACHE_TTL     = HOUR_IN_SECONDS;
    private const FUZZY_CACHE_TTL   = HOUR_IN_SECONDS;

    private const SYNONYM_MAX_TERMS = 3;
    private const SYNONYM_MIN_COUNT = 3;
    private const SYNONYM_MAX_POSTS = 200;

    private WSSF_PRF_Expander $prf;
    private WSSF_Fuzzy_Index  $fuzzy;

    public function __construct(
        private WSSF_Cache $cache,
        private WSSF_Tokenizer $tokenizer
    ) {
        $this->prf   = new WSSF_PRF_Expander( $tokenizer );
        $this->fuzzy = new WSSF_Fuzzy_Index( $cache );
    }

    public function is_enabled(): bool {
        return $this->is_prf_enabled() || $this->is_synonyms_enabled() || $this->is_typo_enabled();
    }

    public function is_prf_enabled(): bool {
        return (bool) get_option( 'wssf_semantic_prf', 0 );
    }

    public function is_synonyms_enabled(): bool {
        return (bool) get_option( 'wssf_semantic_synonyms', 0 );
    }

    public function is_typo_enabled(): bool {
        return (bool) get_option( 'wssf_semantic_typo', 0 );
    }

    /**
     * Расширяет запрос через PRF (Rocchio/RM3 + биграммы).
     *
     * @param array  $groups        стеммированные группы
     * @param array  $top_post_ids  ID топ-документов из BM25
     * @param string $model         'rocchio' | 'rm3'
     * @return array{terms:string[], bigrams:array<int,array{0:string,1:string}>}
     */
    public function prf_expand( array $groups, array $top_post_ids, string $model = 'rm3' ): array {
        if ( ! $this->is_prf_enabled() || empty( $top_post_ids ) ) {
            return [ 'terms' => [], 'bigrams' => [] ];
        }

        $cache_key = 'wssf_prf_v2_' . md5( wp_json_encode( $groups ) . '|' . implode( ',', $top_post_ids ) . '|' . $model );
        $cached    = $this->cache->get( $cache_key );
        if ( is_array( $cached ) && isset( $cached['terms'] ) ) {
            return $cached;
        }

        $result = $this->prf->expand( $groups, $top_post_ids, $model );
        $this->cache->set( $cache_key, $result, self::PRF_CACHE_TTL );
        return $result;
    }

    /**
     * Ищет альтернативы для опечаток.
     *
     * @param string[] $tokens
     * @return string[]
     */
    public function fuzzy_alternatives( array $tokens ): array {
        if ( ! $this->is_typo_enabled() || empty( $tokens ) ) { return []; }

        $cache_key = 'wssf_fuzzy_alt_' . md5( implode( ',', $tokens ) );
        $cached    = $this->cache->get( $cache_key );
        if ( is_array( $cached ) && isset( $cached['terms'] ) ) {
            return (array) $cached['terms'];
        }

        $terms = $this->fuzzy->find_alternatives( $tokens );
        $this->cache->set( $cache_key, [ 'terms' => $terms ], self::FUZZY_CACHE_TTL );
        return $terms;
    }

    /**
     * Синонимы через теги (co-occurrence).
     *
     * @return string[]
     */
    public function synonyms_from_tags( string $raw_query, array $exclude_groups ): array {
        if ( ! $this->is_synonyms_enabled() || $raw_query === '' ) { return []; }

        global $wpdb;

        $cache_key = 'wssf_syn_v2_' . md5( mb_strtolower( $raw_query ) );
        $cached    = $this->cache->get( $cache_key );
        if ( is_array( $cached ) && isset( $cached['terms'] ) ) {
            return (array) $cached['terms'];
        }

        $words = preg_split( '/\s+/u', $raw_query, -1, PREG_SPLIT_NO_EMPTY );
        if ( ! is_array( $words ) || empty( $words ) ) { return []; }

        $found_tag_ids = [];
        foreach ( $words as $w ) {
            $w = trim( (string) $w );
            if ( mb_strlen( $w ) < 3 ) { continue; }
            try {
                $rows = $wpdb->get_col( $wpdb->prepare(
                    "SELECT tt.term_id FROM {$wpdb->term_taxonomy} tt
                     INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
                     WHERE tt.taxonomy = 'post_tag' AND (t.name = %s OR t.slug = %s)
                     LIMIT 3",
                    $w,
                    sanitize_title( $w )
                ) );
            } catch ( \Throwable $e ) { continue; }
            if ( is_array( $rows ) ) {
                foreach ( $rows as $id ) { $found_tag_ids[ (int) $id ] = true; }
            }
        }

        if ( empty( $found_tag_ids ) ) {
            $this->cache->set( $cache_key, [ 'terms' => [] ], self::SYNONYM_CACHE_TTL );
            return [];
        }

        $found_tag_ids = array_keys( $found_tag_ids );
        $in_ph = implode( ',', array_fill( 0, count( $found_tag_ids ), '%d' ) );

        try {
            $post_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT tr.object_id
                 FROM {$wpdb->term_relationships} tr
                 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                 WHERE tt.taxonomy = 'post_tag' AND tt.term_id IN ($in_ph)
                 LIMIT " . (int) self::SYNONYM_MAX_POSTS,
                $found_tag_ids
            ) );
        } catch ( \Throwable $e ) { return []; }
        if ( ! is_array( $post_ids ) || empty( $post_ids ) ) {
            $this->cache->set( $cache_key, [ 'terms' => [] ], self::SYNONYM_CACHE_TTL );
            return [];
        }

        $post_ph = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
        try {
            $neighbours = $wpdb->get_results( $wpdb->prepare(
                "SELECT t.term_id, t.name, COUNT(*) AS cnt
                 FROM {$wpdb->term_relationships} tr
                 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                 INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
                 WHERE tt.taxonomy = 'post_tag'
                   AND tr.object_id IN ($post_ph)
                   AND t.term_id NOT IN ($in_ph)
                 GROUP BY t.term_id, t.name
                 HAVING cnt >= %d
                 ORDER BY cnt DESC
                 LIMIT 10",
                array_merge( $post_ids, $found_tag_ids, [ self::SYNONYM_MIN_COUNT ] )
            ) );
        } catch ( \Throwable $e ) { return []; }
        if ( ! is_array( $neighbours ) || empty( $neighbours ) ) {
            $this->cache->set( $cache_key, [ 'terms' => [] ], self::SYNONYM_CACHE_TTL );
            return [];
        }

        $exclude = [];
        foreach ( $exclude_groups as $g ) {
            foreach ( $g as $t ) { $exclude[ (string) $t ] = true; }
        }

        $terms = [];
        foreach ( $neighbours as $n ) {
            $stems = $this->tokenizer->tokenize( (string) $n->name, true );
            foreach ( $stems as $s ) {
                $s = (string) $s;
                if ( $s === '' || mb_strlen( $s ) < 3 ) { continue; }
                if ( isset( $exclude[ $s ] ) ) { continue; }
                $terms[ $s ] = true;
                if ( count( $terms ) >= self::SYNONYM_MAX_TERMS ) { break 2; }
            }
        }

        $out = array_keys( $terms );
        $this->cache->set( $cache_key, [ 'terms' => $out ], self::SYNONYM_CACHE_TTL );
        return $out;
    }
}