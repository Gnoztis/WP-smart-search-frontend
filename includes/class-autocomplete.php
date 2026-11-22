<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Автодополнение поискового запроса.
 *
 * Источник — таблица wp_wssf_terms (уникальные стемы + IOC целиком).
 * Работает быстро: PRIMARY KEY (term) + LIKE 'prefix%' = index scan.
 *
 * Порядок:
 *   1. Prefix-совпадения из terms table (по частоте);
 *   2. Если 0 — fuzzy через WSSF_Fuzzy_Index (BK-tree + n-gram);
 *   3. Если q похож на IOC — приоритезируются IOC-типы.
 *
 * Всё кешируется в WSSF_Cache (Redis/transient) на 5 минут.
 */
final class WSSF_Autocomplete {

    private const CACHE_TTL = 300;

    public function __construct(
        private WSSF_Cache $cache,
        private WSSF_Tokenizer $tokenizer
    ) {}

    public function is_enabled(): bool {
        return (bool) get_option( 'wssf_autocomplete_enabled', 1 );
    }

    public function get_min_len(): int {
        return max( 1, min( 10, (int) get_option( 'wssf_autocomplete_min_len', 2 ) ) );
    }

    public function get_max_suggestions(): int {
        return max( 1, min( 20, (int) get_option( 'wssf_autocomplete_max', 8 ) ) );
    }

    /**
     * @return array<int, array{term:string, type:string, count:int}>
     */
    public function suggest( string $q ): array {
        if ( ! $this->is_enabled() ) { return []; }
        $q = WSSF_Query_Parser::sanitize( $q );
        if ( mb_strlen( $q ) < $this->get_min_len() ) { return []; }

        $cache_key = 'wssf_ac_v1_' . md5( mb_strtolower( $q ) );
        $cached    = $this->cache->get( $cache_key );
        if ( is_array( $cached ) && isset( $cached['items'] ) ) {
            return (array) $cached['items'];
        }

        $max   = $this->get_max_suggestions();
        $items = [];

        // 1. Prefix matches
        $prefix = $this->find_prefix_matches( $q, $max * 3 );
        if ( ! empty( $prefix ) ) {
            $items = $prefix;
        }

        // 2. Fuzzy fallback
        if ( empty( $items ) ) {
            $items = $this->find_fuzzy_matches( $q, $max );
        }

        // 3. IOC-приоритезация
        if ( $this->is_ioc_hint( $q ) ) {
            $items = $this->prioritize_ioc( $items );
        }

        $items = array_slice( $items, 0, $max );

        $this->cache->set( $cache_key, [ 'items' => $items ], self::CACHE_TTL );
        return $items;
    }

    /* ============================================================
     *  Prefix
     * ============================================================ */

    private function find_prefix_matches( string $q, int $limit ): array {
        global $wpdb;
        $table = $this->get_terms_table();
        if ( $table === null ) { return []; }

        $q_lower = mb_strtolower( $q );
        $like    = $wpdb->esc_like( $q_lower ) . '%';

        try {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT term, post_count, term_type FROM `{$table}`
                 WHERE term LIKE %s
                 ORDER BY post_count DESC, LENGTH(term) ASC, term ASC
                 LIMIT " . (int) $limit,
                $like
            ) );
        } catch ( \Throwable $e ) { return []; }
        if ( ! is_array( $rows ) ) { return []; }

        $items = [];
        foreach ( $rows as $row ) {
            $items[] = [
                'term'  => (string) $row->term,
                'type'  => (string) $row->term_type,
                'count' => (int) $row->post_count,
            ];
        }
        return $items;
    }

    /* ============================================================
     *  Fuzzy
     * ============================================================ */

    private function find_fuzzy_matches( string $q, int $limit ): array {
        if ( ! class_exists( 'WSSF_Fuzzy_Index' ) ) { return []; }
        try {
            $fuzzy = new WSSF_Fuzzy_Index( $this->cache );
            $alts  = $fuzzy->find_alternatives( [ $q ], $limit );
        } catch ( \Throwable $e ) { return []; }
        if ( ! is_array( $alts ) ) { return []; }

        $items = [];
        foreach ( $alts as $alt ) {
            $term = (string) $alt;
            if ( $term === '' ) { continue; }
            $items[] = [
                'term'  => $term,
                'type'  => WSSF_Indexer::classify_term( $term ),
                'count' => 0,
            ];
        }
        return $items;
    }

    /* ============================================================
     *  IOC
     * ============================================================ */

    private function is_ioc_hint( string $q ): bool {
        if ( $this->tokenizer->is_technical_id( $q ) ) { return true; }
        if ( preg_match( '/^(CVE-|BDU:|T\d{4}|AS\d)/i', $q ) ) { return true; }
        if ( preg_match( '/^\d{1,3}\.\d/', $q ) ) { return true; }
        if ( preg_match( '/^[a-f0-9]{8,}$/i', $q ) ) { return true; }
        return false;
    }

    private function prioritize_ioc( array $items ): array {
        if ( empty( $items ) ) { return $items; }

        $ioc_types = [ 'cve', 'bdu', 'attck', 'asn', 'ipv4', 'ipv6', 'sha256', 'sha1', 'sha512', 'md5', 'url' ];
        $ioc  = [];
        $word = [];
        foreach ( $items as $item ) {
            if ( in_array( (string) $item['type'], $ioc_types, true ) ) {
                $ioc[] = $item;
            } else {
                $word[] = $item;
            }
        }
        return array_merge( $ioc, $word );
    }

    /* ============================================================
     *  Утилиты
     * ============================================================ */

    private function get_terms_table(): ?string {
        global $wpdb;
        $t = (string) $wpdb->prefix . 'wssf_terms';
        return preg_match( '/^[a-zA-Z0-9_]+$/', $t ) ? $t : null;
    }
}