<?php
/**
 * Plugin Name: WP Smart Search (Frontend)
 * Plugin URI: https://github.com/Gnoztis/WP-smart-search-frontend
 * Description: AJAX-поиск с фасетными фильтрами, локальным расширением запроса и поддержкой IOC.
 * Version: 5.4.2
 * Author: Gnostis
 * Author URI: https://github.com/Gnoztis
 * Requires at least: 7.0
 * Tested up to: 7.1
 * Requires PHP: 8.0
 * License: GPL-2.0-or-later
 * Text Domain: wp-smart-search-frontend
 * Update URI: https://github.com/Gnoztis/WP-smart-search-frontend
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'WSSF_VERSION', '5.4.2' );
define( 'WSSF_PLUGIN_FILE', __FILE__ );
define( 'WSSF_PATH', plugin_dir_path( __FILE__ ) );
define( 'WSSF_URL',  plugin_dir_url( __FILE__ ) );

require_once WSSF_PATH . 'includes/class-stemmer-ru.php';
require_once WSSF_PATH . 'includes/class-stemmer-en.php';
require_once WSSF_PATH . 'includes/class-morphology.php';
require_once WSSF_PATH . 'includes/class-tokenizer.php';

require_once WSSF_PATH . 'includes/class-query-parser.php';
require_once WSSF_PATH . 'includes/class-excerpt.php';
require_once WSSF_PATH . 'includes/class-cache.php';
require_once WSSF_PATH . 'includes/class-security.php';
require_once WSSF_PATH . 'includes/class-search-engine.php';
require_once WSSF_PATH . 'includes/class-indexer.php';
require_once WSSF_PATH . 'includes/class-initial-state.php';
require_once WSSF_PATH . 'includes/class-layout-corrector.php';
require_once WSSF_PATH . 'includes/class-cleantalk.php';
require_once WSSF_PATH . 'includes/class-ajax-handler.php';
require_once WSSF_PATH . 'includes/class-assets.php';

require_once WSSF_PATH . 'includes/class-prf-expander.php';
require_once WSSF_PATH . 'includes/class-fuzzy-index.php';
require_once WSSF_PATH . 'includes/class-rank-fusion.php';
require_once WSSF_PATH . 'includes/class-semantic-search.php';

require_once WSSF_PATH . 'includes/class-autocomplete.php';
require_once WSSF_PATH . 'includes/class-stopwords.php';
require_once WSSF_PATH . 'includes/class-yara.php';
require_once WSSF_PATH . 'includes/class-ioc-map.php';
require_once WSSF_PATH . 'includes/class-minhash.php';
require_once WSSF_PATH . 'includes/class-security-headers.php';

require_once WSSF_PATH . 'includes/class-migrations.php';

require_once WSSF_PATH . 'includes/class-hooks.php';
require_once WSSF_PATH . 'includes/class-plugin.php';
require_once WSSF_PATH . 'includes/class-wssf-github-updater.php';

require_once WSSF_PATH . 'admin/class-admin-settings.php';
require_once WSSF_PATH . 'admin/class-admin-actions.php';

WSSF_Plugin::instance();

// Updates from GitHub through the native WordPress mechanism.
( new WSSF_GitHub_Updater() )->register_hooks();

register_activation_hook( __FILE__, function () {
    $indexer = new WSSF_Indexer( new WSSF_Tokenizer( new WSSF_Morphology() ) );
    $indexer->create_table();
    $indexer->create_terms_table();
    $indexer->ensure_indexes();

    if ( class_exists( 'WSSF_MinHash' ) ) {
        ( new WSSF_MinHash( new WSSF_Cache() ) )->create_table();
    }

    WSSF_Migrations::run();
} );

register_deactivation_hook( __FILE__, function () {
    wp_clear_scheduled_hook( WSSF_Hooks::CRON_HOOK );
} );

if ( is_admin() ) {
    require_once WSSF_PATH . 'admin/class-admin-diagnostics.php';

    add_action( 'admin_menu', function () {
        WSSF_Admin_Settings::instance()->register_menu();
    }, 20 );

    add_action( 'admin_enqueue_scripts', function ( $hook ) {
        if ( $hook !== 'settings_page_wssf-settings' ) { return; }

        $css_path = WSSF_PATH . 'assets/css/wssf-admin.css';
        if ( is_readable( $css_path ) ) {
            wp_register_style( 'wssf-admin', false, [], WSSF_VERSION );
            wp_enqueue_style( 'wssf-admin' );
            $css = (string) @file_get_contents( $css_path );
            if ( $css !== '' ) { wp_add_inline_style( 'wssf-admin', $css ); }
        }

        $js_path = WSSF_PATH . 'assets/js/wssf-admin.js';
        if ( is_readable( $js_path ) ) {
            wp_register_script( 'wssf-admin', false, [ 'jquery' ], WSSF_VERSION, true );
            wp_enqueue_script( 'wssf-admin' );
            $js = (string) @file_get_contents( $js_path );
            if ( $js !== '' ) { wp_add_inline_script( 'wssf-admin', $js, 'after' ); }
        }
    } );

    add_action( 'admin_post_wssf_save_settings',     [ 'WSSF_Admin_Actions', 'handle_save_settings' ] );
    add_action( 'admin_post_wssf_save_semantic',     [ 'WSSF_Admin_Actions', 'handle_save_semantic' ] );
    add_action( 'admin_post_wssf_save_ranking',      [ 'WSSF_Admin_Actions', 'handle_save_ranking' ] );
    add_action( 'admin_post_wssf_reset_index',       [ 'WSSF_Admin_Actions', 'handle_reset_index' ] );
    add_action( 'admin_post_wssf_drop_index',        [ 'WSSF_Admin_Actions', 'handle_drop_index' ] );
    add_action( 'admin_post_wssf_clear_cache',       [ 'WSSF_Admin_Actions', 'handle_clear_cache' ] );
    add_action( 'admin_post_wssf_clear_ct_cache',    [ 'WSSF_Admin_Actions', 'handle_clear_ct_cache' ] );
    add_action( 'admin_post_wssf_clear_rate_limit',  [ 'WSSF_Admin_Actions', 'handle_clear_rate_limit' ] );
    add_action( 'admin_post_wssf_clear_diag_cache',  [ 'WSSF_Admin_Actions', 'handle_clear_diag_cache' ] );
    add_action( 'admin_post_wssf_clear_all_caches',  [ 'WSSF_Admin_Actions', 'handle_clear_all_caches' ] );
    add_action( 'admin_post_wssf_diag_run',          [ 'WSSF_Admin_Actions', 'handle_diag_run' ] );
    add_action( 'admin_post_wssf_diag_search',       [ 'WSSF_Admin_Actions', 'handle_diag_search' ] );
    add_action( 'admin_post_wssf_save_stopwords',    [ 'WSSF_Admin_Actions', 'handle_save_stopwords' ] );
    add_action( 'admin_post_wssf_stopwords_clear',   [ 'WSSF_Admin_Actions', 'handle_stopwords_clear' ] );
    add_action( 'admin_post_wssf_stopwords_reload',  [ 'WSSF_Admin_Actions', 'handle_stopwords_reload' ] );
    add_action( 'admin_post_wssf_cron_run_now',      [ 'WSSF_Admin_Actions', 'handle_cron_run_now' ] );
    add_action( 'admin_post_wssf_ioc_map_rebuild',   [ 'WSSF_Admin_Actions', 'handle_ioc_map_rebuild' ] );

    add_action( 'wp_ajax_wssf_reindex_start', [ 'WSSF_Admin_Actions', 'ajax_reindex_start' ] );
    add_action( 'wp_ajax_wssf_reindex_batch', [ 'WSSF_Admin_Actions', 'ajax_reindex_batch' ] );

    add_action( 'admin_init', function () {
        if ( isset( $_GET['page'] ) && $_GET['page'] === 'wssf-diagnostics' ) {
            wp_safe_redirect( admin_url( 'options-general.php?page=wssf-settings&tab=diagnostics' ) );
            exit;
        }
    } );
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
    require_once WSSF_PATH . 'includes/class-cli.php';
    WP_CLI::add_command( 'wssf', 'WSSF_CLI' );
}