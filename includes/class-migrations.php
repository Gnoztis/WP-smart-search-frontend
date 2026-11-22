<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class WSSF_Migrations {

    public static function current_version(): string {
        return defined( 'WSSF_VERSION' ) ? (string) WSSF_VERSION : '0.0.0';
    }

    public static function stored_version(): string {
        return (string) get_option( 'wssf_schema_version', '0.0.0' );
    }

    public static function run(): void {
        $stored  = self::stored_version();
        $current = self::current_version();

        if ( version_compare( $stored, $current, '<' ) ) {
            foreach ( self::all() as $version => $callback ) {
                $version = (string) $version;
                if ( version_compare( $stored, $version, '>=' ) ) { continue; }
                try {
                    call_user_func( $callback );
                    update_option( 'wssf_schema_version', $version, true );
                    $stored = $version;
                } catch ( \Throwable $e ) {
                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'error_log' ) ) {
                        error_log( '[WSSF] Migration ' . $version . ' failed: ' . sanitize_text_field( $e->getMessage() ) );
                    }
                    return;
                }
            }
        }

        if ( self::stored_version() !== $current ) {
            update_option( 'wssf_schema_version', $current, true );
        }
    }

    private static function all(): array {
        return [
            '4.0.0' => [ self::class, 'migrate_4_0_0' ],
            '4.1.0' => [ self::class, 'migrate_4_1_0' ],
            '4.1.1' => [ self::class, 'migrate_4_1_1' ],
            '4.2.0' => [ self::class, 'migrate_4_2_0' ],
            '4.2.1' => [ self::class, 'migrate_4_2_1' ],
            '4.3.0' => [ self::class, 'migrate_4_3_0' ],
            '4.4.0' => [ self::class, 'migrate_4_4_0' ],
            '4.5.0' => [ self::class, 'migrate_4_5_0' ],
            '4.6.0' => [ self::class, 'migrate_4_6_0' ],
            '5.0.0' => [ self::class, 'migrate_5_0_0' ],
            '5.1.0' => [ self::class, 'migrate_5_1_0' ],
            '5.2.0' => [ self::class, 'migrate_5_2_0' ],
            '5.3.0' => [ self::class, 'migrate_5_3_0' ],
        ];
    }

    public static function migrate_4_0_0(): void {
        $indexer = new WSSF_Indexer( new WSSF_Tokenizer( new WSSF_Morphology() ) );
        $indexer->create_table();
        $indexer->ensure_indexes();
        global $wpdb;
        $emb_table = (string) $wpdb->prefix . 'wssf_embeddings';
        if ( preg_match( '/^[a-zA-Z0-9_]+$/', $emb_table ) ) {
            $wpdb->query( "DROP TABLE IF EXISTS `{$emb_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- DDL statement without placeholders; the table name is validated with /^[a-zA-Z0-9_]+$/.
        }
        delete_option( 'wssf_openai_key' );
        delete_option( 'wssf_embedding_model' );
        delete_option( 'wssf_semantic_enabled' );
    }
    public static function migrate_4_1_0(): void {}
    public static function migrate_4_1_1(): void {
        try { $c = new WSSF_Cache(); $c->bump_generation(); $c->delete_transients_by_prefix( 'wssf_prf_' ); $c->delete_transients_by_prefix( 'wssf_syn_' ); }
        catch ( \Throwable $e ) {}
    }
    public static function migrate_4_2_0(): void { self::migrate_4_1_1(); }
    public static function migrate_4_2_1(): void { self::migrate_4_1_1(); }
    public static function migrate_4_3_0(): void {
        try { $c = new WSSF_Cache(); $c->bump_generation(); $c->delete( 'wssf_bk_tree_v1' ); $c->delete( 'wssf_ngram_index_v1' ); $c->delete( 'wssf_fuzzy_dict_v1' ); }
        catch ( \Throwable $e ) {}
    }
    public static function migrate_4_4_0(): void {}
    public static function migrate_4_5_0(): void {
        if ( get_option( 'wssf_synonyms', null ) === null ) { update_option( 'wssf_synonyms', '', true ); }
        if ( get_option( 'wssf_term_balance_power', null ) === null ) { update_option( 'wssf_term_balance_power', 0, true ); }
        if ( get_option( 'wssf_post_type_weights', null ) === null ) { update_option( 'wssf_post_type_weights', [], true ); }
    }
    public static function migrate_4_6_0(): void {
        global $wpdb;
        $table = (string) $wpdb->prefix . 'wssf_index';
        if ( preg_match( '/^[a-zA-Z0-9_]+$/', $table ) ) {
            $col = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'post_type'",
                DB_NAME, $table
            ) );
            if ( $col === 0 ) {
                try { $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN post_type VARCHAR(64) NOT NULL DEFAULT 'post'" ); } // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- DDL statement without placeholders; the table name is validated with /^[a-zA-Z0-9_]+$/.
                catch ( \Throwable $e ) {}
            }
            $idx = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = 'post_type_idx'",
                DB_NAME, $table
            ) );
            if ( $idx === 0 ) {
                try { $wpdb->query( "ALTER TABLE `{$table}` ADD INDEX post_type_idx (post_type)" ); } // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- DDL statement without placeholders; the table name is validated with /^[a-zA-Z0-9_]+$/.
                catch ( \Throwable $e ) {}
            }
            try { $wpdb->query( "UPDATE `{$table}` i INNER JOIN {$wpdb->posts} p ON p.ID = i.post_id SET i.post_type = p.post_type WHERE p.post_type != i.post_type" ); } // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- literal SQL without user input; the table name is validated with /^[a-zA-Z0-9_]+$/.
            catch ( \Throwable $e ) {}
        }
        $terms = (string) $wpdb->prefix . 'wssf_terms';
        if ( preg_match( '/^[a-zA-Z0-9_]+$/', $terms ) ) {
            $charset = $wpdb->get_charset_collate();
            $sql = "CREATE TABLE `{$terms}` (
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
        if ( get_option( 'wssf_autocomplete_enabled', null ) === null ) { update_option( 'wssf_autocomplete_enabled', 1, true ); }
        if ( get_option( 'wssf_autocomplete_min_len', null ) === null ) { update_option( 'wssf_autocomplete_min_len', 2, true ); }
        if ( get_option( 'wssf_autocomplete_max', null ) === null ) { update_option( 'wssf_autocomplete_max', 8, true ); }
    }
    public static function migrate_5_0_0(): void {
        global $wpdb;
        $table = (string) $wpdb->prefix . 'wssf_stopwords';
        if ( preg_match( '/^[a-zA-Z0-9_]+$/', $table ) ) {
            $charset = $wpdb->get_charset_collate();
            $sql = "CREATE TABLE `{$table}` (
                term VARCHAR(200) NOT NULL,
                lang VARCHAR(10) NOT NULL DEFAULT 'ru',
                PRIMARY KEY (term, lang),
                KEY lang_idx (lang)
            ) ENGINE=InnoDB {$charset};";
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta( $sql );
            $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- literal SQL; the table name is validated with /^[a-zA-Z0-9_]+$/.
            if ( $count === 0 && class_exists( 'WSSF_Stopwords' ) ) {
                WSSF_Stopwords::instance()->load_defaults();
            }
        }
        if ( get_option( 'wssf_cron_reindex_enabled', null ) === null ) { update_option( 'wssf_cron_reindex_enabled', 0, true ); }
        if ( get_option( 'wssf_cron_reindex_interval', null ) === null ) { update_option( 'wssf_cron_reindex_interval', 'daily', true ); }
        if ( get_option( 'wssf_cron_last_run', null ) === null ) { update_option( 'wssf_cron_last_run', '', true ); }
        if ( get_option( 'wssf_yara_enabled', null ) === null ) { update_option( 'wssf_yara_enabled', 1, true ); }
    }
    public static function migrate_5_1_0(): void {
        global $wpdb;
        $table = (string) $wpdb->prefix . 'wssf_ioc_map';
        if ( preg_match( '/^[a-zA-Z0-9_]+$/', $table ) ) {
            $charset = $wpdb->get_charset_collate();
            $sql = "CREATE TABLE `{$table}` (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                post_id BIGINT UNSIGNED NOT NULL,
                ioc_value VARCHAR(200) NOT NULL,
                ioc_type VARCHAR(20) NOT NULL DEFAULT 'word',
                PRIMARY KEY (id),
                UNIQUE KEY post_ioc (post_id, ioc_value),
                KEY ioc_value_idx (ioc_value),
                KEY ioc_type_idx (ioc_type),
                KEY post_id_idx (post_id)
            ) ENGINE=InnoDB {$charset};";
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta( $sql );
        }
        if ( get_option( 'wssf_ioc_map_enabled', null ) === null ) { update_option( 'wssf_ioc_map_enabled', 1, true ); }
        if ( get_option( 'wssf_related_enabled', null ) === null ) { update_option( 'wssf_related_enabled', 1, true ); }
        if ( get_option( 'wssf_related_max', null ) === null ) { update_option( 'wssf_related_max', 10, true ); }
        update_option( 'wssf_cron_reindex_enabled', 1, true );
        wp_clear_scheduled_hook( WSSF_Hooks::CRON_HOOK );
        try { $cache = new WSSF_Cache(); $cache->bump_generation(); } catch ( \Throwable $e ) {}
    }

    /**
     * 5.2.0 — MinHash + Security Headers.
     */
    public static function migrate_5_2_0(): void {
        global $wpdb;

        // ---- MinHash ----
        $table = (string) $wpdb->prefix . 'wssf_minhash';
        if ( preg_match( '/^[a-zA-Z0-9_]+$/', $table ) ) {
            $charset = $wpdb->get_charset_collate();
            $sql = "CREATE TABLE `{$table}` (
                post_id BIGINT UNSIGNED NOT NULL,
                signature BLOB NOT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY (post_id),
                KEY updated_idx (updated_at)
            ) ENGINE=InnoDB {$charset};";
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta( $sql );
        }

        if ( get_option( 'wssf_minhash_enabled', null ) === null ) {
            update_option( 'wssf_minhash_enabled', 1, true );
        }

        // ---- Security Headers ----
        if ( get_option( 'wssf_hdr_enabled', null ) === null ) {
            update_option( 'wssf_hdr_enabled', 1, true );
        }
        if ( get_option( 'wssf_hdr_xcto', null ) === null ) {
            update_option( 'wssf_hdr_xcto', 1, true );
        }
        if ( get_option( 'wssf_hdr_xfo', null ) === null ) {
            update_option( 'wssf_hdr_xfo', 1, true );
        }
        if ( get_option( 'wssf_hdr_referrer', null ) === null ) {
            update_option( 'wssf_hdr_referrer', 1, true );
        }
        if ( get_option( 'wssf_hdr_permissions', null ) === null ) {
            update_option( 'wssf_hdr_permissions', 1, true );
        }
        if ( get_option( 'wssf_csp_mode', null ) === null ) {
            update_option( 'wssf_csp_mode', 'off', true );
        }
        if ( get_option( 'wssf_csp_script_src', null ) === null ) { update_option( 'wssf_csp_script_src', '', true ); }
        if ( get_option( 'wssf_csp_style_src', null ) === null ) { update_option( 'wssf_csp_style_src', '', true ); }
        if ( get_option( 'wssf_csp_img_src', null ) === null ) { update_option( 'wssf_csp_img_src', '', true ); }
        if ( get_option( 'wssf_csp_connect_src', null ) === null ) { update_option( 'wssf_csp_connect_src', '', true ); }

        try { $cache = new WSSF_Cache(); $cache->bump_generation(); } catch ( \Throwable $e ) {}
    }

    /**
     * 5.3.0 — ускорение индексации.
     *
     * - Формат MinHash-signature изменён (128×md5 → 64×xxh64):
     *   старые подписи несовместимы, TRUNCATE.
     * - Новые опции: batch sizes для AJAX/cron, async save_post.
     */
    public static function migrate_5_3_0(): void {
        global $wpdb;

        // Формат MinHash signature изменился — очищаем подписи.
        $minhash = (string) $wpdb->prefix . 'wssf_minhash';
        if ( preg_match( '/^[a-zA-Z0-9_]+$/', $minhash ) ) {
            $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $minhash ) ) === $minhash;
            if ( $exists ) {
                try { $wpdb->query( "TRUNCATE TABLE `{$minhash}`" ); } // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- DDL statement without placeholders; the table name is validated with /^[a-zA-Z0-9_]+$/.
                catch ( \Throwable $e ) {}
            }
        }

        // Опции оптимизации индексации.
        if ( get_option( 'wssf_reindex_batch_size', null ) === null ) {
            update_option( 'wssf_reindex_batch_size', 200, true );
        }
        if ( get_option( 'wssf_cron_batch_size', null ) === null ) {
            update_option( 'wssf_cron_batch_size', 500, true );
        }
        if ( get_option( 'wssf_index_async_save', null ) === null ) {
            update_option( 'wssf_index_async_save', 0, true );
        }

        try {
            $cache = new WSSF_Cache();
            $cache->bump_generation();
            $cache->delete_transients_by_prefix( 'wssf_mh_sim_' );
        } catch ( \Throwable $e ) {}
    }
}