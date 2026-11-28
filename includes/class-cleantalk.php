<?php
declare(strict_types=1);

namespace WSSF;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class CleanTalk {

    /** Detection result of the current request; see is_available(). */
    private static ?bool $available = null;

    private const CT_VERDICT_TTL_GUEST = 60;
    private const CT_VERDICT_TTL_USER  = 3600;

    private const API_LOCK_TTL = 15;

    private const LOCK_WAIT_MS = 300;

    /**
     * Максимум вызовов CleanTalk API в минуту для одного scope
     * (IP для гостя, user_id для авторизованного). Защита от
     * исчерпания квоты через спам разными запросами с одного IP.
     */
    private const API_RATE_MAX    = 20;
    private const API_RATE_WINDOW = MINUTE_IN_SECONDS;

    public function __construct(
        private Cache $cache,
        private Security $security
    ) {}

    /* ============================================================
     *  Optional integration
     * ============================================================ */

    /**
     * Whether CleanTalk is actually installed on this site.
     *
     * Detected once per request. Nothing in the plugin calls an apbct_*
     * function without this check, so a site without CleanTalk runs the
     * search normally. The filter exists for stands and for tests:
     *
     *     add_filter( 'wssf_cleantalk_available', '__return_true' );
     */
    public static function is_available(): bool {
        if ( self::$available !== null ) {
            return self::$available;
        }

        $available = function_exists( 'apbct_base_call' ) || function_exists( 'ct_add_hidden_fields' );

        self::$available = (bool) apply_filters( 'wssf_cleantalk_available', $available );

        return self::$available;
    }

    /**
     * Configured mode: auto | on | off.
     *
     * - auto: the check runs only when CleanTalk is present;
     * - on:   forced, but still does nothing when CleanTalk is absent;
     * - off:  never runs, even when CleanTalk is present.
     */
    public static function is_enabled(): bool {
        $mode = Admin_Settings::get_cleantalk_mode();

        if ( $mode === 'off' ) { return false; }
        if ( $mode === 'on' ) { return true; }

        return self::is_available();
    }

    /**
     * The check really runs: the mode allows it and the plugin is there.
     */
    public static function active(): bool {
        return self::is_enabled() && self::is_available();
    }

    /**
     * Human-readable integration state for the admin screen.
     *
     * @return array{mode:string,available:bool,effective:bool}
     */
    public static function state(): array {
        return [
            'mode'      => Admin_Settings::get_cleantalk_mode(),
            'available' => self::is_available(),
            'effective' => self::active(),
        ];
    }

    /**
     * Hidden fields of the CleanTalk JS check, ready for output.
     *
     * Empty when the integration is not active, so a site without CleanTalk
     * never sees ct_* markup in its search form.
     */
    public static function form_fields(): string {
        if ( ! self::active() || ! function_exists( 'ct_add_hidden_fields' ) ) {
            return '';
        }

        try {
            $fields = ct_add_hidden_fields( 'ct_checkjs_search_default', true );
        } catch ( \Throwable $e ) {
            return '';
        }

        return is_string( $fields ) ? $fields : '';
    }

