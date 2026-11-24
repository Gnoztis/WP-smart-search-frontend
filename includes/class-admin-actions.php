<?php
declare(strict_types=1);

namespace WSSF;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Admin_Actions {

    private const PAGE_SLUG = 'wssf-settings';

    /* ============================================================
     *  general
     * ============================================================ */

    public static function handle_save_settings(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            Security::log_event( 'cap_denied', 'admin_action' );
            wp_die( 'Forbidden', 403 );
        }
        check_admin_referer( 'wssf_save_settings' );

        $strict_mode = isset( $_POST['wssf_strict_mode'] )
            ? sanitize_key( wp_unslash( $_POST['wssf_strict_mode'] ) ) : 'auto';
        if ( ! in_array( $strict_mode, [ 'auto', 'on', 'off' ], true ) ) { $strict_mode = 'auto'; }
        update_option( 'wssf_strict_mode', $strict_mode, true );

        // CleanTalk is optional: auto | on | off (see WSSF_Admin_Settings).
        $cleantalk_mode = isset( $_POST['wssf_cleantalk_mode'] )
            ? sanitize_key( wp_unslash( $_POST['wssf_cleantalk_mode'] ) ) : 'auto';
        if ( ! in_array( $cleantalk_mode, [ 'auto', 'on', 'off' ], true ) ) { $cleantalk_mode = 'auto'; }
        update_option( 'wssf_cleantalk_mode', $cleantalk_mode, true );

        update_option( 'wssf_ttl_user',  max( 0, (int) ( $_POST['wssf_ttl_user']  ?? 60 ) ), true );
        update_option( 'wssf_ttl_guest', max( 0, (int) ( $_POST['wssf_ttl_guest'] ?? 60 ) ), true );

        update_option( 'wssf_did_you_mean',    isset( $_POST['wssf_did_you_mean'] )    ? 1 : 0, true );
        update_option( 'wssf_suggestions',     isset( $_POST['wssf_suggestions'] )     ? 1 : 0, true );
        update_option( 'wssf_history_enabled', isset( $_POST['wssf_history_enabled'] ) ? 1 : 0, true );

        update_option( 'wssf_autocomplete_enabled', isset( $_POST['wssf_autocomplete_enabled'] ) ? 1 : 0, true );
        update_option( 'wssf_autocomplete_min_len', max( 1, min( 10, (int) ( $_POST['wssf_autocomplete_min_len'] ?? 2 ) ) ), true );
        update_option( 'wssf_autocomplete_max',     max( 1, min( 20, (int) ( $_POST['wssf_autocomplete_max']     ?? 8 ) ) ), true );

        // wssf_stopwords_enabled — в отдельной форме блока «Стоп-слова».
        update_option( 'wssf_yara_enabled', isset( $_POST['wssf_yara_enabled'] ) ? 1 : 0, true );

        update_option( 'wssf_cron_reindex_enabled', isset( $_POST['wssf_cron_reindex_enabled'] ) ? 1 : 0, true );
        $interval = isset( $_POST['wssf_cron_reindex_interval'] )
            ? sanitize_key( wp_unslash( $_POST['wssf_cron_reindex_interval'] ) ) : 'daily';
        if ( ! in_array( $interval, [ 'wssf_hourly', 'wssf_twicedaily', 'daily' ], true ) ) {
            $interval = 'daily';
        }
        update_option( 'wssf_cron_reindex_interval', $interval, true );

        // IOC map + related
        update_option( 'wssf_ioc_map_enabled', isset( $_POST['wssf_ioc_map_enabled'] ) ? 1 : 0, true );
        update_option( 'wssf_related_enabled', isset( $_POST['wssf_related_enabled'] ) ? 1 : 0, true );
        update_option( 'wssf_related_max', max( 1, min( 30, (int) ( $_POST['wssf_related_max'] ?? 10 ) ) ), true );

        // 5.2.0: MinHash
        update_option( 'wssf_minhash_enabled', isset( $_POST['wssf_minhash_enabled'] ) ? 1 : 0, true );

        // 5.2.0: Security Headers
        update_option( 'wssf_hdr_enabled',     isset( $_POST['wssf_hdr_enabled'] )     ? 1 : 0, true );
        update_option( 'wssf_hdr_xcto',        isset( $_POST['wssf_hdr_xcto'] )        ? 1 : 0, true );
        update_option( 'wssf_hdr_xfo',         isset( $_POST['wssf_hdr_xfo'] )         ? 1 : 0, true );
        update_option( 'wssf_hdr_referrer',    isset( $_POST['wssf_hdr_referrer'] )    ? 1 : 0, true );
        update_option( 'wssf_hdr_permissions', isset( $_POST['wssf_hdr_permissions'] ) ? 1 : 0, true );

