<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class WSSF_Indexer {

    /** Сколько term'ов класть в один batch INSERT/UPDATE. */
    private const TERMS_BATCH_CHUNK = 500;

    private WSSF_Tokenizer $tokenizer;
    private string $table;
    private string $terms_table;

    public function __construct( WSSF_Tokenizer $tokenizer ) {
        global $wpdb;
        $table = (string) $wpdb->prefix . 'wssf_index';
        if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', $table ) ) { $table = 'wp_wssf_index'; }
        $this->table = $table;

        $terms = (string) $wpdb->prefix . 'wssf_terms';
        $this->terms_table = preg_match( '/^[a-zA-Z0-9_]+$/', $terms ) ? $terms : 'wp_wssf_terms';

        $this->tokenizer = $tokenizer;
    }

    public function create_table(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$this->table} (
            post_id BIGINT UNSIGNED NOT NULL,
            post_type VARCHAR(64) NOT NULL DEFAULT 'post',
            title_stem LONGTEXT NOT NULL,
            content_stem LONGTEXT NOT NULL,
            excerpt_stem LONGTEXT NOT NULL,
            tags_stem LONGTEXT NOT NULL,
            PRIMARY KEY (post_id),
            KEY post_type_idx (post_type),
            FULLTEXT KEY ft_search (title_stem, content_stem, excerpt_stem, tags_stem)
        ) ENGINE=InnoDB {$charset};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    public function create_terms_table(): void {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$this->terms_table} (
            term VARCHAR(200) NOT NULL,
            post_count INT UNSIGNED NOT NULL DEFAULT 0,
            term_type VARCHAR(20) NOT NULL DEFAULT 'word',
            PRIMARY KEY (term),
            KEY count_idx (post_count),
            KEY type_idx (term_type)
        ) ENGINE=InnoDB {$charset};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    public function ensure_indexes(): array {
        global $wpdb;
        $result = [];
        $indexes = [
            [
                'table'   => $wpdb->term_relationships,
                'name'    => 'wssf_tt_object',
                'sql'     => "ALTER TABLE {$wpdb->term_relationships} ADD INDEX wssf_tt_object (term_taxonomy_id, object_id)",
            ],
            [
                'table'   => $wpdb->term_taxonomy,
                'name'    => 'wssf_taxonomy_term',
                'sql'     => "ALTER TABLE {$wpdb->term_taxonomy} ADD INDEX wssf_taxonomy_term (taxonomy, term_id)",
            ],
        ];
        foreach ( $indexes as $idx ) {
            if ( $this->index_exists( $idx['table'], $idx['name'] ) ) {
                $result[ $idx['name'] ] = 'exists'; continue;
            }
            try { $wpdb->query( $idx['sql'] ); $result[ $idx['name'] ] = 'created'; }
            catch ( \Throwable $e ) { $result[ $idx['name'] ] = 'error: ' . sanitize_text_field( $e->getMessage() ); }
        }
        return $result;
    }

    public function has_optimizations(): bool {
        global $wpdb;
        $checks = [
            [ $wpdb->term_relationships, 'wssf_tt_object' ],
            [ $wpdb->term_taxonomy,      'wssf_taxonomy_term' ],
        ];
        foreach ( $checks as $c ) {
            if ( ! $this->index_exists( $c[0], $c[1] ) ) { return false; }
        }
        return true;
    }

    private function index_exists( string $table, string $index_name ): bool {
        global $wpdb;
        try {
            return (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND INDEX_NAME = %s",
                $table, $index_name
            ) ) > 0;
        } catch ( \Throwable $e ) { return false; }
    }

    public function count_all_posts(): int {
        global $wpdb;
        $post_types = WSSF_Search_Engine::get_post_types();
        if ( empty( $post_types ) ) { return 0; }
        $ph  = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
        $sql = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($ph)",
            $post_types
        );
        return (int) $wpdb->get_var( $sql );
    }

    public function count_missing_posts(): int {
        global $wpdb;
        $post_types = WSSF_Search_Engine::get_post_types();
        if ( empty( $post_types ) ) { return 0; }
        $ph  = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
        $sql = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             LEFT JOIN {$this->table} i ON i.post_id = p.ID
             WHERE p.post_status = 'publish' AND p.post_type IN ($ph) AND i.post_id IS NULL",
            $post_types
        );
        return (int) $wpdb->get_var( $sql );
    }

    public function get_all_post_ids( int $offset, int $limit ): array {
        global $wpdb;
        $post_types = WSSF_Search_Engine::get_post_types();
        if ( empty( $post_types ) ) { return []; }
        $ph     = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
        $params = array_merge( $post_types, [ $limit, $offset ] );
        $sql = $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($ph)
             ORDER BY ID ASC LIMIT %d OFFSET %d",
            $params
        );
        $ids = $wpdb->get_col( $sql );
        return is_array( $ids ) ? array_map( 'intval', $ids ) : [];
    }

    public function get_missing_post_ids( int $limit ): array {
        global $wpdb;
        $post_types = WSSF_Search_Engine::get_post_types();
        if ( empty( $post_types ) ) { return []; }
        $ph     = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
        $params = array_merge( $post_types, [ $limit ] );
        $sql = $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             LEFT JOIN {$this->table} i ON i.post_id = p.ID
             WHERE p.post_status = 'publish' AND p.post_type IN ($ph) AND i.post_id IS NULL
             ORDER BY p.ID ASC LIMIT %d",
            $params
        );
        $ids = $wpdb->get_col( $sql );
        return is_array( $ids ) ? array_map( 'intval', $ids ) : [];
    }

    public function index_post( int $post_id ): bool {
        global $wpdb;
        $post = get_post( $post_id );
        if ( ! $post instanceof \WP_Post ) { $this->delete_post( $post_id ); return false; }
        if ( $post->post_status !== 'publish' ) { $this->delete_post( $post_id ); return false; }
        if ( ! in_array( $post->post_type, WSSF_Search_Engine::get_post_types(), true ) ) {
            $this->delete_post( $post_id );
            return false;
        }

        $content = wp_strip_all_tags( strip_shortcodes( (string) $post->post_content ) );

        $title_tokens   = $this->tokenizer->tokenize( (string) $post->post_title );
        $content_tokens = $this->tokenizer->tokenize( $content );
        $excerpt_tokens = $this->tokenizer->tokenize( (string) $post->post_excerpt );
        $tags           = get_the_terms( $post_id, 'post_tag' );
        $tags_text      = is_array( $tags ) ? implode( ' ', wp_list_pluck( $tags, 'name' ) ) : '';
        $tags_tokens    = $this->tokenizer->tokenize( $tags_text );

        if ( class_exists( 'WSSF_Yara' ) ) {
            try {
                $yara = new WSSF_Yara();
                if ( $yara->is_enabled() ) {
                    $yara_tokens = $yara->build_index_tokens( $content );
                    if ( ! empty( $yara_tokens ) ) {
                        $content_tokens = array_merge( $content_tokens, $yara_tokens );
                    }
                }
            } catch ( \Throwable $e ) {}
        }

        $old_terms = $this->get_existing_terms( $post_id );

        $result = $wpdb->replace(
            $this->table,
            [
                'post_id'      => $post_id,
                'post_type'    => $post->post_type,
                'title_stem'   => implode( ' ', array_unique( $title_tokens ) ),
                'content_stem' => implode( ' ', array_unique( $content_tokens ) ),
                'excerpt_stem' => implode( ' ', array_unique( $excerpt_tokens ) ),
                'tags_stem'    => implode( ' ', array_unique( $tags_tokens ) ),
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s' ]
        );

        $new_terms = array_unique( array_merge( $title_tokens, $content_tokens, $excerpt_tokens, $tags_tokens ) );
        $this->update_terms( $old_terms, $new_terms );

        try {
            if ( class_exists( 'WSSF_IOC_Map' ) ) {
                $ioc_map = new WSSF_IOC_Map( WSSF_Plugin::instance()->cache );
                if ( $ioc_map->is_enabled() ) {
                    $ioc_values = $this->extract_ioc_values(
                        (string) $post->post_title . ' ' . $content . ' ' . $tags_text
                    );
                    $ioc_map->update_for_post( $post_id, $ioc_values );
                }
            }
        } catch ( \Throwable $e ) {}

        try {
            if ( class_exists( 'WSSF_MinHash' ) ) {
                $mh = new WSSF_MinHash( WSSF_Plugin::instance()->cache );
                if ( $mh->is_enabled() ) {
                    $combined_tokens = array_values( array_unique( array_merge( $content_tokens, $title_tokens ) ) );
                    $mh->update_for_post( $post_id, $combined_tokens );
                }
            }
        } catch ( \Throwable $e ) {}

        return $result !== false;
    }

    /**
     * Извлекает IOC-значения из текста через tokenize_with_iocs().
     * Не гоняет is_technical_id повторно на каждый токен.
     *
     * @return array<int, array{value:string, type:string}>
     */
    private function extract_ioc_values( string $text ): array {
        if ( $text === '' ) { return []; }

        $result = $this->tokenizer->tokenize_with_iocs( $text, false );
        $iocs   = $result['iocs'] ?? [];
        if ( empty( $iocs ) ) { return []; }

        $out = [];
        foreach ( $iocs as $ioc ) {
            $ioc = (string) $ioc;
            if ( $ioc === '' ) { continue; }
            $type = self::classify_term( $ioc );
            if ( $type === 'word' ) { continue; }
            $out[] = [ 'value' => $ioc, 'type' => $type ];
        }
        return $out;
    }

    public function delete_post( int $post_id ): void {
        global $wpdb;
        $old_terms = $this->get_existing_terms( $post_id );
        if ( ! empty( $old_terms ) ) { $this->update_terms( $old_terms, [] ); }
        $wpdb->delete( $this->table, [ 'post_id' => $post_id ], [ '%d' ] );

        try {
            if ( class_exists( 'WSSF_IOC_Map' ) ) {
                $ioc_map = new WSSF_IOC_Map( WSSF_Plugin::instance()->cache );
                $ioc_map->delete_for_post( $post_id );
            }
        } catch ( \Throwable $e ) {}

        try {
            if ( class_exists( 'WSSF_MinHash' ) ) {
                $mh = new WSSF_MinHash( WSSF_Plugin::instance()->cache );
                $mh->delete_for_post( $post_id );
            }
        } catch ( \Throwable $e ) {}
    }

    public function reindex_missing_cron( int $limit = 500 ): array {
        $limit = max( 1, min( 2000, $limit ) );
        try { $ids = $this->get_missing_post_ids( $limit ); }
        catch ( \Throwable $e ) { return [ 'processed' => 0, 'remaining' => 0 ]; }

        $processed = 0;
        foreach ( $ids as $id ) { if ( $this->index_post( (int) $id ) ) { $processed++; } }
        if ( $processed > 0 ) {
            update_option( 'wssf_index_updated_at', current_time( 'mysql' ), true );
        }
        try { $remaining = $this->count_missing_posts(); }
        catch ( \Throwable $e ) { $remaining = 0; }

        return [ 'processed' => $processed, 'remaining' => $remaining ];
    }

    private function get_existing_terms( int $post_id ): array {
        global $wpdb;
        try {
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT title_stem, content_stem, excerpt_stem, tags_stem FROM `{$this->table}` WHERE post_id = %d",
                $post_id
            ) );
        } catch ( \Throwable $e ) { return []; }
        if ( ! $row ) { return []; }

        $all = trim(
            (string) $row->title_stem . ' ' . (string) $row->tags_stem . ' ' .
            (string) $row->excerpt_stem . ' ' . mb_substr( (string) $row->content_stem, 0, 20000 )
        );
        if ( $all === '' ) { return []; }
        $tokens = preg_split( '/\s+/u', $all, -1, PREG_SPLIT_NO_EMPTY );
        return is_array( $tokens ) ? array_values( array_unique( array_map( 'strval', $tokens ) ) ) : [];
    }

    /**
     * Батчевый вариант update_terms: 2-8 запросов вместо 500-2000 на пост.
     */
    private function update_terms( array $old_terms, array $new_terms ): void {
        global $wpdb;
        $old = array_flip( $old_terms );
        $new = array_flip( $new_terms );
        $removed = array_keys( array_diff_key( $old, $new ) );
        $added   = array_keys( array_diff_key( $new, $old ) );

        // 1) Batch decrement для удалённых term'ов.
        if ( ! empty( $removed ) ) {
            foreach ( array_chunk( $removed, self::TERMS_BATCH_CHUNK ) as $chunk ) {
                $placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
                $params       = array_map( 'strval', $chunk );
                try {
                    $wpdb->query( $wpdb->prepare(
                        "UPDATE `{$this->terms_table}`
                         SET post_count = GREATEST(0, post_count - 1)
                         WHERE term IN ($placeholders)",
                        $params
                    ) );
                } catch ( \Throwable $e ) {}
            }
            // Чистка нулевых — один запрос.
            try { $wpdb->query( "DELETE FROM `{$this->terms_table}` WHERE post_count <= 0" ); }
            catch ( \Throwable $e ) {}
        }

        // 2) Batch upsert для добавленных term'ов.
        if ( ! empty( $added ) ) {
            foreach ( array_chunk( $added, self::TERMS_BATCH_CHUNK ) as $chunk ) {
                $values = [];
                $params = [];
                foreach ( $chunk as $term ) {
                    $term = (string) $term;
                    if ( $term === '' ) { continue; }
                    if ( mb_strlen( $term ) < 2 || mb_strlen( $term ) > 200 ) { continue; }
                    $values[] = '(%s, 1, %s)';
                    $params[] = $term;
                    $params[] = self::classify_term( $term );
                }
                if ( empty( $values ) ) { continue; }
                $sql = "INSERT INTO `{$this->terms_table}` (term, post_count, term_type)
                        VALUES " . implode( ',', $values ) . "
                        ON DUPLICATE KEY UPDATE post_count = post_count + 1";
                try {
                    $wpdb->query( $wpdb->prepare( $sql, $params ) );
                } catch ( \Throwable $e ) {}
            }
        }
    }

    public static function classify_term( string $term ): string {
        if ( class_exists( 'WSSF_Yara' ) && WSSF_Yara::is_yara_token( $term ) ) {
            return strpos( $term, 'yaratag:' ) === 0 ? 'yaratag' : 'yara';
        }
        $patterns = [
            '/^[a-f0-9]{128}$/i'   => 'sha512',
            '/^[a-f0-9]{64}$/i'    => 'sha256',
            '/^[a-f0-9]{40}$/i'    => 'sha1',
            '/^[a-f0-9]{32}$/i'    => 'md5',
            '/CVE-\d{4}-\d{4,}/i'  => 'cve',
            '/BDU:\d{4}-\d+/i'     => 'bdu',
            '/^T\d{4}(\.\d{3})?$/i' => 'attck',
            '/^AS\d+$/i'           => 'asn',
            '/(?<![0-9.])(?:\d{1,3}\.){3}\d{1,3}/' => 'ipv4',
            '/(?:[a-f0-9]{1,4}:){2,}.*[a-f0-9:]+/i' => 'ipv6',
            '/(?:https?|ftp):\/\//i' => 'url',
        ];
        foreach ( $patterns as $p => $t ) {
            if ( preg_match( $p, $term ) ) { return $t; }
        }
        return 'word';
    }

    public function truncate_terms(): void {
        global $wpdb;
        try { $wpdb->query( "TRUNCATE TABLE `{$this->terms_table}`" ); }
        catch ( \Throwable $e ) {}
    }

    public function reindex_all(): int {
        global $wpdb;
        $post_types = WSSF_Search_Engine::get_post_types();
        if ( empty( $post_types ) ) { return 0; }
        $ph = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
        $sql = $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($ph)",
            $post_types
        );
        $ids = $wpdb->get_col( $sql );

        $count = 0;
        foreach ( $ids as $id ) { $this->index_post( (int) $id ); $count++; }
        update_option( 'wssf_index_updated_at', current_time( 'mysql' ), true );
        return $count;
    }
}