    /**
     * Result of the CleanTalk JavaScript test for this request.
     *
     * CleanTalk passes the value in a cookie when its cookie mode is on, and in
     * a hidden field otherwise. Reading only the hidden field meant js_on was 0
     * on every site with cookies enabled, and the service answered the visitor
     * with "Включите JavaScript" — which is exactly what the owner saw on a
     * phone, where the search form is a plain GET form.
     *
     * The service's own helper is used when it exists, so a static key, a
     * rotating key and the alternative cookie mode all keep working.
     */
    private function js_on(): int {
        $candidates = [];

        if ( isset( $_COOKIE['ct_checkjs'] ) && is_string( $_COOKIE['ct_checkjs'] ) ) {
            $candidates[] = sanitize_text_field( wp_unslash( $_COOKIE['ct_checkjs'] ) );
        }

        foreach ( [ 'ct_checkjs', 'ct_checkjs_search_default' ] as $field ) {
            if ( isset( $_POST[ $field ] ) && is_string( $_POST[ $field ] ) ) {
                $candidates[] = sanitize_text_field( wp_unslash( $_POST[ $field ] ) );
            }

            if ( isset( $_GET[ $field ] ) && is_string( $_GET[ $field ] ) ) {
                $candidates[] = sanitize_text_field( wp_unslash( $_GET[ $field ] ) );
            }
        }

        if ( function_exists( 'apbct_js_test' ) ) {
            foreach ( $candidates as $candidate ) {
                if ( '' === $candidate ) {
                    continue;
                }

                try {
                    if ( apbct_js_test( $candidate, true ) || apbct_js_test( $candidate ) ) {
                        return 1;
                    }
                } catch ( \Throwable $e ) {
                    continue;
                }
            }

            return 0;
        }

        // No helper: the service's key is a long hex string, anything else —
        // including the literal 0 that marks "JavaScript is off" — is a no.
        foreach ( $candidates as $candidate ) {
            if ( '' !== $candidate && '0' !== $candidate && preg_match( '/^[a-f0-9]{32,64}$/i', $candidate ) ) {
                return 1;
            }
        }

        return 0;
    }

    /* ============================================================
     *  GET-проверка (при загрузке страницы поиска)
     * ============================================================ */

