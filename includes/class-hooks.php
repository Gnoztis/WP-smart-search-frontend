<?php
declare(strict_types=1);

namespace WSSF;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Hooks {

    private static ?self $instance = null;

    public const CRON_HOOK            = 'wssf_cron_reindex';
    public const INDEX_ASYNC_HOOK     = 'wssf_index_single_post';

    public static function instance( Plugin $plugin ): self {
        if ( self::$instance === null ) { self::$instance = new self( $plugin ); }
        return self::$instance;
    }

    private function __construct( private Plugin $plugin ) {}

    public function register(): void {
        add_filter( 'template_include', [ $this, 'template_include' ], 99 );
        add_action( 'wp_enqueue_scripts', [ $this->plugin->assets, 'enqueue_assets' ] );
        add_action( 'wp_head', [ $this, 'expert_review_shim' ], 1 );
        add_action( 'wp_head', [ $this, 'head_layout_fixes' ], 99 );

        add_action( 'wp_ajax_' . Plugin::AJAX_ACTION,        [ $this->plugin->ajax, 'handle' ] );
        add_action( 'wp_ajax_nopriv_' . Plugin::AJAX_ACTION, [ $this->plugin->ajax, 'handle' ] );
        add_action( 'wp_ajax_' . Plugin::SUGGEST_ACTION,        [ $this->plugin->ajax, 'handle_suggest' ] );
        add_action( 'wp_ajax_nopriv_' . Plugin::SUGGEST_ACTION, [ $this->plugin->ajax, 'handle_suggest' ] );
        add_action( 'wp_ajax_wssf_history', [ $this->plugin->ajax, 'handle_history' ] );

        add_filter( 'get_search_form', [ $this->plugin->cleantalk, 'add_fields_to_search_form' ], 99 );

        add_action( 'save_post',        [ $this, 'bump_on_save_post' ], 10, 3 );
        add_action( 'deleted_post',     [ $this->plugin->cache, 'bump_generation' ] );
        add_action( 'set_object_terms', [ $this->plugin->cache, 'bump_generation' ] );
        add_action( 'created_term',     [ $this->plugin->cache, 'bump_generation' ] );
        add_action( 'edited_term',      [ $this, 'bump_on_edited_term' ], 10, 3 );
        add_action( 'delete_term',      [ $this->plugin->cache, 'bump_generation' ] );

        add_action( 'save_post',        [ $this, 'on_save_post_index' ], 10, 1 );
        add_action( 'deleted_post',     [ $this, 'on_delete_post_index' ], 10, 1 );
        add_action( 'set_object_terms', [ $this, 'on_set_object_terms' ], 10, 1 );

        // Асинхронная индексация одиночного поста (см. on_save_post_index).
        add_action( self::INDEX_ASYNC_HOOK, [ $this, 'index_single_post_async' ], 10, 1 );

        add_action( 'init', [ $this, 'run_migrations' ], 1 );

        add_filter( 'cron_schedules', [ $this, 'add_cron_schedules' ] );
        add_action( self::CRON_HOOK, [ $this, 'run_cron_reindex' ] );
        add_action( 'init', [ $this, 'schedule_cron' ], 20 );

        add_action( 'wp_logout', [ $this, 'on_user_logout' ] );

        // Security headers
        if ( class_exists( 'WSSF_Security_Headers' ) ) {
            ( new Security_Headers() )->register();
        }
    }

    /* ============================================================
     *  Cron
     * ============================================================ */

    public function add_cron_schedules( array $schedules ): array {
        if ( ! isset( $schedules['wssf_hourly'] ) ) {
            $schedules['wssf_hourly'] = [ 'interval' => HOUR_IN_SECONDS, 'display' => __( 'Каждый час', 'wp-smart-search-frontend' ) ];
        }
        if ( ! isset( $schedules['wssf_twicedaily'] ) ) {
            $schedules['wssf_twicedaily'] = [ 'interval' => 12 * HOUR_IN_SECONDS, 'display' => __( 'Два раза в день', 'wp-smart-search-frontend' ) ];
        }
        return $schedules;
    }

