<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class WSSF_Admin_Settings {

    private const PAGE_SLUG = 'wssf-settings';

    private const VIEW_MAP = [
        'status'      => 'status',
        'general'     => 'settings',
        'semantic'    => 'semantic',
        'ranking'     => 'ranking',
        'diagnostics' => 'diagnostics',
        'maintenance' => 'maintenance',
    ];

    private static ?self $instance = null;

    public static function instance(): self {
        if ( self::$instance === null ) { self::$instance = new self(); }
        return self::$instance;
    }

    public function register_menu(): void {
        add_options_page(
            __( 'WP Smart Search', 'wp-smart-search-frontend' ),
            __( 'WP Smart Search', 'wp-smart-search-frontend' ),
            'manage_options',
            self::PAGE_SLUG,
            [ $this, 'render_page' ]
        );
    }

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) { return; }

        $tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'status';
        if ( ! array_key_exists( $tab, self::VIEW_MAP ) ) { $tab = 'status'; }

        $base_url = admin_url( 'options-general.php?page=' . self::PAGE_SLUG );
        $tabs = [
            'status'      => [ 'dashicons-chart-area',     __( 'Статус', 'wp-smart-search-frontend' ) ],
            'general'     => [ 'dashicons-admin-settings', __( 'Настройки', 'wp-smart-search-frontend' ) ],
            'semantic'    => [ 'dashicons-networking',     __( 'Расширение запроса', 'wp-smart-search-frontend' ) ],
            'ranking'     => [ 'dashicons-chart-bar',      __( 'Ранжирование', 'wp-smart-search-frontend' ) ],
            'diagnostics' => [ 'dashicons-shield-alt',     __( 'Диагностика', 'wp-smart-search-frontend' ) ],
            'maintenance' => [ 'dashicons-admin-tools',    __( 'Обслуживание', 'wp-smart-search-frontend' ) ],
        ];
        ?>
        <div class="wrap wssf-admin">
            <h1>
                <span class="dashicons dashicons-search"></span>
                <?php esc_html_e( 'WP Smart Search', 'wp-smart-search-frontend' ); ?>
            </h1>

            <?php $this->render_notices(); ?>

            <nav class="nav-tab-wrapper">
                <?php foreach ( $tabs as $slug => $meta ) :
                    [ $icon, $label ] = $meta;
                ?>
                    <a href="<?php echo esc_url( $base_url . '&tab=' . $slug ); ?>"
                       class="nav-tab <?php echo $tab === $slug ? 'nav-tab-active' : ''; ?>">
                        <span class="dashicons <?php echo esc_attr( $icon ); ?>"></span>
                        <?php echo esc_html( $label ); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <div class="wssf-admin-tab-content">
                <?php
                try {
                    if ( $tab === 'diagnostics' ) {
                        WSSF_Admin_Diagnostics::instance()->render_tab();
                    } else {
                        $view_name = self::VIEW_MAP[ $tab ];
                        $view      = WSSF_PATH . 'admin/view-' . $view_name . '.php';
                        if ( file_exists( $view ) ) {
                            require $view;
                        } else {
                            echo '<div class="notice notice-error"><p>View не найден: <code>'
                                . esc_html( basename( $view ) ) . '</code></p></div>';
                        }
                    }
                } catch ( \Throwable $e ) {
                    // SEC-001: the exception text, the file and the line stay
                    // in the log; the screen shows a stable code.
                    error_log( '[WSSF] admin view: ' . sanitize_text_field( $e->getMessage() ) . ' @ ' . basename( $e->getFile() ) . ':' . (int) $e->getLine() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- server log only.
                    echo '<div class="notice notice-error"><p><b>WSSF error:</b> '
                        . esc_html__( 'Внутренняя ошибка. Подробности — в логе сервера.', 'wp-smart-search-frontend' )
                        . ' <code>wssf_admin_view</code></p></div>';
                }
                ?>
            </div>
        </div>
        <?php
    }

    private function render_notices(): void {
        $notices = [
            'saved'            => __( 'Настройки сохранены.', 'wp-smart-search-frontend' ),
            'semantic_saved'   => __( 'Настройки расширения запроса сохранены.', 'wp-smart-search-frontend' ),
            'ranking_saved'    => __( 'Настройки ранжирования сохранены.', 'wp-smart-search-frontend' ),
            'index_reset'      => __( 'Индекс очищен.', 'wp-smart-search-frontend' ),
            'index_dropped'    => __( 'Таблица индекса пересоздана и заполнена.', 'wp-smart-search-frontend' ),
            'cache_cleared'    => __( 'Кеш поиска очищен.', 'wp-smart-search-frontend' ),
            'ct_cache_cleared' => __( 'Вердикты CleanTalk и лок-ключи очищены.', 'wp-smart-search-frontend' ),
            'rl_cleared'       => __( 'Rate-limit счётчики очищены.', 'wp-smart-search-frontend' ),
            'diag_cleared'     => __( 'Транзиенты диагностики очищены.', 'wp-smart-search-frontend' ),
            'all_cleared'      => __( 'Все кеши очищены.', 'wp-smart-search-frontend' ),
        ];
        foreach ( $notices as $key => $msg ) {
            if ( isset( $_GET[ $key ] ) ) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
            }
        }
    }

    /* ============================================================
     *  Геттеры
     * ============================================================ */

    public static function get_strict_mode(): string {
        $mode = (string) get_option( 'wssf_strict_mode', 'auto' );
        return in_array( $mode, [ 'auto', 'on', 'off' ], true ) ? $mode : 'auto';
    }

    /**
     * CleanTalk integration mode: auto | on | off.
     *
     * `auto` (default) turns the check on only when CleanTalk is really
     * installed. Sites that saved the old checkbox before the mode existed
     * keep their choice: `wssf_cleantalk_enabled = 1` reads as `on`,
     * `0` reads as `off`.
     */
    public static function get_cleantalk_mode(): string {
        $mode = get_option( 'wssf_cleantalk_mode', null );

        if ( $mode === null ) {
            $legacy = get_option( 'wssf_cleantalk_enabled', null );
            if ( $legacy !== null ) {
                return (bool) $legacy ? 'on' : 'off';
            }
            return 'auto';
        }

        $mode = (string) $mode;
        return in_array( $mode, [ 'auto', 'on', 'off' ], true ) ? $mode : 'auto';
    }

    public static function get_ttl_user(): int  { return max( 0, (int) get_option( 'wssf_ttl_user', 60 ) ); }
    public static function get_ttl_guest(): int { return max( 0, (int) get_option( 'wssf_ttl_guest', 60 ) ); }

    public static function is_did_you_mean_enabled(): bool { return (bool) get_option( 'wssf_did_you_mean', 1 ); }
    public static function is_suggestions_enabled(): bool   { return (bool) get_option( 'wssf_suggestions', 1 ); }
    public static function is_history_enabled(): bool       { return (bool) get_option( 'wssf_history_enabled', 1 ); }

    public static function is_autocomplete_enabled(): bool {
        return (bool) get_option( 'wssf_autocomplete_enabled', 1 );
    }

    public static function get_autocomplete_min_len(): int {
        return max( 1, min( 10, (int) get_option( 'wssf_autocomplete_min_len', 2 ) ) );
    }

    public static function get_autocomplete_max(): int {
        return max( 1, min( 20, (int) get_option( 'wssf_autocomplete_max', 8 ) ) );
    }

    public static function get_synonyms(): array {
        $raw = (string) get_option( 'wssf_synonyms', '' );
        if ( trim( $raw ) === '' ) { return []; }

        $out = [];
        foreach ( explode( ';', $raw ) as $pair ) {
            $pair = trim( $pair );
            if ( $pair === '' ) { continue; }
            if ( strpos( $pair, '=' ) === false ) { continue; }
            [ $k, $v ] = explode( '=', $pair, 2 );
            $k = mb_strtolower( trim( (string) $k ) );
            $v = trim( (string) $v );
            if ( $k === '' || $v === '' ) { continue; }
            $out[ $k ][] = $v;
        }
        return $out;
    }

    public static function get_post_type_weights(): array {
        $saved = get_option( 'wssf_post_type_weights', [] );
        if ( ! is_array( $saved ) ) { return []; }
        $out = [];
        foreach ( $saved as $pt => $w ) {
            $pt = (string) $pt;
            $w  = (float) $w;
            if ( $pt === '' ) { continue; }
            $out[ $pt ] = max( 0.0, min( 10.0, $w ) );
        }
        return $out;
    }

    public static function get_term_balance_power(): float {
        return max( 0.0, min( 5.0, (float) get_option( 'wssf_term_balance_power', 0 ) ) );
    }
}