    public function check_search_get_request(): void {
        if ( ! self::active() ) { return; }
        if ( ! $this->has_usable_client() ) { return; }
        if ( empty( $_GET['s'] ) || ! is_string( $_GET['s'] ) ) { return; }
        $search_query = sanitize_text_field( wp_unslash( $_GET['s'] ) );
        if ( mb_strlen( $search_query ) < Query_Parser::MIN_LEN ) { return; }

        $verdict_key = $this->verdict_key( $search_query );
        if ( $verdict_key === null ) { return; }

        $cached = $this->cache->get( $verdict_key );
        if ( is_array( $cached ) && array_key_exists( 'allow', $cached ) ) {
            if ( (int) $cached['allow'] === 0 ) {
                $msg = (string) ( $cached['message'] ?? '' );
                if ( trim( wp_strip_all_tags( $msg ) ) === '' ) {
                    $msg = esc_html__( 'Поиск заблокирован антиспам-сервисом.', 'wp-smart-search-frontend' );
                }
                wp_die( wp_kses_post( $msg ), 403 );
            }
            return;
        }

        if ( ! $this->acquire_api_lock( $verdict_key ) ) {
            return;
        }

        // Защита от исчерпания квоты CleanTalk: если с этого scope
        // за минуту было слишком много API-вызовов — пропускаем
        // проверку (allow), не дергаем API. Это rate-limit, а не
        // решение безопасности.
        if ( ! $this->ct_api_rate_ok( $verdict_key ) ) {
            $this->release_api_lock( $verdict_key );
            return;
        }

        try {
            $js_on = $this->js_on();

            $context = [
                's'     => $search_query,
                'cat'   => isset( $_GET['cat'] )   ? absint( wp_unslash( $_GET['cat'] ) )   : 0,
                'pt'    => isset( $_GET['pt'] )    ? sanitize_key( wp_unslash( $_GET['pt'] ) ) : '',
                'tags'  => isset( $_GET['tags'] )  ? sanitize_text_field( wp_unslash( $_GET['tags'] ) )  : '',
                'xtags' => isset( $_GET['xtags'] ) ? sanitize_text_field( wp_unslash( $_GET['xtags'] ) ) : '',
                'paged' => isset( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1,
            ];

            $post_url = $this->build_search_url( $context );

            $post_info = [
                'comment_type' => 'site_search_wordpress',
                'post_url'     => $post_url,
            ];
            if ( $context['paged'] > 1 ) {
                $post_info['page'] = $context['paged'];
            }

            $ct_result = apbct_base_call( [
                'message'          => $search_query,
                'sender_email'     => '',
                'sender_nickname'  => '',
                'sender_ip'        => $this->security->get_client_ip(),
                'js_on'            => $js_on,
                'post_info'        => $post_info,
                'sender_info'      => [ 'form_name' => 'WP Smart Search (GET)' ],
                'exception_action' => 0,
            ] );

            if ( ! isset( $ct_result['ct_result'] )
                || ! is_object( $ct_result['ct_result'] )
                || ! isset( $ct_result['ct_result']->allow )
            ) {
                return;
            }

            $allow   = (int) $ct_result['ct_result']->allow;
            $comment = isset( $ct_result['ct_result']->comment ) ? (string) $ct_result['ct_result']->comment : '';
            $message = wp_kses_post( $comment );
            if ( trim( wp_strip_all_tags( $message ) ) === '' ) {
                $message = esc_html__( 'Поиск заблокирован антиспам-сервисом.', 'wp-smart-search-frontend' );
            }

            $ttl = $this->verdict_ttl( $allow );
            $this->cache->set(
                $verdict_key,
                [ 'allow' => $allow, 'message' => $message, 'time' => time() ],
                $ttl
            );

            if ( $allow === 0 ) {
                wp_die( $message, 403 );
            }
        } catch ( \Throwable $e ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'error_log' ) ) {
                error_log( '[WSSF] GET CleanTalk error: ' . sanitize_text_field( $e->getMessage() ) );
            }
        } finally {
            $this->release_api_lock( $verdict_key );
        }
    }

    public function add_fields_to_search_form( $form ) {
        if ( ! self::active() || ! function_exists( 'ct_add_hidden_fields' ) ) { return $form; }
        if ( ! is_string( $form ) ) { return $form; }
        if ( strpos( $form, 'ct_checkjs_search_default' ) !== false ) { return $form; }
        $fields = self::form_fields();
        if ( $fields !== '' ) {
            $form = str_replace( '</form>', $fields . '</form>', $form );
        }
        return $form;
    }

    /* ============================================================
     *  AJAX-проверка
     * ============================================================ */

    public function check_ajax( string $raw, array $context = [] ): array {
        $result = [ 'blocked' => false, 'message' => '', 'debug' => [] ];

        if ( ! self::active() ) { return $result; }
        if ( ! $this->has_usable_client() ) { return $result; }
        $skip_logged = (bool) apply_filters( 'wssf_cleantalk_skip_for_logged_in', false );
        if ( $skip_logged && is_user_logged_in() ) { return $result; }

        $has_cache_plugins = function_exists( 'apbct_is_cache_plugins_exists' ) && apbct_is_cache_plugins_exists();

        $strict_opt = Admin_Settings::get_strict_mode();
        if ( $strict_opt === 'on' ) {
            $strict_mode = true;
        } elseif ( $strict_opt === 'off' ) {
            $strict_mode = false;
        } else {
            $strict_mode = ! $has_cache_plugins;
        }
        $strict_mode = (bool) apply_filters( 'wssf_cleantalk_strict_mode', $strict_mode );

        $result['debug']['strict']    = $strict_mode;
        $result['debug']['has_cache'] = $has_cache_plugins;
        $result['debug']['query_hash'] = md5( $raw );
        $result['debug']['time']       = current_time( 'mysql' );

        $verdict_key = $this->verdict_key( $raw );
        $cached      = $verdict_key !== null ? $this->cache->get( $verdict_key ) : null;

        if ( is_array( $cached ) && array_key_exists( 'allow', $cached ) ) {
            $result['debug']['cache_hit'] = true;
            $result['debug']['cache_age'] = time() - (int) ( $cached['time'] ?? 0 );
            if ( (int) $cached['allow'] === 0 ) {
                $result['blocked'] = true;
                $result['message'] = (string) ( $cached['message'] ?? '' );
                if ( $result['message'] === '' ) {
                    $result['message'] = __( 'Поиск заблокирован антиспам-сервисом.', 'wp-smart-search-frontend' );
                }
            }
            return $result;
        }

        if ( $verdict_key !== null && ! $this->acquire_api_lock( $verdict_key ) ) {
            $waited = 0;
            $step   = 50;
            while ( $waited < self::LOCK_WAIT_MS ) {
                usleep( $step * 1000 );
                $waited += $step;

                $cached = $this->cache->get( $verdict_key );
                if ( is_array( $cached ) && array_key_exists( 'allow', $cached ) ) {
                    $result['debug']['cache_hit_after_lock'] = true;
                    $result['debug']['cache_age']            = time() - (int) ( $cached['time'] ?? 0 );
                    if ( (int) $cached['allow'] === 0 ) {
                        $result['blocked'] = true;
                        $result['message'] = (string) ( $cached['message'] ?? '' );
                        if ( $result['message'] === '' ) {
                            $result['message'] = __( 'Поиск заблокирован антиспам-сервисом.', 'wp-smart-search-frontend' );
                        }
                    }
                    return $result;
                }
            }

            $result['debug']['lock_timeout_allow'] = true;
            return $result;
        }

        // Защита от исчерпания квоты CleanTalk: если scope уже сделал
        // слишком много API-вызовов за минуту — пропускаем проверку.
        if ( $verdict_key !== null && ! $this->ct_api_rate_ok( $verdict_key ) ) {
            $this->release_api_lock( $verdict_key );
            $result['debug']['api_rate_limited'] = true;
            return $result;
        }

        $saved_post    = $_POST;
        $saved_cookie  = $_COOKIE;
        $saved_request = $_REQUEST;
        global $cleantalk_executed, $apbct;
        $saved_executed    = $cleantalk_executed;
        $saved_request_ids = is_object( $apbct ) && isset( $apbct->plugin_request_ids ) ? $apbct->plugin_request_ids : null;

        $result['debug']['executed_before'] = (bool) $cleantalk_executed;
        $cleantalk_executed = false;
        if ( is_object( $apbct ) ) { $apbct->plugin_request_ids = []; }

        if ( $strict_mode ) {
            $_POST['apbct_do_not_exclude'] = 1;
            foreach ( [
                'ct_bot_detector_event_token', 'ct_no_cookie_hidden_field',
                'ct_checkjs', 'apbct_visible_fields',
            ] as $k ) { unset( $_POST[ $k ] ); }
            foreach ( [
                'ct_checkjs', 'ct_pointer_data', 'ct_fkp_timestamp', 'ct_ps_timestamp',
                'ct_screen_info', 'ct_timezone', 'ct_has_scrolled', 'ct_mouse_moved',
                'ct_has_key_up', 'ct_has_input_focused', 'ct_bot_detector_event_token',
                'apbct_cookies_test', 'apbct_headless', 'ct_gathering_loaded',
                'apbct_prev_referer', 'apbct_site_landing_ts', 'apbct_page_hits',
                'apbct_alt_session',
            ] as $k ) { unset( $_COOKIE[ $k ] ); }
        }

        $url_context = [
            's'     => $raw,
            'cat'   => isset( $context['cat'] ) ? absint( $context['cat'] ) : 0,
            'pt'    => isset( $context['post_type'] ) ? sanitize_key( $context['post_type'] ) : '',
            'tags'  => isset( $context['tag_ids'] ) && is_array( $context['tag_ids'] )
                ? implode( ',', array_map( 'absint', $context['tag_ids'] ) ) : '',
            'xtags' => isset( $context['exclude_tag_ids'] ) && is_array( $context['exclude_tag_ids'] )
                ? implode( ',', array_map( 'absint', $context['exclude_tag_ids'] ) ) : '',
            'paged' => isset( $context['page'] ) ? max( 1, absint( $context['page'] ) ) : 1,
        ];

        $post_url = $this->build_search_url( $url_context );

        $post_info = [
            'comment_type' => 'site_search_wordpress',
            'post_url'     => $post_url,
        ];
        if ( $url_context['paged'] > 1 ) {
            $post_info['page'] = $url_context['paged'];
        }

        $ct_result = null;
        try {
            $ct_result = apbct_base_call( [
                'message'          => $raw,
                'sender_email'     => '',
                'sender_nickname'  => '',
                'sender_ip'        => $this->security->get_client_ip(),
                'js_on'            => $this->js_on(),
                'post_info'        => $post_info,
                'sender_info'      => [ 'form_name' => 'WP Smart Search' ],
                'exception_action' => 0,
            ] );
        } catch ( \Throwable $e ) {
            $ct_result = null;
            $result['debug']['exception'] = sanitize_text_field( $e->getMessage() );
        } finally {
            $_POST    = $saved_post;
            $_COOKIE  = $saved_cookie;
            $_REQUEST = $saved_request;
            $cleantalk_executed = $saved_executed;
            if ( is_object( $apbct ) && $saved_request_ids !== null ) {
                $apbct->plugin_request_ids = $saved_request_ids;
            }
            if ( $verdict_key !== null ) {
                $this->release_api_lock( $verdict_key );
            }
        }

        if ( is_array( $ct_result ) && isset( $ct_result['ct_result'] ) && is_object( $ct_result['ct_result'] ) ) {
            $allow   = isset( $ct_result['ct_result']->allow )   ? (int) $ct_result['ct_result']->allow   : null;
            $errno   = isset( $ct_result['ct_result']->errno )   ? (int) $ct_result['ct_result']->errno   : null;
            $comment = isset( $ct_result['ct_result']->comment ) ? (string) $ct_result['ct_result']->comment : '';
            $spam    = isset( $ct_result['ct_result']->spam )    ? (int) $ct_result['ct_result']->spam    : null;
            $id      = isset( $ct_result['ct_result']->id )      ? (string) $ct_result['ct_result']->id   : '';
            $codes   = isset( $ct_result['ct_result']->codes ) && is_array( $ct_result['ct_result']->codes )
                ? $ct_result['ct_result']->codes : [];

            $result['debug']['allow']      = $allow;
            $result['debug']['errno']      = $errno;
            $result['debug']['spam']       = $spam;
            $result['debug']['comment']    = $comment;
            $result['debug']['id']         = $id;
            $result['debug']['codes']      = $codes;
            $result['debug']['api_called'] = ( $id !== '' );

            if ( $allow === 0 ) {
                $result['blocked'] = true;
                $result['message'] = sanitize_text_field( $comment );
                if ( $result['message'] === '' ) {
                    $result['message'] = __( 'Поиск заблокирован антиспам-сервисом.', 'wp-smart-search-frontend' );
                }
            }

            if ( $allow !== null && $verdict_key !== null ) {
                $ttl = $this->verdict_ttl( $allow );

                if ( $ttl > 0 ) {
                    $this->cache->set( $verdict_key, [
                        'allow'   => $allow,
                        'message' => $result['message'],
                        'errno'   => $errno,
                        'spam'    => $spam,
                        'api_id'  => $id,
                        'time'    => time(),
                    ], $ttl );
                    $result['debug']['cache_stored'] = true;
                    $result['debug']['cache_ttl']    = $ttl;
                } else {
                    $result['debug']['cache_stored'] = false;
                }
            }
        } else {
            $result['debug']['error'] = isset( $ct_result['error'] ) ? $ct_result['error'] : 'no ct_result';
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'error_log' ) ) {
                error_log( '[WSSF] CleanTalk API error' );
            }
        }

        $result['debug']['cache_hit']   = false;
        $result['debug']['post_keys']   = array_keys( $saved_post );
        $result['debug']['cookie_keys'] = array_keys( array_filter( $saved_cookie, static function ( $k ) {
            return strpos( (string) $k, 'ct_' ) === 0 || strpos( (string) $k, 'apbct' ) === 0;
        }, ARRAY_FILTER_USE_KEY ) );
        $result['debug']['raw_result']  = $ct_result;

        return $result;
    }

    /* ============================================================
     *  Хелперы
     * ============================================================ */

    /**
     * CleanTalk is asked per client: the verdict cache and the API quota are
     * both scoped by it. Without a usable client IP (broken REMOTE_ADDR,
     * CLI) a guest request has no scope, so the check is skipped instead of
     * calling the API on every request without any quota guard.
     */
    private function has_usable_client(): bool {
        if ( is_user_logged_in() ) { return true; }
        return $this->security->get_client_ip() !== '';
    }

    /**
     * Независимый rate-limit на вызовы CleanTalk API.
     * Возвращает true, если можно звать API, false — если лимит
     * исчерпан. Используется параллельно с acquire_api_lock():
     * лок сериализует вызовы, а этот лимит — ограничивает частоту.
     */
    private function ct_api_rate_ok( string $scope ): bool {
        $key = 'wssf_ct_api_rl_' . md5( $scope );

        $max = (int) apply_filters( 'wssf_ct_api_rate_max', self::API_RATE_MAX );
        if ( $max <= 0 ) { return true; }

        // A protective counter, not a cache: it has to work on every site, so
        // it uses a transient of its own instead of the Redis cache backend
        // (which is absent on most installs). Without it a client could call
        // the CleanTalk API on every request and burn the account quota.
        $count = (int) get_transient( $key );
        if ( $count >= $max ) { return false; }

        set_transient( $key, $count + 1, self::API_RATE_WINDOW );
        return true;
    }

    private function verdict_ttl( int $allow ): int {
        $opt_ttl_user  = Admin_Settings::get_ttl_user();
        $opt_ttl_guest = Admin_Settings::get_ttl_guest();

        if ( $opt_ttl_user === 0 && $opt_ttl_guest === 0 ) {
            return 0;
        }

        if ( is_user_logged_in() && $allow === 1 ) {
            return $opt_ttl_user > 0
                ? $opt_ttl_user * MINUTE_IN_SECONDS
                : self::CT_VERDICT_TTL_USER;
        }

        return $opt_ttl_guest > 0
            ? $opt_ttl_guest
            : self::CT_VERDICT_TTL_GUEST;
    }

    private function acquire_api_lock( string $verdict_key ): bool {
        $lock_key = 'wssf_ct_lock_' . md5( $verdict_key );

        if ( wp_using_ext_object_cache() ) {
            return (bool) wp_cache_add( $lock_key, 1, 'wssf_ct_lock', self::API_LOCK_TTL );
        }

        global $wpdb;
        $option_name = '_wssf_ct_lock_' . md5( $verdict_key );
        $expires     = time() + self::API_LOCK_TTL;

        $inserted = $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload)
             VALUES (%s, %d, 'no')",
            $option_name,
            $expires
        ) );

        if ( $inserted > 0 ) {
            return true;
        }

        $existing = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            $option_name
        ) );

        if ( $existing >= time() ) {
            return false;
        }

        $updated = $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->options}
             SET option_value = %d
             WHERE option_name = %s AND option_value < %d",
            $expires,
            $option_name,
            time()
        ) );

        return $updated > 0;
    }

    private function release_api_lock( string $verdict_key ): void {
        $lock_key = 'wssf_ct_lock_' . md5( $verdict_key );

        if ( wp_using_ext_object_cache() ) {
            wp_cache_delete( $lock_key, 'wssf_ct_lock' );
            return;
        }

        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name = %s",
            '_wssf_ct_lock_' . md5( $verdict_key )
        ) );
    }

    private function build_search_url( array $ctx ): string {
        $base = home_url( '/' );
        $args = [];

        if ( ! empty( $ctx['s'] ) ) {
            $args['s'] = $ctx['s'];
        }
        if ( ! empty( $ctx['cat'] ) && (int) $ctx['cat'] > 0 ) {
            $args['cat'] = (int) $ctx['cat'];
        }
        if ( ! empty( $ctx['pt'] ) && in_array( $ctx['pt'], Search_Engine::get_post_types(), true ) ) {
            $args['pt'] = $ctx['pt'];
        }
        if ( ! empty( $ctx['tags'] ) )  { $args['tags']  = $ctx['tags']; }
        if ( ! empty( $ctx['xtags'] ) ) { $args['xtags'] = $ctx['xtags']; }
        if ( ! empty( $ctx['paged'] ) && (int) $ctx['paged'] > 1 ) {
            $args['paged'] = (int) $ctx['paged'];
        }

        if ( empty( $args ) ) { return $base; }
        return add_query_arg( $args, $base );
    }

    private function verdict_key( string $raw_query ): ?string {
        $user_id = get_current_user_id();
        if ( $user_id > 0 ) {
            $scope = 'u' . $user_id;
        } else {
            $ip = $this->security->get_client_ip();
            if ( $ip === '' ) { return null; }
            $scope = 'ip' . $ip;
        }
        $norm = Query_Parser::sanitize( $raw_query );
        return 'wssf_ct_' . md5( $scope . '|' . $norm );
    }
}