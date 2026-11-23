<?php
declare(strict_types=1);

namespace WSSF;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Search_Engine {

    public const TAGS_LIMIT         = 10;
    public const TAGS_SCAN          = 200;
    public const POSTS_PER_PAGE     = 10;

    public const DEFAULT_SEARCH_POST_TYPES = [ 'post', 'page' ];

    private const TAGS_POSTS_LIMIT   = 5000;
    private const RELEVANCE_MAX_PAGE = 3;
    private const COUNT_CACHE_TTL    = 60;
    private const FULLTEXT_CACHE_TTL = 300;
    private const HYBRID_CANDIDATES  = 200;
    private const IDF_CACHE_TTL      = HOUR_IN_SECONDS;

    private static ?array $cached_post_types = null;

    /** Index availability/count, memoized for the request (see index_state()). */
    private static ?array $index_state = null;

    private Rank_Fusion $fusion;

    public static function get_post_types(): array {
        if ( self::$cached_post_types !== null ) { return self::$cached_post_types; }

        $default = self::DEFAULT_SEARCH_POST_TYPES;
        $saved   = get_option( 'wssf_search_post_types', $default );
        if ( ! is_array( $saved ) ) { $saved = $default; }

        $available = get_post_types( [ 'public' => true ], 'names' );
        if ( ! is_array( $available ) ) { $available = []; }

        $out = [];
        foreach ( $saved as $pt ) {
            $pt = (string) $pt;
            if ( isset( $available[ $pt ] ) ) { $out[] = $pt; }
        }
        if ( empty( $out ) ) {
            foreach ( $default as $pt ) {
                if ( isset( $available[ $pt ] ) ) { $out[] = $pt; }
            }
        }
        if ( empty( $out ) ) { $out = array_values( $available ); }

        $out = array_values( array_unique( $out ) );
        if ( count( $out ) > 20 ) { $out = array_slice( $out, 0, 20 ); }

        self::$cached_post_types = $out;
        return $out;
    }

    public static function reset_post_types_cache(): void {
        self::$cached_post_types = null;
    }

    public function __construct(
        private Cache $cache,
        private Excerpt $excerpt,
        private Tokenizer $tokenizer,
        private ?Semantic_Search $semantic = null
    ) {
        $this->fusion = new Rank_Fusion();
    }

    private function get_index_table(): ?string {
        global $wpdb;
        $t = (string) $wpdb->prefix . 'wssf_index';
        return preg_match( '/^[a-zA-Z0-9_]+$/', $t ) ? $t : null;
    }

    private function is_index_available(): bool {
        $state = $this->index_state();
        return $state !== null && $state['exists'] && $state['count'] > 0;
    }

    /**
     * Whether the index table exists and how many rows it holds.
     *
     * Memoized for the request only: since 5.4 the cache has no transient
     * fallback, and a persistent copy of this state without Redis would be a
     * cache in wp_options — exactly what the backend was cleaned of. The probe
     * is two cheap queries (SHOW TABLES + COUNT on a table with a primary
     * key); it runs once per request instead of once per search call.
     *
     * @return array{exists:bool,count:int}|null
     */
    private function index_state(): ?array {
        if ( self::$index_state !== null ) {
            return self::$index_state;
        }

        global $wpdb;
        $table = $this->get_index_table();
        if ( $table === null ) { return null; }

        $exists = false;
        try { $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table; }
        catch ( \Throwable $e ) { $exists = false; }

        $count = 0;
        if ( $exists ) {
            try { $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ); } // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- literal SQL; the table name is validated with /^[a-zA-Z0-9_]+$/.
            catch ( \Throwable $e ) { $count = 0; }
        }

        self::$index_state = [ 'exists' => $exists, 'count' => $count ];