        $csp_mode = isset( $_POST['wssf_csp_mode'] ) ? sanitize_key( wp_unslash( $_POST['wssf_csp_mode'] ) ) : 'off';
        if ( ! in_array( $csp_mode, [ 'off', 'report_only', 'enforce' ], true ) ) { $csp_mode = 'off'; }
        update_option( 'wssf_csp_mode', $csp_mode, true );

        foreach ( [ 'wssf_csp_script_src', 'wssf_csp_style_src', 'wssf_csp_img_src', 'wssf_csp_connect_src' ] as $opt ) {
            $v = isset( $_POST[ $opt ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $opt ] ) ) : '';
            update_option( $opt, $v, true );
        }

        // 5.3.0: оптимизация индексации
        update_option(
            'wssf_reindex_batch_size',
            max( 10, min( 500, (int) ( $_POST['wssf_reindex_batch_size'] ?? 200 ) ) ),
            true
        );
        update_option(
            'wssf_cron_batch_size',
            max( 50, min( 2000, (int) ( $_POST['wssf_cron_batch_size'] ?? 500 ) ) ),
            true
        );
        update_option(
            'wssf_index_async_save',
            isset( $_POST['wssf_index_async_save'] ) ? 1 : 0,
            true
        );

        $saved_types = [];
        if ( isset( $_POST['wssf_search_post_types'] ) && is_array( $_POST['wssf_search_post_types'] ) ) {
            $raw_types = wp_unslash( $_POST['wssf_search_post_types'] );
            $available = get_post_types( [ 'public' => true ], 'names' );
            if ( is_array( $available ) ) {
                foreach ( $raw_types as $pt ) {
                    $pt = sanitize_key( (string) $pt );
                    if ( $pt !== '' && isset( $available[ $pt ] ) ) { $saved_types[] = $pt; }
                }
            }
        }
        $saved_types = array_values( array_unique( $saved_types ) );
        if ( empty( $saved_types ) ) {
            $saved_types = Search_Engine::DEFAULT_SEARCH_POST_TYPES;
        }
        update_option( 'wssf_search_post_types', $saved_types, true );

        Search_Engine::reset_post_types_cache();
        $cache = Plugin::instance()->cache;
        $cache->bump_generation();
        $cache->delete( 'wssf_index_count' );
        $cache->delete_transients_by_prefix( 'wssf_ac_' );
        $cache->delete_transients_by_prefix( 'wssf_coc_' );
        $cache->delete_transients_by_prefix( 'wssf_ioc_rel_' );
        $cache->delete_transients_by_prefix( 'wssf_mh_sim_' );

        wp_clear_scheduled_hook( Hooks::CRON_HOOK );

        wp_safe_redirect( add_query_arg(
            [ 'page' => self::PAGE_SLUG, 'tab' => 'general', 'saved' => 1 ],
            admin_url( 'options-general.php' )
        ) );
        exit;
    }

    /* ============================================================
     *  semantic
     * ============================================================ */

    public static function handle_save_semantic(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            Security::log_event( 'cap_denied', 'admin_action' );
            wp_die( 'Forbidden', 403 );
        }
        check_admin_referer( 'wssf_save_semantic' );

        update_option( 'wssf_semantic_prf',      isset( $_POST['wssf_semantic_prf'] )      ? 1 : 0, true );
        update_option( 'wssf_semantic_synonyms', isset( $_POST['wssf_semantic_synonyms'] ) ? 1 : 0, true );
        update_option( 'wssf_semantic_typo',     isset( $_POST['wssf_semantic_typo'] )     ? 1 : 0, true );

        delete_option( 'wssf_semantic_enabled' );
        delete_option( 'wssf_embedding_model' );
        delete_option( 'wssf_openai_key' );

        $cache = Plugin::instance()->cache;
        $cache->bump_generation();
        $cache->delete_transients_by_prefix( 'wssf_prf_' );
        $cache->delete_transients_by_prefix( 'wssf_syn_' );
        $cache->delete_transients_by_prefix( 'wssf_fuzzy_' );

        wp_safe_redirect( add_query_arg(
            [ 'page' => self::PAGE_SLUG, 'tab' => 'semantic', 'semantic_saved' => 1 ],
            admin_url( 'options-general.php' )
        ) );
        exit;
    }

    /* ============================================================
     *  ranking
     * ============================================================ */

    public static function handle_save_ranking(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            Security::log_event( 'cap_denied', 'admin_action' );
            wp_die( 'Forbidden', 403 );
        }
        check_admin_referer( 'wssf_save_ranking' );

        $raw = isset( $_POST['wssf_synonyms'] )
            ? (string) wp_unslash( $_POST['wssf_synonyms'] ) : '';
        $raw = str_replace( [ "\r\n", "\r" ], "\n", $raw );
        $raw = str_replace( "\n", ';', $raw );
        $raw = trim( $raw, " \t\n\r\0\x0B;" );

        $pairs = [];
        foreach ( explode( ';', $raw ) as $pair ) {
            $pair = trim( $pair );
            if ( $pair === '' ) { continue; }
            if ( strpos( $pair, '=' ) === false ) { continue; }
            [ $k, $v ] = explode( '=', $pair, 2 );
            $k = mb_strtolower( trim( (string) $k ) );
            $v = trim( (string) $v );
            if ( $k === '' || $v === '' ) { continue; }
            if ( mb_strlen( $k ) > 100 || mb_strlen( $v ) > 100 ) { continue; }
            $pairs[] = $k . '=' . $v;
            if ( count( $pairs ) >= 200 ) { break; }
        }
        update_option( 'wssf_synonyms', implode( ';', $pairs ), true );

        $power = isset( $_POST['wssf_term_balance_power'] )
            ? (float) $_POST['wssf_term_balance_power'] : 0.0;
        $power = max( 0.0, min( 5.0, $power ) );
        update_option( 'wssf_term_balance_power', $power, true );

        $available = Search_Engine::get_post_types();
        $weights   = [];
        foreach ( $available as $pt ) {
            $field = 'wssf_pt_weight_' . $pt;
            if ( ! isset( $_POST[ $field ] ) ) { $weights[ $pt ] = 1.0; continue; }
            $w = (float) $_POST[ $field ];
            if ( $w < 0 ) { $w = 0.0; }
            if ( $w > 10 ) { $w = 10.0; }
            $weights[ $pt ] = $w;
        }
        update_option( 'wssf_post_type_weights', $weights, true );

        $cache = Plugin::instance()->cache;
        $cache->bump_generation();
        $cache->delete_transients_by_prefix( 'wssf_idf_' );

        wp_safe_redirect( add_query_arg(
            [ 'page' => self::PAGE_SLUG, 'tab' => 'ranking', 'ranking_saved' => 1 ],
            admin_url( 'options-general.php' )
        ) );
        exit;
    }

    /* ============================================================
     *  Stopwords
     * ============================================================ */

    public static function handle_save_stopwords(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            Security::log_event( 'cap_denied', 'admin_action' );
            wp_die( 'Forbidden', 403 );
        }
        check_admin_referer( 'wssf_save_stopwords' );

        $action = isset( $_POST['wssf_sw_action'] )
            ? sanitize_key( wp_unslash( $_POST['wssf_sw_action'] ) ) : 'add';

        $lang = isset( $_POST['wssf_sw_lang'] )
            ? sanitize_key( wp_unslash( $_POST['wssf_sw_lang'] ) ) : 'ru';
        if ( ! in_array( $lang, [ 'ru', 'en' ], true ) ) { $lang = 'ru'; }

        if ( $action === 'save_enabled' ) {
            update_option( 'wssf_stopwords_enabled', isset( $_POST['wssf_stopwords_enabled'] ) ? 1 : 0, true );
            try {
                Stopwords::instance()->flush_cache();
                $cache = Plugin::instance()->cache;
                $cache->bump_generation();
            } catch ( \Throwable $e ) {}
        } else {
            $sw = Stopwords::instance();

            if ( $action === 'add' ) {
                $raw   = isset( $_POST['wssf_sw_terms'] )
                    ? (string) wp_unslash( $_POST['wssf_sw_terms'] ) : '';
                $lines = preg_split( '/[\r\n,]+/u', $raw );
                if ( is_array( $lines ) ) {
                    foreach ( $lines as $line ) {
                        $line = trim( (string) $line );
                        if ( $line === '' ) { continue; }
                        $sw->add( $line, $lang );
                    }
                }
            } elseif ( $action === 'remove' ) {
                $term = isset( $_POST['wssf_sw_term'] )
                    ? sanitize_text_field( wp_unslash( $_POST['wssf_sw_term'] ) ) : '';
                if ( $term !== '' ) { $sw->remove( $term, $lang ); }
            }
        }

        wp_safe_redirect( add_query_arg(
            [ 'page' => self::PAGE_SLUG, 'tab' => 'general', 'sw_saved' => 1, 'sw_lang' => $lang ],
            admin_url( 'options-general.php' )
        ) );
        exit;
    }

    public static function handle_stopwords_clear(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            Security::log_event( 'cap_denied', 'admin_action' );
            wp_die( 'Forbidden', 403 );
        }
        check_admin_referer( 'wssf_stopwords_clear' );

        $lang = isset( $_POST['lang'] )
            ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : '';
        if ( ! in_array( $lang, [ '', 'ru', 'en' ], true ) ) { $lang = ''; }

        Stopwords::instance()->clear( $lang );

        wp_safe_redirect( add_query_arg(
            [ 'page' => self::PAGE_SLUG, 'tab' => 'general', 'sw_cleared' => 1 ],
            admin_url( 'options-general.php' )
        ) );
        exit;
    }

    public static function handle_stopwords_reload(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            Security::log_event( 'cap_denied', 'admin_action' );
            wp_die( 'Forbidden', 403 );
        }
        check_admin_referer( 'wssf_stopwords_reload' );

        Stopwords::instance()->load_defaults();

        wp_safe_redirect( add_query_arg(
            [ 'page' => self::PAGE_SLUG, 'tab' => 'general', 'sw_reloaded' => 1 ],
            admin_url( 'options-general.php' )
        ) );
        exit;
    }

    /* ============================================================
     *  Cron
     * ============================================================ */

    public static function handle_cron_run_now(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            Security::log_event( 'cap_denied', 'admin_action' );
            wp_die( 'Forbidden', 403 );
        }
        check_admin_referer( 'wssf_cron_run_now' );

        Hooks::instance( Plugin::instance() )->run_cron_reindex();

        wp_safe_redirect( add_query_arg(
            [ 'page' => self::PAGE_SLUG, 'tab' => 'maintenance', 'cron_ran' => 1 ],
            admin_url( 'options-general.php' )
        ) );
        exit;
    }

    /* ============================================================
     *  IOC map: пересборка
     * ============================================================ */

    public static function handle_ioc_map_rebuild(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            Security::log_event( 'cap_denied', 'admin_action' );
            wp_die( 'Forbidden', 403 );
        }
        check_admin_referer( 'wssf_ioc_map_rebuild' );

        try {
            $indexer = new Indexer( new Tokenizer( new Morphology() ) );

            $ioc_map = new IOC_Map( Plugin::instance()->cache );
            $ioc_map->truncate();

            $post_types = Search_Engine::get_post_types();
            if ( ! empty( $post_types ) ) {
                global $wpdb;
                $ph  = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
                $ids = $wpdb->get_col( $wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts}
                     WHERE post_status = 'publish' AND post_type IN ($ph)
                     LIMIT 5000",
                    $post_types
                ) );
                if ( is_array( $ids ) ) {
                    foreach ( $ids as $id ) { $indexer->index_post( (int) $id ); }
                }
            }
        } catch ( \Throwable $e ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[WSSF] ioc_map rebuild: ' . sanitize_text_field( $e->getMessage() ) );
            }
        }

        $cache = Plugin::instance()->cache;
        $cache->bump_generation();
        $cache->delete_transients_by_prefix( 'wssf_coc_' );
        $cache->delete_transients_by_prefix( 'wssf_ioc_rel_' );

        wp_safe_redirect( add_query_arg(
            [ 'page' => self::PAGE_SLUG, 'tab' => 'maintenance', 'ioc_map_rebuilt' => 1 ],
            admin_url( 'options-general.php' )
        ) );
        exit;
    }

    /* ============================================================
     *  Индекс
     * ============================================================ */

    public static function handle_reset_index(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            Security::log_event( 'cap_denied', 'admin_action' );
            wp_die( 'Forbidden', 403 );
        }
        check_admin_referer( 'wssf_reset_index' );

        global $wpdb;
        $table = (string) $wpdb->prefix . 'wssf_index';
        $terms = (string) $wpdb->prefix . 'wssf_terms';

        try {
            if ( preg_match( '/^[a-zA-Z0-9_]+$/', $table ) ) {
                $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
                if ( $exists ) { $wpdb->query( "DELETE FROM `{$table}`" ); } // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- DDL/DML statement without placeholders; the table name is validated with /^[a-zA-Z0-9_]+$/.
            }
            if ( preg_match( '/^[a-zA-Z0-9_]+$/', $terms ) ) {
                $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $terms ) ) === $terms;
                if ( $exists ) { $wpdb->query( "TRUNCATE TABLE `{$terms}`" ); } // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- DDL/DML statement without placeholders; the table name is validated with /^[a-zA-Z0-9_]+$/.
            }
        } catch ( \Throwable $e ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[WSSF] reset index: ' . sanitize_text_field( $e->getMessage() ) );
            }
        }

        try {
            $ioc_map = new IOC_Map( Plugin::instance()->cache );
            $ioc_map->truncate();
        } catch ( \Throwable $e ) { /* ignore */ }

        try {
            $mh = new MinHash( Plugin::instance()->cache );
            $mh->truncate();
        } catch ( \Throwable $e ) { /* ignore */ }

        $cache = Plugin::instance()->cache;
        $cache->bump_generation();
        $cache->delete( 'wssf_index_count' );
        $cache->delete( 'wssf_index_exists' );
        $cache->delete( 'wssf_sem_dict_v1' );
        $cache->delete( 'wssf_bk_tree_v1' );
        $cache->delete( 'wssf_ngram_index_v1' );
        $cache->delete( 'wssf_fuzzy_dict_v1' );
        $cache->delete_transients_by_prefix( 'wssf_ac_' );
        $cache->delete_transients_by_prefix( 'wssf_coc_' );
        $cache->delete_transients_by_prefix( 'wssf_ioc_rel_' );
        $cache->delete_transients_by_prefix( 'wssf_mh_sim_' );
        delete_option( 'wssf_index_updated_at' );

        wp_safe_redirect( add_query_arg(
            [ 'page' => self::PAGE_SLUG, 'tab' => 'maintenance', 'index_reset' => 1 ],
            admin_url( 'options-general.php' )
        ) );
        exit;
    }

    public static function handle_drop_index(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            Security::log_event( 'cap_denied', 'admin_action' );
            wp_die( 'Forbidden', 403 );
        }
        check_admin_referer( 'wssf_drop_index' );

        global $wpdb;
        $table = (string) $wpdb->prefix . 'wssf_index';
        $terms = (string) $wpdb->prefix . 'wssf_terms';

        try {
            if ( preg_match( '/^[a-zA-Z0-9_]+$/', $table ) ) {
                $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- DDL statement without placeholders; the table name is validated with /^[a-zA-Z0-9_]+$/.
            }
            if ( preg_match( '/^[a-zA-Z0-9_]+$/', $terms ) ) {
                $wpdb->query( "DROP TABLE IF EXISTS `{$terms}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- DDL statement without placeholders; the table name is validated with /^[a-zA-Z0-9_]+$/.
            }
        } catch ( \Throwable $e ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[WSSF] drop index: ' . sanitize_text_field( $e->getMessage() ) );
            }
        }

        $indexer = new Indexer( new Tokenizer( new Morphology() ) );
        $indexer->create_table();
        $indexer->create_terms_table();
        $indexer->ensure_indexes();

        $cache = Plugin::instance()->cache;
        $cache->bump_generation();
        $cache->delete( 'wssf_index_count' );
        $cache->delete( 'wssf_index_exists' );
        $cache->delete( 'wssf_sem_dict_v1' );
        $cache->delete( 'wssf_bk_tree_v1' );
        $cache->delete( 'wssf_ngram_index_v1' );
        $cache->delete( 'wssf_fuzzy_dict_v1' );
        $cache->delete_transients_by_prefix( 'wssf_ac_' );
        $cache->delete_transients_by_prefix( 'wssf_coc_' );
        $cache->delete_transients_by_prefix( 'wssf_ioc_rel_' );
        $cache->delete_transients_by_prefix( 'wssf_mh_sim_' );

        wp_safe_redirect( add_query_arg(
            [ 'page' => self::PAGE_SLUG, 'tab' => 'maintenance', 'index_dropped' => 1 ],
            admin_url( 'options-general.php' )
        ) );
        exit;
    }

    /* ============================================================
     *  Кеши
     * ============================================================ */

    public static function handle_clear_cache(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            Security::log_event( 'cap_denied', 'admin_action' );
            wp_die( 'Forbidden', 403 );
        }
        check_admin_referer( 'wssf_clear_cache' );

        $cache = Plugin::instance()->cache;
        $cache->bump_generation();
        $cache->delete( 'wssf_index_count' );
        $cache->delete( 'wssf_index_exists' );
        $cache->delete( 'wssf_sem_dict_v1' );
        $cache->delete( 'wssf_sw_v1_ru' );
        $cache->delete( 'wssf_sw_v1_en' );
        $cache->delete_transients_by_prefix( 'wssf_prf_' );
        $cache->delete_transients_by_prefix( 'wssf_syn_' );
        $cache->delete_transients_by_prefix( 'wssf_fuzzy_' );
        $cache->delete_transients_by_prefix( 'wssf_idf_' );
        $cache->delete_transients_by_prefix( 'wssf_ac_' );
        $cache->delete_transients_by_prefix( 'wssf_coc_' );
        $cache->delete_transients_by_prefix( 'wssf_ioc_rel_' );
        $cache->delete_transients_by_prefix( 'wssf_mh_sim_' );

        wp_safe_redirect( add_query_arg(
            [ 'page' => self::PAGE_SLUG, 'tab' => 'maintenance', 'cache_cleared' => 1 ],
            admin_url( 'options-general.php' )
        ) );
        exit;
    }

    public static function handle_clear_ct_cache(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            Security::log_event( 'cap_denied', 'admin_action' );
            wp_die( 'Forbidden', 403 );
        }
        check_admin_referer( 'wssf_clear_ct_cache' );

        $cache = Plugin::instance()->cache;
        $cache->delete_transients_by_prefix( 'wssf_ct_' );
        $cache->delete_options_by_prefix( '_wssf_ct_lock_' );

        wp_safe_redirect( add_query_arg(
            [ 'page' => self::PAGE_SLUG, 'tab' => 'maintenance', 'ct_cache_cleared' => 1 ],
            admin_url( 'options-general.php' )
        ) );
        exit;
    }

    public static function handle_clear_rate_limit(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            Security::log_event( 'cap_denied', 'admin_action' );
            wp_die( 'Forbidden', 403 );
        }
        check_admin_referer( 'wssf_clear_rate_limit' );
        Plugin::instance()->cache->flush_rate_limit_group();
        wp_safe_redirect( add_query_arg(
            [ 'page' => self::PAGE_SLUG, 'tab' => 'maintenance', 'rl_cleared' => 1 ],
            admin_url( 'options-general.php' )
        ) );
        exit;
    }

    public static function handle_clear_diag_cache(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            Security::log_event( 'cap_denied', 'admin_action' );
            wp_die( 'Forbidden', 403 );
        }
        check_admin_referer( 'wssf_clear_diag_cache' );
        Plugin::instance()->cache->delete_transients_by_prefix( 'wssf_diag_' );
        wp_safe_redirect( add_query_arg(
            [ 'page' => self::PAGE_SLUG, 'tab' => 'maintenance', 'diag_cleared' => 1 ],
            admin_url( 'options-general.php' )
        ) );
        exit;
    }

    public static function handle_clear_all_caches(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            Security::log_event( 'cap_denied', 'admin_action' );
            wp_die( 'Forbidden', 403 );
        }
        check_admin_referer( 'wssf_clear_all_caches' );

        $cache = Plugin::instance()->cache;
        $cache->bump_generation();
        $cache->delete( 'wssf_index_count' );
        $cache->delete( 'wssf_index_exists' );
        $cache->delete( 'wssf_sem_dict_v1' );
        $cache->delete( 'wssf_bk_tree_v1' );
        $cache->delete( 'wssf_ngram_index_v1' );
        $cache->delete( 'wssf_fuzzy_dict_v1' );
        $cache->delete( 'wssf_sw_v1_ru' );
        $cache->delete( 'wssf_sw_v1_en' );
        $cache->delete_transients_by_prefix( 'wssf_prf_' );
        $cache->delete_transients_by_prefix( 'wssf_syn_' );
        $cache->delete_transients_by_prefix( 'wssf_fuzzy_' );
        $cache->delete_transients_by_prefix( 'wssf_idf_' );
        $cache->delete_transients_by_prefix( 'wssf_ac_' );
        $cache->delete_transients_by_prefix( 'wssf_coc_' );
        $cache->delete_transients_by_prefix( 'wssf_ioc_rel_' );
        $cache->delete_transients_by_prefix( 'wssf_mh_sim_' );
        $cache->delete_transients_by_prefix( 'wssf_ct_' );
        $cache->delete_options_by_prefix( '_wssf_ct_lock_' );
        $cache->flush_rate_limit_group();
        $cache->delete_transients_by_prefix( 'wssf_diag_' );

        wp_safe_redirect( add_query_arg(
            [ 'page' => self::PAGE_SLUG, 'tab' => 'maintenance', 'all_cleared' => 1 ],
            admin_url( 'options-general.php' )
        ) );
        exit;
    }

    /* ============================================================
     *  Диагностика
     * ============================================================ */

    public static function handle_diag_run(): void {
        Admin_Diagnostics::instance()->handle_run();
    }

    public static function handle_diag_search(): void {
        Admin_Diagnostics::instance()->handle_search_test();
    }

    /* ============================================================
     *  AJAX: переиндексация
     * ============================================================ */

    /**
     * Error text for an AJAX reply.
     *
     * The raw exception message can carry SQL fragments, table names and
     * absolute paths: never sent to the browser, logged instead (SEC-001).
     */
    private static function error_text( \Throwable $e ): string {
        if ( function_exists( 'error_log' ) ) {
            error_log( '[WSSF] internal: ' . sanitize_text_field( $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- server log only, never sent to the browser.
        }
        return __( 'Ошибка операции. Подробности — в логе сервера.', 'wp-smart-search-frontend' );
    }

    public static function ajax_reindex_start(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            Security::log_event( 'cap_denied', 'ajax:reindex' );
            wp_send_json_error( [ 'code' => 'wssf_cap_denied', 'message' => 'Forbidden' ], 403 );
        }
        check_ajax_referer( 'wssf_reindex_ajax', 'nonce' );

        $mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'all';
        if ( ! in_array( $mode, [ 'all', 'missing' ], true ) ) { $mode = 'all'; }

        try {
            $indexer = new Indexer( new Tokenizer( new Morphology() ) );
            $indexer->create_table();
            $indexer->create_terms_table();
            ( new MinHash( Plugin::instance()->cache ) )->create_table();
            $total = ( $mode === 'missing' )
                ? $indexer->count_missing_posts()
                : $indexer->count_all_posts();
        } catch ( \Throwable $e ) {
            wp_send_json_error( [ 'code' => 'wssf_internal', 'message' => self::error_text( $e ) ], 500 );
        }

        $batch_size = max( 10, min( 500, (int) get_option( 'wssf_reindex_batch_size', 200 ) ) );

        wp_send_json_success( [
            'total'      => $total,
            'offset'     => 0,
            'mode'       => $mode,
            'batch_size' => $batch_size,
        ] );
    }

    public static function ajax_reindex_batch(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            Security::log_event( 'cap_denied', 'ajax:reindex' );
            wp_send_json_error( [ 'code' => 'wssf_cap_denied', 'message' => 'Forbidden' ], 403 );
        }
        check_ajax_referer( 'wssf_reindex_ajax', 'nonce' );

        $mode = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'all';
        if ( ! in_array( $mode, [ 'all', 'missing' ], true ) ) { $mode = 'all'; }

        $offset = isset( $_POST['offset'] ) ? absint( $_POST['offset'] ) : 0;

        $batch_size = isset( $_POST['limit'] )
            ? max( 10, min( 500, absint( $_POST['limit'] ) ) )
            : max( 10, min( 500, (int) get_option( 'wssf_reindex_batch_size', 200 ) ) );

        $indexer = new Indexer( new Tokenizer( new Morphology() ) );

        try {
            if ( $mode === 'missing' ) {
                $ids = $indexer->get_missing_post_ids( $batch_size );
            } else {
                $ids = $indexer->get_all_post_ids( $offset, $batch_size );
            }
        } catch ( \Throwable $e ) {
            wp_send_json_error( [ 'code' => 'wssf_internal', 'message' => self::error_text( $e ) ], 500 );
        }

        $processed = 0;
        $written   = 0;
        try {
            foreach ( $ids as $id ) {
                if ( $indexer->index_post( (int) $id ) ) { $written++; }
                $processed++;
            }
        } catch ( \Throwable $e ) {
            wp_send_json_error( [ 'code' => 'wssf_internal', 'message' => self::error_text( $e ) ], 500 );
        }

        if ( $written === 0 && $processed > 0 ) {
            update_option( 'wssf_index_updated_at', current_time( 'mysql' ), true );
            Plugin::instance()->cache->delete( 'wssf_index_count' );
            wp_send_json_success( [
                'processed' => 0,
                'offset'    => $offset,
                'done'      => true,
                'error'     => 'Записи не индексируются (проверьте настройки типов)',
            ] );
        }

        $new_offset = $offset + $processed;

        if ( $mode === 'missing' ) {
            try { $remaining = $indexer->count_missing_posts(); }
            catch ( \Throwable $e ) { $remaining = 0; }
            $done = ( $remaining === 0 );
        } else {
            try { $total_now = $indexer->count_all_posts(); }
            catch ( \Throwable $e ) { $total_now = $new_offset; }
            $done = ( $new_offset >= $total_now );
        }

        if ( $done ) {
            update_option( 'wssf_index_updated_at', current_time( 'mysql' ), true );
            $cache = Plugin::instance()->cache;
            $cache->bump_generation();
            $cache->delete( 'wssf_index_count' );
            $cache->delete( 'wssf_sem_dict_v1' );
            $cache->delete( 'wssf_bk_tree_v1' );
            $cache->delete( 'wssf_ngram_index_v1' );
            $cache->delete( 'wssf_fuzzy_dict_v1' );
            $cache->delete_transients_by_prefix( 'wssf_idf_' );
            $cache->delete_transients_by_prefix( 'wssf_ac_' );
            $cache->delete_transients_by_prefix( 'wssf_coc_' );
            $cache->delete_transients_by_prefix( 'wssf_ioc_rel_' );
            $cache->delete_transients_by_prefix( 'wssf_mh_sim_' );
        }

        wp_send_json_success( [
            'processed' => $processed,
            'offset'    => $new_offset,
            'done'      => $done,
            'mode'      => $mode,
        ] );
    }

    /* ============================================================
     *  Статистика индекса
     * ============================================================ */

    public static function get_index_stats(): array {
        global $wpdb;
        $table         = (string) $wpdb->prefix . 'wssf_index';
        $terms_table   = (string) $wpdb->prefix . 'wssf_terms';
        $sw_table      = (string) $wpdb->prefix . 'wssf_stopwords';
        $ioc_table     = (string) $wpdb->prefix . 'wssf_ioc_map';
        $minhash_table = (string) $wpdb->prefix . 'wssf_minhash';

        $table_valid = preg_match( '/^[a-zA-Z0-9_]+$/', $table ) === 1;

        $stats = [
            'exists'         => false,
            'count'          => 0,
            'total_posts'    => 0,
            'missing'        => 0,
            'coverage'       => 0,
            'data_length'    => 0,
            'index_length'   => 0,
            'total_size'     => 0,
            'avg_size'       => 0,
            'raw_data_size'  => 0,
            'raw_avg_size'   => 0,
            'terms_count'    => 0,
            'terms_exists'   => false,
            'sw_count'       => 0,
            'sw_exists'      => false,
            'ioc_count'      => 0,
            'ioc_exists'     => false,
            'minhash_count'  => 0,
            'minhash_exists' => false,
            'updated_at'     => (string) get_option( 'wssf_index_updated_at', '' ),
            'schema_version' => (string) get_option( 'wssf_schema_version', '' ),
            'optimized'      => false,
        ];

        $stats['optimized'] = version_compare( $stats['schema_version'], \WSSF_VERSION, '>=' );

        try {
            $post_types = Search_Engine::get_post_types();
            if ( ! empty( $post_types ) ) {
                $ph = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
                $sql = $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->posts}
                     WHERE post_status = 'publish' AND post_type IN ($ph)",
                    $post_types
                );
                $stats['total_posts'] = (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- the SQL string is built with $wpdb->prepare() above.
            }
        } catch ( \Throwable $e ) { /* ignore */ }

        try {
            $stats['exists'] = $table_valid
                && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
        } catch ( \Throwable $e ) { /* ignore */ }

        try {
            $stats['terms_exists'] = preg_match( '/^[a-zA-Z0-9_]+$/', $terms_table )
                && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $terms_table ) ) === $terms_table;
            if ( $stats['terms_exists'] ) {
                $stats['terms_count'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$terms_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- literal SQL; the table name is validated with /^[a-zA-Z0-9_]+$/.
            }
        } catch ( \Throwable $e ) { /* ignore */ }

        try {
            $stats['sw_exists'] = preg_match( '/^[a-zA-Z0-9_]+$/', $sw_table )
                && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sw_table ) ) === $sw_table;
            if ( $stats['sw_exists'] ) {
                $stats['sw_count'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$sw_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- literal SQL; the table name is validated with /^[a-zA-Z0-9_]+$/.
            }
        } catch ( \Throwable $e ) { /* ignore */ }

        try {
            $stats['ioc_exists'] = preg_match( '/^[a-zA-Z0-9_]+$/', $ioc_table )
                && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $ioc_table ) ) === $ioc_table;
            if ( $stats['ioc_exists'] ) {
                $stats['ioc_count'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$ioc_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- literal SQL; the table name is validated with /^[a-zA-Z0-9_]+$/.
            }
        } catch ( \Throwable $e ) { /* ignore */ }

        try {
            $stats['minhash_exists'] = preg_match( '/^[a-zA-Z0-9_]+$/', $minhash_table )
                && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $minhash_table ) ) === $minhash_table;
            if ( $stats['minhash_exists'] ) {
                $stats['minhash_count'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$minhash_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- literal SQL; the table name is validated with /^[a-zA-Z0-9_]+$/.
            }
        } catch ( \Throwable $e ) { /* ignore */ }

        if ( ! $stats['exists'] ) { return $stats; }

        try {
            $row = $wpdb->get_row( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- literal SQL; the table name is validated with /^[a-zA-Z0-9_]+$/.
                "SELECT COUNT(*) AS cnt,
                        COALESCE(SUM(
                            LENGTH(title_stem) + LENGTH(content_stem) +
                            LENGTH(excerpt_stem) + LENGTH(tags_stem)
                        ), 0) AS raw_size
                 FROM `{$table}`"
            );
            if ( $row ) {
                $stats['count']         = (int) $row->cnt;
                $stats['raw_data_size'] = (int) $row->raw_size;
                if ( $stats['count'] > 0 ) {
                    $stats['raw_avg_size'] = (int) round( $stats['raw_data_size'] / $stats['count'] );
                }
            }
        } catch ( \Throwable $e ) { /* ignore */ }

        $stats['missing'] = max( 0, $stats['total_posts'] - $stats['count'] );

        try { $wpdb->query( "ANALYZE TABLE `{$table}`" ); } catch ( \Throwable $e ) { /* ignore */ } // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- DDL statement without placeholders; the table name is validated with /^[a-zA-Z0-9_]+$/.

        try {
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT DATA_LENGTH, INDEX_LENGTH
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
                DB_NAME,
                $table
            ) );
            if ( $row ) {
                $stats['data_length']  = (int) $row->DATA_LENGTH;
                $stats['index_length'] = (int) $row->INDEX_LENGTH;
                $stats['total_size']   = $stats['data_length'] + $stats['index_length'];
                if ( $stats['count'] > 0 ) {
                    $stats['avg_size'] = (int) round( $stats['total_size'] / $stats['count'] );
                }
            }
        } catch ( \Throwable $e ) { /* ignore */ }

        if ( $stats['total_posts'] > 0 ) {
            $stats['coverage'] = (int) round( $stats['count'] / $stats['total_posts'] * 100 );
        }

        return $stats;
    }

    public static function get_semantic_stats(): array {
        return [
            'prf_on'  => (bool) get_option( 'wssf_semantic_prf', 0 ),
            'syn_on'  => (bool) get_option( 'wssf_semantic_synonyms', 0 ),
            'typo_on' => (bool) get_option( 'wssf_semantic_typo', 0 ),
        ];
    }

    public static function format_bytes( int $bytes ): string {
        if ( $bytes < 1024 )               { return $bytes . ' B'; }
        if ( $bytes < 1024 * 1024 )        { return number_format( $bytes / 1024, 1, '.', '' ) . ' KB'; }
        if ( $bytes < 1024 * 1024 * 1024 ) { return number_format( $bytes / 1024 / 1024, 1, '.', '' ) . ' MB'; }
        return number_format( $bytes / 1024 / 1024 / 1024, 2, '.', '' ) . ' GB';
    }
}