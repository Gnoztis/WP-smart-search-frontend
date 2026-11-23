<?php
/**
 * Plugin Name: WP Smart Search (Frontend)
 * Plugin URI: https://github.com/Gnoztis/WP-smart-search-frontend
 * Description: AJAX-поиск с фасетными фильтрами, локальным расширением запроса и поддержкой IOC.
 * Version: 5.5.3
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

define( 'WSSF_VERSION', '5.5.3' );
define( 'WSSF_PLUGIN_FILE', __FILE__ );
define( 'WSSF_PATH', plugin_dir_path( __FILE__ ) );
define( 'WSSF_URL',  plugin_dir_url( __FILE__ ) );

/*
 * Compatibility guard for the namespaced code below: inside 
amespace WSSF an
 * unqualified constant name does not fall back to the global one — a missing name
 * throws Error and takes the whole site down. 5.5.0 shipped exactly that mistake
 * with VERSION, PATH and URL. The aliases make a missed reference harmless while
 * tools/static-check/check-bare-constants.mjs keeps them out of the source.
 */
if ( ! defined( 'WSSF\VERSION' ) ) { define( 'WSSF\VERSION', WSSF_VERSION ); }
if ( ! defined( 'WSSF\PATH' ) )    { define( 'WSSF\PATH', WSSF_PATH ); }
if ( ! defined( 'WSSF\URL' ) )     { define( 'WSSF\URL', WSSF_URL ); }

// The plugin ships its own loader for the WSSF namespace: no Composer, no
// vendor directory, nothing outside this directory. Classes are loaded on
// demand; the old global names keep working through class_alias().
require_once WSSF_PATH . 'includes/autoload.php';

// Generated file, written by tools/scripts/sync-updater.mjs. It declares the
// global class WSSF_GitHub_Updater and is not part of the namespace.
require_once WSSF_PATH . 'includes/class-wssf-github-updater.php';

\WSSF\Plugin::instance();

// Updates from GitHub through the native WordPress mechanism.
( new WSSF_GitHub_Updater() )->register_hooks();

register_activation_hook( __FILE__, function () {
    $indexer = new \WSSF\Indexer( new \WSSF\Tokenizer( new \WSSF\Morphology() ) );
    $indexer->create_table();
    $indexer->create_terms_table();
    $indexer->ensure_indexes();

    ( new \WSSF\MinHash( new \WSSF\Cache() ) )->create_table();

    \WSSF\Migrations::run();
} );

register_deactivation_hook( __FILE__, function () {
    wp_clear_scheduled_hook( \WSSF\Hooks::CRON_HOOK );
} );

if ( is_admin() ) {
    add_action( 'admin_menu', function () {
        \WSSF\Admin_Settings::instance()->register_menu();
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

    add_action( 'admin_post_wssf_save_settings',     [ \WSSF\Admin_Actions::class, 'handle_save_settings' ] );
    add_action( 'admin_post_wssf_save_semantic',     [ \WSSF\Admin_Actions::class, 'handle_save_semantic' ] );
    add_action( 'admin_post_wssf_save_ranking',      [ \WSSF\Admin_Actions::class, 'handle_save_ranking' ] );
    add_action( 'admin_post_wssf_reset_index',       [ \WSSF\Admin_Actions::class, 'handle_reset_index' ] );
    add_action( 'admin_post_wssf_drop_index',        [ \WSSF\Admin_Actions::class, 'handle_drop_index' ] );
    add_action( 'admin_post_wssf_clear_cache',       [ \WSSF\Admin_Actions::class, 'handle_clear_cache' ] );
    add_action( 'admin_post_wssf_clear_ct_cache',    [ \WSSF\Admin_Actions::class, 'handle_clear_ct_cache' ] );
    add_action( 'admin_post_wssf_clear_rate_limit',  [ \WSSF\Admin_Actions::class, 'handle_clear_rate_limit' ] );
    add_action( 'admin_post_wssf_clear_diag_cache',  [ \WSSF\Admin_Actions::class, 'handle_clear_diag_cache' ] );
    add_action( 'admin_post_wssf_clear_all_caches',  [ \WSSF\Admin_Actions::class, 'handle_clear_all_caches' ] );
    add_action( 'admin_post_wssf_diag_run',          [ \WSSF\Admin_Actions::class, 'handle_diag_run' ] );
    add_action( 'admin_post_wssf_diag_search',       [ \WSSF\Admin_Actions::class, 'handle_diag_search' ] );
    add_action( 'admin_post_wssf_save_stopwords',    [ \WSSF\Admin_Actions::class, 'handle_save_stopwords' ] );
    add_action( 'admin_post_wssf_stopwords_clear',   [ \WSSF\Admin_Actions::class, 'handle_stopwords_clear' ] );
    add_action( 'admin_post_wssf_stopwords_reload',  [ \WSSF\Admin_Actions::class, 'handle_stopwords_reload' ] );
    add_action( 'admin_post_wssf_cron_run_now',      [ \WSSF\Admin_Actions::class, 'handle_cron_run_now' ] );
    add_action( 'admin_post_wssf_ioc_map_rebuild',   [ \WSSF\Admin_Actions::class, 'handle_ioc_map_rebuild' ] );

    add_action( 'wp_ajax_wssf_reindex_start', [ \WSSF\Admin_Actions::class, 'ajax_reindex_start' ] );
    add_action( 'wp_ajax_wssf_reindex_batch', [ \WSSF\Admin_Actions::class, 'ajax_reindex_batch' ] );

    add_action( 'admin_init', function () {
        if ( isset( $_GET['page'] ) && $_GET['page'] === 'wssf-diagnostics' ) {
            wp_safe_redirect( admin_url( 'options-general.php?page=wssf-settings&tab=diagnostics' ) );
            exit;
        }
    } );
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
    WP_CLI::add_command( 'wssf', \WSSF\CLI::class );
}