        return self::$index_state;
    }

    private function semantic_active(): bool {
        return $this->semantic !== null && $this->semantic->is_enabled();
    }

    private function is_pure_ioc_query( array $groups ): bool {
        $tokens = [];
        foreach ( $groups as $g ) { foreach ( $g as $t ) { $tokens[] = (string) $t; } }
        if ( empty( $tokens ) ) { return false; }
        foreach ( $tokens as $t ) { if ( ! $this->tokenizer->is_technical_id( $t ) ) { return false; } }
        return true;
    }

    /**
     * Co-occurrence «Похожие запросы».
     */
    public function get_related_iocs( array $groups, int $limit = 10 ): array {
        if ( ! (bool) get_option( 'wssf_related_enabled', 1 ) ) { return []; }
        if ( ! class_exists( 'WSSF_IOC_Map' ) ) { return []; }

        $ioc_values = [];
        foreach ( $groups as $g ) {
            foreach ( $g as $t ) {
                $t = (string) $t;
                if ( $t === '' ) { continue; }
                if ( ! $this->tokenizer->is_technical_id( $t ) ) { continue; }
                if ( class_exists( 'WSSF_Yara' ) && Yara::is_yara_token( $t ) ) { continue; }
                $ioc_values[ $t ] = true;
            }
        }
        $ioc_values = array_keys( $ioc_values );
        if ( empty( $ioc_values ) ) { return []; }

        try {
            $map = new IOC_Map( $this->cache );
            return $map->co_occurring( $ioc_values, $limit );
        } catch ( \Throwable $e ) { return []; }
    }

    /**
     * Похожие посты через MinHash/Jaccard ≥ 0.5 (near-duplicate).
     *
     * @param array $items hydrated items из search_fulltext/search_content
     * @return array<int, array{id:int, title:string, url:string, similarity:float}>
     */
    public function get_similar_posts( array $items ): array {
        if ( ! (bool) get_option( 'wssf_minhash_enabled', 1 ) ) { return []; }
        if ( empty( $items ) ) { return []; }
        if ( ! class_exists( 'WSSF_MinHash' ) ) { return []; }

        $first_id = (int) ( $items[0]['id'] ?? 0 );
        if ( $first_id <= 0 ) { return []; }

        try {
            $mh = new MinHash( $this->cache );
            $similar = $mh->similar_to( $first_id );
        } catch ( \Throwable $e ) { return []; }

        if ( empty( $similar ) ) { return []; }

        global $wpdb;
        $ids = array_map( static fn( $r ) => (int) $r['post_id'], $similar );
        if ( empty( $ids ) ) { return []; }
        $ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        try {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT ID, post_title FROM {$wpdb->posts} WHERE ID IN ($ph) AND post_status = 'publish'",
                $ids
            ) );
        } catch ( \Throwable $e ) { return []; }
        if ( ! is_array( $rows ) ) { return []; }

        $by_id = [];
        foreach ( $rows as $r ) { $by_id[ (int) $r->ID ] = $r; }

        $out = [];
        foreach ( $similar as $s ) {
            $id = (int) $s['post_id'];
            if ( ! isset( $by_id[ $id ] ) ) { continue; }
            $out[] = [
                'id'         => $id,
                'title'      => (string) $by_id[ $id ]->post_title,
                'url'        => get_permalink( $id ),
                'similarity' => (float) $s['similarity'],
            ];
        }
        return $out;
    }

    /* ============================================================
     *  Метки
     * ============================================================ */

    public function search_tags( array $groups, int $cat_id = 0, array $tag_ids = [], string $post_type_filter = '', array $exclude_tag_ids = [], string $raw_query = '' ): array {
        global $wpdb;

        $tag_ids = $this->normalize_ids( $tag_ids );
        $exclude_tag_ids = $this->normalize_ids( $exclude_tag_ids );
        if ( ! empty( $tag_ids ) && ! empty( $exclude_tag_ids ) ) {
            $exclude_tag_ids = array_values( array_diff( $exclude_tag_ids, $tag_ids ) );
        }

        $has_any = ! empty( $groups ) || ! empty( $tag_ids ) || ! empty( $exclude_tag_ids ) || $cat_id > 0 || $post_type_filter !== '';
        if ( ! $has_any ) { return []; }

        if ( $post_type_filter !== '' && in_array( $post_type_filter, self::get_post_types(), true ) ) {
            $post_types = [ $post_type_filter ];
        } else {
            $post_types = self::get_post_types();
        }
        if ( empty( $post_types ) ) { return []; }

        $raw_norm = Query_Parser::sanitize( $raw_query );

        $cache_key = 'wssf_tags_v18_' . $this->cache->generation() . '_'
            . md5( wp_json_encode( $groups ) . '|cat=' . $cat_id . '|in=' . implode( ',', $tag_ids )
                . '|ex=' . implode( ',', $exclude_tag_ids ) . '|pt=' . implode( ',', $post_types ) . '|q=' . $raw_norm );
        $cached = $this->cache->get( $cache_key );
        if ( is_array( $cached ) ) { return $cached; }

        $exact_entry = null;
        $exact_id    = 0;
        if ( $raw_norm !== '' ) {
            $exact_row = null;
            try {
                $exact_row = $wpdb->get_row( $wpdb->prepare(
                    "SELECT t.term_id, t.name, t.slug FROM {$wpdb->terms} AS t
                     INNER JOIN {$wpdb->term_taxonomy} AS tt ON tt.term_id = t.term_id AND tt.taxonomy = 'post_tag'
                     WHERE (t.name = %s OR t.slug = %s) LIMIT 1",
                    $raw_norm, sanitize_title( $raw_norm )
                ) );
            } catch ( \Throwable $e ) { $exact_row = null; }
            if ( $exact_row ) {
                $exact_id    = (int) $exact_row->term_id;
                $exact_count = $this->count_tag_in_scope( $exact_id, $cat_id, $post_types, $tag_ids, $exclude_tag_ids );
                $exact_entry = [
                    'id' => $exact_id, 'name' => (string) $exact_row->name, 'slug' => (string) $exact_row->slug,
                    'count' => $exact_count, 'url' => get_tag_link( $exact_id ), 'exact' => true,
                ];
            }
        }

        $post_ids = $this->get_filtered_post_ids( $groups, $cat_id, $post_types, $tag_ids, $exclude_tag_ids );
        if ( $post_ids !== null && empty( $post_ids ) ) {
            $out = $exact_entry !== null ? [ $exact_entry ] : [];
            $this->cache->set( $cache_key, $out );
            return $out;
        }

        $rows = [];
        if ( $post_ids !== null ) {
            $in_ph = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
            $sql = "SELECT t.term_id, t.name, t.slug, COUNT(tr.object_id) AS count
                FROM {$wpdb->terms} AS t
                INNER JOIN {$wpdb->term_taxonomy} AS tt ON tt.term_id = t.term_id AND tt.taxonomy = 'post_tag'
                INNER JOIN {$wpdb->term_relationships} AS tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
                WHERE tt.count > 0 AND tr.object_id IN ($in_ph)
                GROUP BY t.term_id, t.name, t.slug
                ORDER BY count DESC, t.name ASC LIMIT " . (int) self::TAGS_SCAN;
            try { $rows = $wpdb->get_results( $wpdb->prepare( $sql, $post_ids ) ); }
            catch ( \Throwable $e ) { $rows = []; }
        } else {
            [ $where_sql, $params ] = $this->build_post_where( $groups, $cat_id, $post_types, $tag_ids, $exclude_tag_ids );
            $sql = "SELECT t.term_id, t.name, t.slug, COUNT(DISTINCT p.ID) AS count
                FROM {$wpdb->terms} AS t
                INNER JOIN {$wpdb->term_taxonomy} AS tt ON tt.term_id = t.term_id AND tt.taxonomy = 'post_tag'
                INNER JOIN {$wpdb->term_relationships} AS tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
                INNER JOIN {$wpdb->posts} AS p ON p.ID = tr.object_id
                WHERE tt.count > 0 AND {$where_sql}
                GROUP BY t.term_id, t.name, t.slug
                ORDER BY count DESC, t.name ASC LIMIT " . (int) self::TAGS_SCAN;
            try { $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); }
            catch ( \Throwable $e ) { $rows = []; }
        }
        if ( ! is_array( $rows ) ) { $rows = []; }

        $out = []; $seen_ids = [];
        if ( $exact_entry !== null ) { $out[] = $exact_entry; $seen_ids[ $exact_id ] = true; }
        foreach ( $rows as $row ) {
            $tid = (int) $row->term_id;
            if ( isset( $seen_ids[ $tid ] ) ) { continue; }
            $out[] = [
                'id' => $tid, 'name' => (string) $row->name, 'slug' => (string) $row->slug,
                'count' => (int) $row->count, 'url' => get_tag_link( $tid ), 'exact' => false,
            ];
            $seen_ids[ $tid ] = true;
            if ( count( $out ) >= self::TAGS_LIMIT ) { break; }
        }
        $this->cache->set( $cache_key, $out );
        return $out;
    }

    private function count_tag_in_scope( int $term_id, int $cat_id, array $post_types, array $tag_ids, array $exclude_tag_ids ): int {
        global $wpdb;
        $where = [ "p.post_status = 'publish'", "p.post_password = ''" ];
        $params = [];
        if ( ! empty( $post_types ) ) {
            $ph = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
            $where[] = "p.post_type IN ($ph)";
            foreach ( $post_types as $pt ) { $params[] = $pt; }
        } else { return 0; }
        if ( $cat_id > 0 ) {
            $where[] = "p.ID IN ( SELECT tr_cat.object_id FROM {$wpdb->term_relationships} AS tr_cat INNER JOIN {$wpdb->term_taxonomy} AS tt_cat ON tt_cat.term_taxonomy_id = tr_cat.term_taxonomy_id WHERE tt_cat.taxonomy = 'category' AND tt_cat.term_id = %d )";
            $params[] = $cat_id;
        }
        if ( ! empty( $tag_ids ) ) {
            $in_ph = implode( ',', array_fill( 0, count( $tag_ids ), '%d' ) );
            $where[] = "p.ID IN ( SELECT tr_in.object_id FROM {$wpdb->term_relationships} AS tr_in INNER JOIN {$wpdb->term_taxonomy} AS tt_in ON tt_in.term_taxonomy_id = tr_in.term_taxonomy_id WHERE tt_in.taxonomy = 'post_tag' AND tt_in.term_id IN ($in_ph) GROUP BY tr_in.object_id HAVING COUNT(DISTINCT tt_in.term_id) = " . count( $tag_ids ) . " )";
            foreach ( $tag_ids as $tid ) { $params[] = $tid; }
        }
        if ( ! empty( $exclude_tag_ids ) ) {
            $ex_ph = implode( ',', array_fill( 0, count( $exclude_tag_ids ), '%d' ) );
            $where[] = "p.ID NOT IN ( SELECT tr_ex.object_id FROM {$wpdb->term_relationships} AS tr_ex INNER JOIN {$wpdb->term_taxonomy} AS tt_ex ON tt_ex.term_taxonomy_id = tr_ex.term_taxonomy_id WHERE tt_ex.taxonomy = 'post_tag' AND tt_ex.term_id IN ($ex_ph) )";
            foreach ( $exclude_tag_ids as $tid ) { $params[] = $tid; }
        }
        $where_sql = implode( ' AND ', $where );
        $sql = "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} AS p
            INNER JOIN {$wpdb->term_relationships} AS tr ON tr.object_id = p.ID
            INNER JOIN {$wpdb->term_taxonomy} AS tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
            WHERE tt.taxonomy = 'post_tag' AND tt.term_id = %d AND {$where_sql}";
        array_unshift( $params, $term_id );
        try { return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) ); }
        catch ( \Throwable $e ) { return 0; }
    }

    private function get_filtered_post_ids( array $groups, int $cat_id, array $post_types, array $tag_ids, array $exclude_tag_ids ): ?array {
        $use_fulltext = false; $boolean = '';
        if ( ! empty( $groups ) && $this->is_index_available() ) {
            $boolean = $this->tokenizer->groups_to_boolean_string( $groups );
            if ( $boolean !== '' ) { $use_fulltext = true; }
        }
        if ( $use_fulltext ) {
            return $this->get_filtered_post_ids_fulltext( $boolean, $cat_id, $post_types, $tag_ids, $exclude_tag_ids );
        }
        return $this->get_filtered_post_ids_like( $groups, $cat_id, $post_types, $tag_ids, $exclude_tag_ids );
    }

    private function get_filtered_post_ids_fulltext( string $boolean, int $cat_id, array $post_types, array $tag_ids, array $exclude_tag_ids ): ?array {
        global $wpdb;
        $table = $this->get_index_table();
        if ( $table === null ) { return null; }
        if ( empty( $post_types ) ) { return []; }

        [ $where_sql, $where_params ] = $this->build_fulltext_where( '', $cat_id, $tag_ids, $exclude_tag_ids );
        $pt_ph = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
        $where_sql = "i.post_type IN ($pt_ph) AND " . $where_sql;
        $where_params = array_merge( $post_types, $where_params );

        $limit = self::TAGS_POSTS_LIMIT + 1;
        $sql = "SELECT p.ID FROM {$wpdb->posts} AS p
                INNER JOIN `{$table}` AS i ON i.post_id = p.ID
                WHERE {$where_sql}
                  AND MATCH(i.title_stem, i.content_stem, i.excerpt_stem, i.tags_stem) AGAINST (%s IN BOOLEAN MODE)
                LIMIT " . (int) $limit;
        $params = array_merge( $where_params, [ $boolean ] );
        try { $rows = $wpdb->get_col( $wpdb->prepare( $sql, $params ) ); }
        catch ( \Throwable $e ) { return null; }
        if ( ! is_array( $rows ) ) { return null; }
        if ( count( $rows ) > self::TAGS_POSTS_LIMIT ) { return null; }
        return array_map( 'intval', $rows );
    }

    private function get_filtered_post_ids_like( array $groups, int $cat_id, array $post_types, array $tag_ids, array $exclude_tag_ids ): ?array {
        global $wpdb;
        [ $where_sql, $params ] = $this->build_post_where( $groups, $cat_id, $post_types, $tag_ids, $exclude_tag_ids );
        $limit = self::TAGS_POSTS_LIMIT + 1;
        $sql = "SELECT p.ID FROM {$wpdb->posts} AS p WHERE {$where_sql} LIMIT " . (int) $limit;
        try { $rows = $wpdb->get_col( $wpdb->prepare( $sql, $params ) ); }
        catch ( \Throwable $e ) { return null; }
        if ( ! is_array( $rows ) ) { return null; }
        if ( count( $rows ) > self::TAGS_POSTS_LIMIT ) { return null; }
        return array_map( 'intval', $rows );
    }

    /* ============================================================
     *  LIKE / FULLTEXT
     * ============================================================ */

    public function search_content( array $groups, int $page, int $cat_id, string $post_type_filter = '', array $tag_ids = [], array $exclude_tag_ids = [] ): array {
        global $wpdb;

        $tag_ids = $this->normalize_ids( $tag_ids );
        $exclude_tag_ids = $this->normalize_ids( $exclude_tag_ids );
        if ( ! empty( $tag_ids ) && ! empty( $exclude_tag_ids ) ) {
            $exclude_tag_ids = array_values( array_diff( $exclude_tag_ids, $tag_ids ) );
        }
        if ( $post_type_filter !== '' && in_array( $post_type_filter, self::get_post_types(), true ) ) {
            $post_types = [ $post_type_filter ];
        } else {
            $post_types = self::get_post_types();
        }
        if ( empty( $post_types ) ) {
            return [ 'items' => [], 'total' => 0, 'page' => 1, 'total_pages' => 1, 'related_iocs' => [], 'similar_posts' => [] ];
        }

        $cache_key = 'wssf_content_v19_' . $this->cache->generation() . '_'
            . md5( wp_json_encode( $groups ) . '|cat=' . $cat_id . '|page=' . $page . '|types=' . implode( ',', $post_types ) . '|in=' . implode( ',', $tag_ids ) . '|ex=' . implode( ',', $exclude_tag_ids ) );
        $cached = $this->cache->get( $cache_key );
        if ( is_array( $cached ) && isset( $cached['items'] ) ) { return $cached; }

        [ $where_sql, $params ] = $this->build_post_where( $groups, $cat_id, $post_types, $tag_ids, $exclude_tag_ids );
        $total = $this->get_cached_total( 'like', $groups, $cat_id, $post_types, $tag_ids, $exclude_tag_ids, $where_sql, $params );

        $total_pages = max( 1, (int) ceil( $total / self::POSTS_PER_PAGE ) );
        if ( $page > $total_pages ) { $page = $total_pages; }
        $offset = ( $page - 1 ) * self::POSTS_PER_PAGE;

        $sql = "SELECT p.ID, p.post_title, p.post_content, p.post_excerpt, p.post_date, p.post_type
            FROM {$wpdb->posts} AS p WHERE {$where_sql}
            ORDER BY p.post_date DESC LIMIT %d OFFSET %d";
        $params_with_limit = array_merge( $params, [ self::POSTS_PER_PAGE, $offset ] );

        try { $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params_with_limit ) ); }
        catch ( \Throwable $e ) {
            return [ 'items' => [], 'total' => 0, 'page' => 1, 'total_pages' => 1, 'related_iocs' => [], 'similar_posts' => [] ];
        }
        if ( ! is_array( $rows ) ) {
            $result = [ 'items' => [], 'total' => 0, 'page' => 1, 'total_pages' => 1, 'related_iocs' => [], 'similar_posts' => [] ];
            $this->cache->set( $cache_key, $result );
            return $result;
        }

        $items = $this->hydrate_items( $rows, $groups, $cat_id );

        $related_iocs = [];
        if ( $total > 0 && (int) get_option( 'wssf_related_max', 10 ) > 0 ) {
            try { $related_iocs = $this->get_related_iocs( $groups, (int) get_option( 'wssf_related_max', 10 ) ); }
            catch ( \Throwable $e ) { $related_iocs = []; }
        }

        $similar_posts = $this->get_similar_posts( $items );

        $result = [
            'items'         => $items,
            'total'         => $total,
            'page'          => $page,
            'total_pages'   => $total_pages,
            'related_iocs'  => $related_iocs,
            'similar_posts' => $similar_posts,
        ];
        $this->cache->set( $cache_key, $result );
        return $result;
    }

    public function search_fulltext( array $groups, int $page, int $cat_id, string $post_type_filter = '', array $tag_ids = [], array $exclude_tag_ids = [] ): array {
        if ( $this->is_pure_ioc_query( $groups ) ) {
            return $this->search_content( $groups, $page, $cat_id, $post_type_filter, $tag_ids, $exclude_tag_ids );
        }

        global $wpdb;
        $table = $this->get_index_table();
        if ( $table === null || ! $this->is_index_available() ) {
            return $this->search_content( $groups, $page, $cat_id, $post_type_filter, $tag_ids, $exclude_tag_ids );
        }

        $boolean = $this->tokenizer->groups_to_boolean_string( $groups );
        if ( $boolean === '' ) {
            return $this->search_content( $groups, $page, $cat_id, $post_type_filter, $tag_ids, $exclude_tag_ids );
        }

        $raw_query = $this->groups_to_raw( $groups );

        $ft_cache_key = 'wssf_ft_v2_' . $this->cache->generation() . '_' . md5(
            $boolean . '|' . $page . '|' . $cat_id . '|' . $post_type_filter
            . '|' . implode( ',', $this->normalize_ids( $tag_ids ) )
            . '|' . implode( ',', $this->normalize_ids( $exclude_tag_ids ) )
        );
        $ft_cached = $this->cache->get( $ft_cache_key );
        if ( is_array( $ft_cached ) && isset( $ft_cached['items'] ) ) { return $ft_cached; }

        [ $where_sql, $where_params ] = $this->build_fulltext_where( $post_type_filter, $cat_id, $tag_ids, $exclude_tag_ids );

        $count_sql = "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} AS p INNER JOIN `{$table}` AS i ON i.post_id = p.ID
                     WHERE {$where_sql} AND MATCH(i.title_stem, i.content_stem, i.excerpt_stem, i.tags_stem) AGAINST (%s IN BOOLEAN MODE)";
        $count_params = array_merge( $where_params, [ $boolean ] );
        $base_total = $this->get_cached_total_raw( 'fulltext', $boolean, $cat_id, $post_type_filter, $tag_ids, $exclude_tag_ids, $count_sql, $count_params );

        if ( $base_total === null ) {
            return $this->search_content( $groups, $page, $cat_id, $post_type_filter, $tag_ids, $exclude_tag_ids );
        }

        $bm25_rows = $this->fetch_bm25_rows( $table, $boolean, $where_sql, $where_params, self::HYBRID_CANDIDATES );
        $scored    = $this->apply_boosts( $bm25_rows, $raw_query, $groups );
        $scored    = $this->filter_by_phrases( $scored, $raw_query );

        if ( empty( $scored ) ) {
            $or_rows = $this->fetch_or_rows( $table, $groups, $where_sql, $where_params, self::HYBRID_CANDIDATES );
            if ( ! empty( $or_rows ) ) {
                $scored = $this->apply_boosts( $or_rows, $raw_query, $groups );
                $scored = $this->filter_by_phrases( $scored, $raw_query );
            }
        }

        $prf_boolean = '';
        if ( $this->semantic_active() && $this->semantic !== null
             && $this->semantic->is_prf_enabled()
             && ! empty( $scored ) && count( $scored ) <= 100
        ) {
            $top_ids = array_slice( array_keys( $scored ), 0, 20 );
            $prf     = $this->semantic->prf_expand( $groups, $top_ids, 'rm3' );
            if ( ! empty( $prf['terms'] ) || ! empty( $prf['bigrams'] ) ) {
                $prf_parts = [ $boolean ];
                foreach ( $prf['terms'] as $term ) {
                    $clean = preg_replace( '/[+\-><\(\)~*\"@\\\\%]+/u', ' ', (string) $term );
                    $clean = trim( (string) $clean );
                    if ( $clean !== '' ) { $prf_parts[] = $clean . '*'; }
                }
                foreach ( $prf['bigrams'] as $pair ) {
                    $a = trim( (string) ( $pair[0] ?? '' ) );
                    $b = trim( (string) ( $pair[1] ?? '' ) );
                    if ( $a !== '' && $b !== '' ) { $prf_parts[] = '+"' . $a . ' ' . $b . '"'; }
                }
                $prf_boolean = implode( ' ', $prf_parts );
            }
        }

        $fuzzy_ids = [];
        if ( $base_total === 0 && $this->semantic_active() && $this->semantic !== null
             && $this->semantic->is_typo_enabled()
        ) {
            $query_tokens = $this->flatten_groups( $groups );
            $alternatives = $this->semantic->fuzzy_alternatives( $query_tokens );
            if ( ! empty( $alternatives ) ) {
                $fuzzy_ids = $this->search_ids_by_terms( $table, $alternatives, $where_sql, $where_params );
            }
        }

        $rankings = [];
        $bm25_ids = array_keys( $scored );
        if ( ! empty( $bm25_ids ) ) { $rankings[] = $bm25_ids; }

        if ( $prf_boolean !== '' && $prf_boolean !== $boolean ) {
            $prf_rows = $this->fetch_bm25_rows( $table, $prf_boolean, $where_sql, $where_params, self::HYBRID_CANDIDATES );
            $prf_ids  = [];
            foreach ( $prf_rows as $r ) { $prf_ids[] = (int) $r->ID; }
            if ( ! empty( $prf_ids ) ) { $rankings[] = $prf_ids; }
        }
        if ( ! empty( $fuzzy_ids ) ) { $rankings[] = $fuzzy_ids; }

        if ( count( $rankings ) > 1 ) {
            $fused_ids   = $this->fusion->fuse_ids( $rankings );
            $total       = count( $fused_ids );
            $total_pages = max( 1, (int) ceil( $total / self::POSTS_PER_PAGE ) );
            if ( $page > $total_pages ) { $page = $total_pages; }
            $offset   = ( $page - 1 ) * self::POSTS_PER_PAGE;
            $page_ids = array_slice( $fused_ids, $offset, self::POSTS_PER_PAGE );
            $rows     = $this->fetch_posts_by_ids( $page_ids );
        } else {
            $total       = count( $bm25_ids );
            $total_pages = max( 1, (int) ceil( $total / self::POSTS_PER_PAGE ) );
            if ( $page > $total_pages ) { $page = $total_pages; }
            $offset   = ( $page - 1 ) * self::POSTS_PER_PAGE;
            $page_ids = array_slice( $bm25_ids, $offset, self::POSTS_PER_PAGE );
            $rows     = $this->fetch_posts_by_ids( $page_ids );
        }

        if ( empty( $rows ) && $total > 0 && $page === 1 ) {
            return $this->search_content( $groups, $page, $cat_id, $post_type_filter, $tag_ids, $exclude_tag_ids );
        }

        $items = $this->hydrate_items( $rows, $groups, $cat_id );

        $related_iocs = [];
        if ( $total > 0 && (int) get_option( 'wssf_related_max', 10 ) > 0 ) {
            try { $related_iocs = $this->get_related_iocs( $groups, (int) get_option( 'wssf_related_max', 10 ) ); }
            catch ( \Throwable $e ) { $related_iocs = []; }
        }

        $similar_posts = $this->get_similar_posts( $items );

        $result = [
            'items'         => $items,
            'total'         => $total,
            'page'          => $page,
            'total_pages'   => $total_pages,
            'related_iocs'  => $related_iocs,
            'similar_posts' => $similar_posts,
        ];

        $this->cache->set( $ft_cache_key, $result, self::FULLTEXT_CACHE_TTL );
        return $result;
    }

    /* ============================================================
     *  Бусты
     * ============================================================ */

    private function apply_boosts( array $rows, string $raw_query, array $groups ): array {
        if ( empty( $rows ) ) { return []; }

        $idf_boost_enabled   = (bool) apply_filters( 'wssf_idf_boost', true );
        $exact_match_enabled = (bool) apply_filters( 'wssf_exact_match_boost', true );
        $balance_power       = (float) apply_filters( 'wssf_term_balance_power',
            class_exists( 'WSSF_Admin_Settings' ) ? Admin_Settings::get_term_balance_power() : 0.0 );
        $post_type_weights   = $this->get_post_type_weights();

        $terms = [];
        foreach ( $groups as $g ) { foreach ( $g as $t ) { $terms[] = (string) $t; } }
        $terms = array_values( array_unique( array_filter( $terms ) ) );

        $idf_map = [];
        if ( $idf_boost_enabled && ! empty( $terms ) ) {
            $idf_map = $this->get_terms_idf( $terms );
        }
        $term_counts = [];
        if ( $balance_power > 0 && count( $terms ) > 1 ) {
            $term_counts = $this->get_term_balance_counts( $terms, $rows );
        }

        $clean_query       = Query_Parser::sanitize( $raw_query );
        $clean_query_lower = mb_strtolower( $clean_query );

        $scored = [];
        foreach ( $rows as $row ) {
            $id   = (int) $row->ID;
            $base = (float) ( $row->relevance ?? 0 );
            if ( $base <= 0 ) { $base = 1.0; }

            if ( $idf_boost_enabled && ! empty( $idf_map ) ) {
                $avg_idf = array_sum( $idf_map ) / count( $idf_map );
                $base *= ( 1.0 + $avg_idf );
            }
            if ( $exact_match_enabled && $clean_query_lower !== '' ) {
                $title   = mb_strtolower( (string) ( $row->post_title ?? '' ) );
                $content = mb_strtolower( (string) ( $row->post_content ?? '' ) );
                if ( $title !== '' && mb_strpos( $title, $clean_query_lower ) !== false ) { $base *= 5.0; }
                elseif ( $content !== '' && mb_strpos( $content, $clean_query_lower ) !== false ) { $base *= 2.0; }
            }
            $pt = (string) ( $row->post_type ?? 'post' );
            if ( isset( $post_type_weights[ $pt ] ) && $post_type_weights[ $pt ] > 0 ) { $base *= (float) $post_type_weights[ $pt ]; }
            if ( $balance_power > 0 && isset( $term_counts[ $id ] ) ) {
                $balance = $this->term_balance_ratio( $term_counts[ $id ] );
                if ( $balance > 0 && $balance < 1.0 ) { $base *= pow( $balance, $balance_power ); }
            }
            $scored[ $id ] = $base;
        }
        arsort( $scored );
        return $scored;
    }

    private function get_post_type_weights(): array {
        $available = self::get_post_types();
        $default = [];
        foreach ( $available as $pt ) { $default[ $pt ] = 1.0; }
        $saved = get_option( 'wssf_post_type_weights', [] );
        if ( ! is_array( $saved ) ) { return $default; }
        foreach ( $saved as $pt => $w ) {
            $pt = (string) $pt;
            if ( ! isset( $default[ $pt ] ) ) { continue; }
            $default[ $pt ] = max( 0.0, min( 10.0, (float) $w ) );
        }
        return $default;
    }

    private function get_terms_idf( array $terms ): array {
        global $wpdb;
        $table = $this->get_index_table();
        if ( $table === null ) { return []; }

        $state = $this->index_state();
        $total = $state !== null ? (int) $state['count'] : 0;
        if ( $total <= 0 ) { $total = 100; }

        $out = [];
        foreach ( $terms as $term ) {
            $term = (string) $term;
            if ( $term === '' ) { continue; }

            $cache_key = 'wssf_idf_' . md5( $term . '|' . $total );
            $cached    = $this->cache->get( $cache_key );
            if ( is_array( $cached ) && isset( $cached['idf'] ) ) { $out[ $term ] = (float) $cached['idf']; continue; }

            $boolean = $this->tokenizer->groups_to_boolean_string( [ [ $term ] ] );
            if ( $boolean === '' ) { $out[ $term ] = 1.0; continue; }
            try {
                $df = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(DISTINCT post_id) FROM `{$table}` WHERE MATCH(title_stem, content_stem, excerpt_stem, tags_stem) AGAINST (%s IN BOOLEAN MODE)",
                    $boolean
                ) );
            } catch ( \Throwable $e ) { $df = 1; }
            $idf = log( ( $total + 1 ) / ( $df + 1 ) );
            if ( $idf < 1.0 ) { $idf = 1.0; }
            $out[ $term ] = $idf;
            $this->cache->set( $cache_key, [ 'idf' => $idf ], self::IDF_CACHE_TTL );
        }
        return $out;
    }

    private function get_term_balance_counts( array $terms, array $rows ): array {
        $ids = [];
        foreach ( $rows as $r ) { $ids[] = (int) $r->ID; }
        if ( empty( $ids ) ) { return []; }

        global $wpdb;
        $table = $this->get_index_table();
        if ( $table === null ) { return []; }

        $counts = [];
        foreach ( $ids as $id ) { $counts[ $id ] = []; }
        $ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

        foreach ( $terms as $term ) {
            $term = (string) $term;
            if ( $term === '' ) { continue; }
            $boolean = $this->tokenizer->groups_to_boolean_string( [ [ $term ] ] );
            if ( $boolean === '' ) { continue; }
            $args = array_merge( [ $boolean ], $ids );
            try {
                $result = $wpdb->get_results( $wpdb->prepare(
                    "SELECT post_id, MATCH(title_stem, content_stem, excerpt_stem, tags_stem) AGAINST (%s IN BOOLEAN MODE) AS score
                     FROM `{$table}` WHERE post_id IN ($ph)",
                    $args
                ) );
            } catch ( \Throwable $e ) { continue; }
            if ( ! is_array( $result ) ) { continue; }
            foreach ( $result as $r ) {
                $score = (float) $r->score;
                if ( $score <= 0 ) { continue; }
                $counts[ (int) $r->post_id ][ $term ] = $score;
            }
        }
        return $counts;
    }

    private function term_balance_ratio( array $scores ): float {
        if ( count( $scores ) < 2 ) { return 1.0; }
        foreach ( $scores as $s ) { if ( ! is_numeric( $s ) || (float) $s <= 0 ) { return 1.0; } }
        $max = (float) max( $scores );
        $log_sum = 0.0; $lin_sum = 0.0; $n = count( $scores );
        foreach ( $scores as $s ) { $s = (float) $s; $log_sum += log( $s / $max ); $lin_sum += $s / $max; }
        $log_avg = $log_sum / $n; $lin_avg = $lin_sum / $n;
        if ( $lin_avg <= 0 ) { return 1.0; }
        $ratio = exp( $log_avg - log( $lin_avg ) );
        return max( 0.0, min( 1.0, $ratio ) );
    }

    private function extract_phrases( string $raw_query ): array {
        $phrases = [];
        if ( preg_match_all( '/"([^"]{2,})"/u', $raw_query, $m ) ) {
            foreach ( $m[1] as $p ) { $p = trim( (string) $p ); if ( $p !== '' ) { $phrases[] = $p; } }
        }
        return $phrases;
    }

    private function filter_by_phrases( array $scored, string $raw_query ): array {
        $phrases = $this->extract_phrases( $raw_query );
        if ( empty( $phrases ) ) { return $scored; }
        if ( empty( $scored ) ) { return []; }

        global $wpdb;
        $ids = array_keys( $scored );
        $ph  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $like_parts  = []; $like_params = [];
        foreach ( $phrases as $p ) {
            $like = '%' . $wpdb->esc_like( $p ) . '%';
            $like_parts[]  = "(post_content LIKE %s OR post_title LIKE %s)";
            $like_params[] = $like; $like_params[] = $like;
        }
        $like_sql = implode( ' AND ', $like_parts );
        $args = array_merge( $ids, $like_params );
        try {
            $matching = $wpdb->get_col( $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE ID IN ($ph) AND ($like_sql)", $args
            ) );
        } catch ( \Throwable $e ) { return $scored; }
        if ( ! is_array( $matching ) ) { return $scored; }
        $matching = array_map( 'intval', $matching );
        $out = [];
        foreach ( $scored as $id => $score ) { if ( in_array( $id, $matching, true ) ) { $out[ $id ] = $score; } }
        return $out;
    }

    private function fetch_or_rows( string $table, array $groups, string $where_sql, array $where_params, int $limit ): array {
        $terms = [];
        foreach ( $groups as $g ) { foreach ( $g as $t ) { $terms[] = (string) $t; } }
        $terms = array_values( array_unique( array_filter( $terms ) ) );
        if ( empty( $terms ) ) { return []; }
        $query_text = implode( ' ', $terms );

        global $wpdb;
        $sql = "SELECT p.ID, p.post_title, p.post_content, p.post_type,
                       MATCH(i.title_stem, i.content_stem, i.excerpt_stem, i.tags_stem) AGAINST (%s) AS relevance
                FROM {$wpdb->posts} AS p
                INNER JOIN `{$table}` AS i ON i.post_id = p.ID
                WHERE {$where_sql} AND MATCH(i.title_stem, i.content_stem, i.excerpt_stem, i.tags_stem) AGAINST (%s)
                ORDER BY relevance DESC, p.post_date DESC LIMIT " . (int) $limit;
        $params = array_merge( [ $query_text ], $where_params, [ $query_text ] );
        try { $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); }
        catch ( \Throwable $e ) { return []; }
        return is_array( $rows ) ? $rows : [];
    }

    private function fetch_bm25_rows( string $table, string $boolean, string $where_sql, array $where_params, int $limit ): array {
        global $wpdb;
        $sql = "SELECT p.ID, p.post_title, p.post_content, p.post_type,
                       MATCH(i.title_stem, i.content_stem, i.excerpt_stem, i.tags_stem) AGAINST (%s IN BOOLEAN MODE) AS relevance
                FROM {$wpdb->posts} AS p
                INNER JOIN `{$table}` AS i ON i.post_id = p.ID
                WHERE {$where_sql} AND MATCH(i.title_stem, i.content_stem, i.excerpt_stem, i.tags_stem) AGAINST (%s IN BOOLEAN MODE)
                ORDER BY relevance DESC, p.post_date DESC LIMIT " . (int) $limit;
        $params = array_merge( [ $boolean ], $where_params, [ $boolean ] );
        try { $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); }
        catch ( \Throwable $e ) { return []; }
        return is_array( $rows ) ? $rows : [];
    }

    private function search_ids_by_terms( string $table, array $terms, string $where_sql, array $where_params ): array {
        if ( empty( $terms ) ) { return []; }
        $parts = [];
        foreach ( $terms as $t ) {
            $clean = preg_replace( '/[+\-><\(\)~*\"@\\\\%]+/u', ' ', (string) $t );
            $clean = trim( (string) $clean );
            if ( $clean !== '' ) { $parts[] = '+' . $clean . '*'; }
        }
        if ( empty( $parts ) ) { return []; }
        $boolean = implode( ' ', $parts );

        global $wpdb;
        $sql = "SELECT p.ID FROM {$wpdb->posts} AS p
                INNER JOIN `{$table}` AS i ON i.post_id = p.ID
                WHERE {$where_sql}
                  AND MATCH(i.title_stem, i.content_stem, i.excerpt_stem, i.tags_stem) AGAINST (%s IN BOOLEAN MODE)
                ORDER BY MATCH(i.title_stem, i.content_stem, i.excerpt_stem, i.tags_stem) AGAINST (%s IN BOOLEAN MODE) DESC
                LIMIT 100";
        $params = array_merge( $where_params, [ $boolean, $boolean ] );
        try { $ids = $wpdb->get_col( $wpdb->prepare( $sql, $params ) ); }
        catch ( \Throwable $e ) { return []; }
        return is_array( $ids ) ? array_map( 'intval', $ids ) : [];
    }

    private function fetch_posts_by_ids( array $ids ): array {
        if ( empty( $ids ) ) { return []; }
        global $wpdb;
        $ids = array_values( array_filter( array_map( 'intval', $ids ), static fn( $v ) => $v > 0 ) );
        if ( empty( $ids ) ) { return []; }
        $ph  = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $sql = "SELECT ID, post_title, post_content, post_excerpt, post_date, post_type FROM {$wpdb->posts} WHERE ID IN ($ph)";
        try { $rows = $wpdb->get_results( $wpdb->prepare( $sql, $ids ) ); }
        catch ( \Throwable $e ) { return []; }
        if ( ! is_array( $rows ) ) { return []; }
        $by_id = [];
        foreach ( $rows as $r ) { $by_id[ (int) $r->ID ] = $r; }
        $ordered = [];
        foreach ( $ids as $id ) { if ( isset( $by_id[ $id ] ) ) { $ordered[] = $by_id[ $id ]; } }
        return $ordered;
    }

    private function groups_to_raw( array $groups ): string {
        $parts = [];
        foreach ( $groups as $g ) { foreach ( $g as $t ) { $parts[] = (string) $t; } }
        return trim( implode( ' ', $parts ) );
    }

    private function build_fulltext_where( string $post_type_filter, int $cat_id, array $tag_ids, array $exclude_tag_ids ): array {
        global $wpdb;
        $where        = [ "p.post_status = 'publish'", "p.post_password = ''" ];
        $where_params = [];

        if ( $post_type_filter !== '' && in_array( $post_type_filter, self::get_post_types(), true ) ) {
            $where[] = "i.post_type = %s";
            $where_params[] = $post_type_filter;
        } else {
            $allowed = self::get_post_types();
            if ( ! empty( $allowed ) ) {
                $pt_ph = implode( ',', array_fill( 0, count( $allowed ), '%s' ) );
                $where[] = "i.post_type IN ($pt_ph)";
                foreach ( $allowed as $pt ) { $where_params[] = $pt; }
            }
        }

        if ( $cat_id > 0 ) {
            $where[] = "p.ID IN ( SELECT tr_cat.object_id FROM {$wpdb->term_relationships} AS tr_cat INNER JOIN {$wpdb->term_taxonomy} AS tt_cat ON tt_cat.term_taxonomy_id = tr_cat.term_taxonomy_id WHERE tt_cat.taxonomy = 'category' AND tt_cat.term_id = %d )";
            $where_params[] = $cat_id;
        }

        $tag_ids = $this->normalize_ids( $tag_ids );
        if ( ! empty( $tag_ids ) ) {
            $in_ph = implode( ',', array_fill( 0, count( $tag_ids ), '%d' ) );
            $where[] = "p.ID IN ( SELECT tr_in.object_id FROM {$wpdb->term_relationships} AS tr_in INNER JOIN {$wpdb->term_taxonomy} AS tt_in ON tt_in.term_taxonomy_id = tr_in.term_taxonomy_id WHERE tt_in.taxonomy = 'post_tag' AND tt_in.term_id IN ($in_ph) GROUP BY tr_in.object_id HAVING COUNT(DISTINCT tt_in.term_id) = " . count( $tag_ids ) . " )";
            foreach ( $tag_ids as $tid ) { $where_params[] = $tid; }
        }

        $exclude_tag_ids = $this->normalize_ids( $exclude_tag_ids );
        if ( ! empty( $exclude_tag_ids ) ) {
            $ex_ph = implode( ',', array_fill( 0, count( $exclude_tag_ids ), '%d' ) );
            $where[] = "p.ID NOT IN ( SELECT tr_ex.object_id FROM {$wpdb->term_relationships} AS tr_ex INNER JOIN {$wpdb->term_taxonomy} AS tt_ex ON tt_ex.term_taxonomy_id = tr_ex.term_taxonomy_id WHERE tt_ex.taxonomy = 'post_tag' AND tt_ex.term_id IN ($ex_ph) )";
            foreach ( $exclude_tag_ids as $tid ) { $where_params[] = $tid; }
        }

        return [ implode( ' AND ', $where ), $where_params ];
    }

    private function build_post_where( array $groups, int $cat_id, array $post_types, array $tag_ids, array $exclude_tag_ids ): array {
        global $wpdb;
        $where  = [];
        $params = [];
        $where[] = "p.post_status = 'publish'";
        $where[] = "p.post_password = ''";

        if ( empty( $post_types ) ) {
            $where[] = '1=0';
        } else {
            $ph = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
            $where[] = "p.post_type IN ($ph)";
            foreach ( $post_types as $pt ) { $params[] = $pt; }
        }
        if ( $cat_id > 0 ) {
            $where[] = "p.ID IN ( SELECT tr_cat.object_id FROM {$wpdb->term_relationships} AS tr_cat INNER JOIN {$wpdb->term_taxonomy} AS tt_cat ON tt_cat.term_taxonomy_id = tr_cat.term_taxonomy_id WHERE tt_cat.taxonomy = 'category' AND tt_cat.term_id = %d )";
            $params[] = $cat_id;
        }
        if ( ! empty( $tag_ids ) ) {
            $in_ph = implode( ',', array_fill( 0, count( $tag_ids ), '%d' ) );
            $where[] = "p.ID IN ( SELECT tr_in.object_id FROM {$wpdb->term_relationships} AS tr_in INNER JOIN {$wpdb->term_taxonomy} AS tt_in ON tt_in.term_taxonomy_id = tr_in.term_taxonomy_id WHERE tt_in.taxonomy = 'post_tag' AND tt_in.term_id IN ($in_ph) GROUP BY tr_in.object_id HAVING COUNT(DISTINCT tt_in.term_id) = " . count( $tag_ids ) . " )";
            foreach ( $tag_ids as $tid ) { $params[] = $tid; }
        }
        if ( ! empty( $exclude_tag_ids ) ) {
            $ex_ph = implode( ',', array_fill( 0, count( $exclude_tag_ids ), '%d' ) );
            $where[] = "p.ID NOT IN ( SELECT tr_ex.object_id FROM {$wpdb->term_relationships} AS tr_ex INNER JOIN {$wpdb->term_taxonomy} AS tt_ex ON tt_ex.term_taxonomy_id = tr_ex.term_taxonomy_id WHERE tt_ex.taxonomy = 'post_tag' AND tt_ex.term_id IN ($ex_ph) )";
            foreach ( $exclude_tag_ids as $tid ) { $params[] = $tid; }
        }
        if ( ! empty( $groups ) ) {
            $group_sqls = [];
            foreach ( $groups as $group ) {
                if ( empty( $group ) ) { continue; }
                $cond_sqls = [];
                foreach ( $group as $condition ) {
                    $like = '%' . $wpdb->esc_like( $condition ) . '%';
                    $cond_sqls[] = '(p.post_title LIKE %s OR p.post_content LIKE %s OR p.post_excerpt LIKE %s)';
                    $params[] = $like; $params[] = $like; $params[] = $like;
                }
                if ( ! empty( $cond_sqls ) ) {
                    $group_sqls[] = '( ' . implode( ' AND ', $cond_sqls ) . ' )';
                }
            }
            if ( ! empty( $group_sqls ) ) {
                $where[] = '( ' . implode( ' OR ', $group_sqls ) . ' )';
            }
        }
        return [ implode( ' AND ', $where ), $params ];
    }

    private function get_cached_total( string $kind, array $groups, int $cat_id, array $post_types, array $tag_ids, array $exclude_tag_ids, string $where_sql, array $params ): int {
        global $wpdb;
        $key = 'wssf_count_v19_' . $kind . '_' . $this->cache->generation() . '_'
            . md5( wp_json_encode( $groups ) . '|cat=' . $cat_id . '|types=' . implode( ',', $post_types ) . '|in=' . implode( ',', $tag_ids ) . '|ex=' . implode( ',', $exclude_tag_ids ) );
        $cached = $this->cache->get( $key );
        if ( is_array( $cached ) && isset( $cached['total'] ) ) { return (int) $cached['total']; }

        $count_sql = "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} AS p WHERE {$where_sql}";
        try { $total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ); }
        catch ( \Throwable $e ) { return 0; }
        $this->cache->set( $key, [ 'total' => $total ], self::COUNT_CACHE_TTL );
        return $total;
    }

    private function get_cached_total_raw( string $kind, string $boolean, int $cat_id, string $post_type_filter, array $tag_ids, array $exclude_tag_ids, string $count_sql, array $count_params ): ?int {
        global $wpdb;
        $key = 'wssf_count_v19_' . $kind . '_' . $this->cache->generation() . '_'
            . md5( $boolean . '|cat=' . $cat_id . '|pt=' . $post_type_filter . '|in=' . implode( ',', $tag_ids ) . '|ex=' . implode( ',', $exclude_tag_ids ) );
        $cached = $this->cache->get( $key );
        if ( is_array( $cached ) && isset( $cached['total'] ) ) { return (int) $cached['total']; }
        try { $total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $count_params ) ); }
        catch ( \Throwable $e ) { return null; }
        $this->cache->set( $key, [ 'total' => $total ], self::COUNT_CACHE_TTL );
        return $total;
    }

    private function hydrate_items( array $rows, array $groups, int $cat_id ): array {
        $items = [];
        foreach ( $rows as $row ) {
            $content = wp_strip_all_tags( strip_shortcodes( (string) $row->post_content ) );
            $excerpt = $this->excerpt->make( $content, $this->flatten_groups( $groups ) );
            $type    = (string) $row->post_type;
            $items[] = [
                'id'         => (int) $row->ID,
                'type'       => $type,
                'type_label' => $this->get_type_label( $type ),
                'title'      => $row->post_title !== '' ? $row->post_title : __( '(без названия)', 'wp-smart-search-frontend' ),
                'date'       => mysql2date( get_option( 'date_format' ), $row->post_date ),
                'url'        => get_permalink( (int) $row->ID ),
                'excerpt'    => $excerpt,
                'tags'       => [],
                'categories' => [],
            ];
        }
        if ( ! empty( $items ) ) {
            $post_ids = array_column( $items, 'id' );
            $tags_map = $this->fetch_terms_for_posts( $post_ids, 'post_tag' );
            $cats_map = ( $cat_id === 0 ) ? $this->fetch_terms_for_posts( $post_ids, 'category' ) : [];
            foreach ( $items as $i => $item ) {
                if ( isset( $tags_map[ $item['id'] ] ) ) { $items[ $i ]['tags'] = $tags_map[ $item['id'] ]; }
                if ( isset( $cats_map[ $item['id'] ] ) ) { $items[ $i ]['categories'] = $cats_map[ $item['id'] ]; }
            }
        }
        return $items;
    }

    private function flatten_groups( array $groups ): array {
        $words = [];
        foreach ( $groups as $group ) { foreach ( $group as $cond ) { $words[] = $cond; } }
        return $words;
    }

    private function normalize_ids( array $ids ): array {
        $ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn( $v ) => $v > 0 ) ) );
        if ( count( $ids ) > 50 ) { $ids = array_slice( $ids, 0, 50 ); }
        return $ids;
    }

    private function get_type_label( string $type ): string {
        $obj = function_exists( 'get_post_type_object' ) ? get_post_type_object( $type ) : null;
        if ( $obj && isset( $obj->labels->singular_name ) && (string) $obj->labels->singular_name !== '' ) {
            return (string) $obj->labels->singular_name;
        }
        switch ( $type ) {
            case 'page': return __( 'Страница', 'wp-smart-search-frontend' );
            case 'post':
            default:     return __( 'Запись', 'wp-smart-search-frontend' );
        }
    }

    private function fetch_terms_for_posts( array $post_ids, string $taxonomy ): array {
        global $wpdb;
        $allowed = [ 'post_tag', 'category' ];
        if ( ! in_array( $taxonomy, $allowed, true ) ) { return []; }
        $post_ids = array_values( array_filter( array_map( 'intval', $post_ids ), static fn( $v ) => $v > 0 ) );
        if ( empty( $post_ids ) ) { return []; }

        $placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
        $sql = "SELECT tr.object_id, t.term_id, t.name, t.slug
            FROM {$wpdb->term_relationships} AS tr
            INNER JOIN {$wpdb->term_taxonomy} AS tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
            INNER JOIN {$wpdb->terms} AS t ON t.term_id = tt.term_id
            WHERE tt.taxonomy = %s AND tr.object_id IN ($placeholders) ORDER BY t.name ASC";
        $params = array_merge( [ $taxonomy ], $post_ids );
        try { $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); }
        catch ( \Throwable $e ) { return []; }
        if ( ! is_array( $rows ) ) { return []; }

        $map = [];
        foreach ( $rows as $row ) {
            $term_id = (int) $row->term_id;
            $url = ( $taxonomy === 'category' ) ? get_category_link( $term_id ) : get_tag_link( $term_id );
            $map[ (int) $row->object_id ][] = [ 'id' => $term_id, 'name' => (string) $row->name, 'url' => $url ];
        }
        return $map;
    }
}