    public function schedule_cron(): void {
        $enabled  = (bool) get_option( 'wssf_cron_reindex_enabled', 1 );
        $interval = (string) get_option( 'wssf_cron_reindex_interval', 'daily' );
        if ( ! in_array( $interval, [ 'wssf_hourly', 'wssf_twicedaily', 'daily' ], true ) ) { $interval = 'daily'; }

        $scheduled = wp_next_scheduled( self::CRON_HOOK );

        if ( $enabled && ! $scheduled ) {
            wp_schedule_event( time() + 300, $interval, self::CRON_HOOK );
        } elseif ( ! $enabled && $scheduled ) {
            wp_clear_scheduled_hook( self::CRON_HOOK );
        } elseif ( $enabled && $scheduled ) {
            $current = wp_get_schedule( self::CRON_HOOK );
            if ( $current !== $interval ) {
                wp_clear_scheduled_hook( self::CRON_HOOK );
                wp_schedule_event( time() + 300, $interval, self::CRON_HOOK );
            }
        }
    }

    public function run_cron_reindex(): void {
        try {
            $batch_size = max( 50, min( 2000, (int) get_option( 'wssf_cron_batch_size', 500 ) ) );
            $indexer    = new Indexer( new Tokenizer( new Morphology() ) );
            $result     = $indexer->reindex_missing_cron( $batch_size );

            update_option( 'wssf_cron_last_run', current_time( 'mysql' ), true );
            update_option( 'wssf_cron_last_result', $result, true );

            if ( $result['processed'] > 0 ) {
                $cache = $this->plugin->cache;
                $cache->bump_generation();
                $cache->delete( 'wssf_index_count' );
                $cache->delete_transients_by_prefix( 'wssf_ac_' );
                $cache->delete_transients_by_prefix( 'wssf_mh_sim_' );
            }
        } catch ( \Throwable $e ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'error_log' ) ) {
                error_log( '[WSSF] cron reindex error: ' . sanitize_text_field( $e->getMessage() ) );
            }
        }
    }

    /**
     * @return array{enabled:bool, interval:string, next_run:int, last_run:string, last_result:array, disabled_wp_cron:bool}
     */
    public function get_cron_status(): array {
        $enabled  = (bool) get_option( 'wssf_cron_reindex_enabled', 1 );
        $interval = (string) get_option( 'wssf_cron_reindex_interval', 'daily' );
        $next     = (int) wp_next_scheduled( self::CRON_HOOK );
        $last     = (string) get_option( 'wssf_cron_last_run', '' );
        $last_res = get_option( 'wssf_cron_last_result', [] );
        if ( ! is_array( $last_res ) ) { $last_res = []; }

        return [
            'enabled'          => $enabled,
            'interval'         => $interval,
            'next_run'         => $next,
            'last_run'         => $last,
            'last_result'      => $last_res,
            'disabled_wp_cron' => ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ),
        ];
    }

    /* ============================================================
     *  template_include / head
     * ============================================================ */

    public function template_include( $template ) {
        if ( is_admin() || ! is_search() ) { return $template; }
        $this->plugin->cleantalk->check_search_get_request();
        $custom = PATH . 'templates/search.php';
        return file_exists( $custom ) ? $custom : $template;
    }

    public function expert_review_shim(): void {
        if ( ! is_search() ) { return; }
        echo '<script>window.expert_review_ajax = window.expert_review_ajax || { ajax_url: "" };</script>' . "\n";
    }

    public function head_layout_fixes(): void {
        if ( ! is_search() ) { return; }
        ?>
        <style id="wssf-layout-fixes">
            html { overflow-y: scroll; scrollbar-gutter: stable; }
            html, body, #page, #content, #primary,
            .site, .site-content, .content-area, .content-area-inner,
            .site-main, .site-main-inner { min-width: 0 !important; max-width: 100% !important; }
            @media (max-width: 767.98px) {
                html, body { overflow-x: hidden !important; width: 100% !important; }
                .site-content, .content-area, .content-area-inner,
                .site-main, .site-main-inner { overflow-x: hidden !important; }
            }
        </style>
        <?php
    }

    public function bump_on_save_post( $post_id, $post = null, $update = null ): void {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) { return; }
        if ( ! $post instanceof \WP_Post ) { $post = get_post( $post_id ); }
        if ( ! $post instanceof \WP_Post ) { return; }
        if ( ! in_array( $post->post_type, Search_Engine::get_post_types(), true ) ) { return; }
        $this->plugin->cache->bump_generation();
    }

    public function bump_on_edited_term( $term_id, $tt_id, $taxonomy ): void {
        $this->plugin->cache->bump_generation();
        if ( $taxonomy !== 'post_tag' ) { return; }
        global $wpdb;
        $post_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT tr.object_id FROM {$wpdb->term_relationships} AS tr WHERE tr.term_taxonomy_id = %d",
            (int) $tt_id
        ) );
        if ( ! is_array( $post_ids ) ) { return; }
        if ( count( $post_ids ) > 500 ) { return; }
        $indexer = new Indexer( new Tokenizer( new Morphology() ) );
        foreach ( $post_ids as $pid ) { $indexer->index_post( (int) $pid ); }
    }

    /**
     * Индексация поста при save_post. Если wssf_index_async_save=1 и
     * WP-Cron включён — откладываем через wp_schedule_single_event,
     * чтобы не блокировать редактор.
     */
    public function on_save_post_index( $post_id ): void {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) { return; }

        $post_id = (int) $post_id;
        if ( $post_id <= 0 ) { return; }

        $async       = (bool) get_option( 'wssf_index_async_save', 0 );
        $wp_cron_off = ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON );

        if ( $async && ! $wp_cron_off ) {
            $scheduled = wp_schedule_single_event(
                time() + 5,
                self::INDEX_ASYNC_HOOK,
                [ $post_id ]
            );
            if ( $scheduled !== false && ! is_wp_error( $scheduled ) ) {
                $this->plugin->cache->delete( 'wssf_index_count' );
                return;
            }
            // fallback к синхронной индексации при неудаче планирования
        }

        try {
            $indexer = new Indexer( new Tokenizer( new Morphology() ) );
            $indexer->index_post( $post_id );
        } catch ( \Throwable $e ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'error_log' ) ) {
                error_log( '[WSSF] index_post error: ' . sanitize_text_field( $e->getMessage() ) );
            }
        }
        $this->plugin->cache->delete( 'wssf_index_count' );
    }

    public function index_single_post_async( $post_id ): void {
        $post_id = (int) $post_id;
        if ( $post_id <= 0 ) { return; }

        try {
            $indexer = new Indexer( new Tokenizer( new Morphology() ) );
            $indexer->index_post( $post_id );
            $this->plugin->cache->delete( 'wssf_index_count' );
        } catch ( \Throwable $e ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'error_log' ) ) {
                error_log( '[WSSF] async index error: ' . sanitize_text_field( $e->getMessage() ) );
            }
        }
    }

    public function on_delete_post_index( $post_id ): void {
        try {
            $indexer = new Indexer( new Tokenizer( new Morphology() ) );
            $indexer->delete_post( (int) $post_id );
        } catch ( \Throwable $e ) {}
    }

    public function on_set_object_terms( $object_id ): void {
        $this->on_save_post_index( (int) $object_id );
    }

    public function run_migrations(): void {
        Migrations::run();
    }

    public function on_user_logout( $user_id ): void {
        $user_id = (int) $user_id;
        if ( $user_id > 0 ) { delete_user_meta( $user_id, 'wssf_search_history' ); }
        if ( ! headers_sent() ) {
            setcookie( 'wssf_logout', '1', time() + 60, '/', '', is_ssl(), false );
        }
    }
}