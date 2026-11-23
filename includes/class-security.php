<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Request validation for the public search surface.
 *
 * Two independent layers protect the AJAX endpoints:
 *
 *  1. Nonce — CSRF only. A guest nonce is printed on the public search page
 *     and is therefore available to anyone who loads it; it is not a
 *     capability and it must not be treated as one. It only proves that the
 *     request was assembled by a page of this site, and it can be replayed
 *     for the life of the nonce (12–24 h).
 *  2. Rate limiter — the actual abuse control: 30 requests/minute per guest
 *     IP, 300/minute per logged-in user. The counter is kept in Redis when it
 *     is available and in a TTL-bounded transient otherwise.
 *
 * The client IP is taken from REMOTE_ADDR. Forwarding headers are read only
 * when REMOTE_ADDR is listed in the `wssf_trusted_proxies` filter, and then
 * the chain is walked from the right, so a value the client put into
 * X-Forwarded-For itself is never trusted.
 */
final class WSSF_Security {

    private const RATE_LIMIT_GUEST  = 30;
    private const RATE_LIMIT_WINDOW = 60;

    /**
     * Elevated limit for a logged-in user on the community theme.
     *
     * The elevation exists for WPCommunity (WPShop): a community site with
     * subscriptions, where a signed-in visitor is expected to search a lot.
     * It is bound to the theme, not to authentication alone — a logged-in
     * user on any other theme gets the guest limit.
     */
    private const RATE_LIMIT_USER = 300;

    /**
     * Limit for a logged-in user on a theme that is not the community theme.
     *
     * Separate constant on purpose: it equals the guest limit today, but the
     * owner can raise it (or a filter can) without touching the guest limit.
     */
    private const RATE_LIMIT_USER_FOREIGN = 30;

    /** Default theme slug the elevated limit belongs to. */
    private const COMMUNITY_THEME = 'wpcommunity';

    /**
     * Bucket for requests whose client IP cannot be determined. Sharing one
     * bucket keeps the limiter closed instead of switching it off: a request
     * without a usable REMOTE_ADDR must not be an unlimited request.
     */
    private const UNKNOWN_CLIENT_KEY = 'wssf_rl_unknown';

    /** Theme detection result of the current request; see is_community_theme(). */
    private static ?bool $community_theme = null;

    public function __construct( private WSSF_Cache $cache ) {}

    public function verify_request(): bool {
        $xrw = strtolower( $this->server_string( 'HTTP_X_REQUESTED_WITH' ) );

        $nonce = isset( $_POST[ WSSF_Plugin::NONCE_FIELD ] ) && is_string( $_POST[ WSSF_Plugin::NONCE_FIELD ] )
            ? sanitize_text_field( wp_unslash( $_POST[ WSSF_Plugin::NONCE_FIELD ] ) ) : '';

        if ( $xrw === 'xmlhttprequest' ) {
            if ( ! check_ajax_referer( WSSF_Plugin::NONCE_ACTION, WSSF_Plugin::NONCE_FIELD, false ) ) {
                return false;
            }
            $site_host = wp_parse_url( home_url(), PHP_URL_HOST );
            if ( ! $site_host ) { return false; }

            $source = $this->server_string( 'HTTP_ORIGIN' );
            if ( $source === '' ) { $source = $this->server_string( 'HTTP_REFERER' ); }
            if ( $source === '' ) { return false; }

            $source_host = wp_parse_url( $source, PHP_URL_HOST );
            if ( ! $source_host ) { return false; }

            $site_host   = (string) preg_replace( '/^www\./i', '', $site_host );
            $source_host = (string) preg_replace( '/^www\./i', '', $source_host );
            return strcasecmp( $site_host, $source_host ) === 0;
        }

        return $nonce !== '' && (bool) wp_verify_nonce( $nonce, WSSF_Plugin::NONCE_ACTION );
    }

    /**
     * SEC-001 A09: one line per security event, no secrets.
     *
     * Events: nonce_failed, cap_denied, object_denied, github_rate_limit,
     * github_unauthorized, github_forbidden. No e-mail, IP or request content.
     *
     * @param string $event   Event name from the vocabulary above.
     * @param string $context Short handler label, e.g. "ajax:search".
     */
    public static function log_event( string $event, string $context = '' ): void {
        if ( ! function_exists( 'error_log' ) ) { return; }

        $line = '[wssf] security: ' . $event;

        if ( $context !== '' ) {
            $line .= ' ' . str_replace( [ "\r", "\n" ], ' ', $context );
        }

        error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- A09 security event, no secrets.
    }

    /**
     * A $_SERVER value as a sanitized string; empty when absent or not scalar.
     */
    private function server_string( string $key ): string {
        if ( ! isset( $_SERVER[ $key ] ) ) { return ''; }
        $raw = wp_unslash( $_SERVER[ $key ] );
        return is_string( $raw ) ? sanitize_text_field( $raw ) : '';
    }

    public function rate_limit_ok(): bool {
        [ $key, $max ] = $this->rate_limit_key_and_max();
        if ( $key === '' || $max <= 0 ) { return true; }

        $count = $this->rate_limit_incr( $key, self::RATE_LIMIT_WINDOW );
        return $count <= $max;
    }

    /**
     * Increments the request counter and returns the new value.
     *
     * Redis is used when the cache backend is up (atomic INCR + EXPIRE), so
     * parallel requests cannot lose increments. Without Redis the counter is
     * a transient with a real TTL.
     *
     * The previous implementation kept the counter in a wp_options row and
     * reset it with `IF ( value < time() + window … )` — the value could never
     * reach that bound, so a guest stayed blocked forever after 30 requests
     * and every counted IP left a permanent row behind.
     */
    private function rate_limit_incr( string $key, int $window ): int {
        $window = max( 1, $window );

        $count = $this->cache->rate_incr( $key, $window );
        if ( $count !== null ) { return $count; }

        $transient = 'wssf_rl_' . md5( $key );
        $count     = (int) get_transient( $transient );
        $count++;
        set_transient( $transient, $count, $window );

        return $count;
    }

    public function get_client_ip(): string {
        $remote_addr = $this->server_string( 'REMOTE_ADDR' );

        if ( ! filter_var( $remote_addr, FILTER_VALIDATE_IP ) ) {
            return '';
        }

        $trusted_proxies = $this->trusted_proxies();

        if ( in_array( $remote_addr, $trusted_proxies, true ) ) {
            $real_ip = trim( $this->server_string( 'HTTP_X_REAL_IP' ) );
            if ( filter_var( $real_ip, FILTER_VALIDATE_IP ) ) {
                return $this->normalize_ip( $real_ip );
            }

            $forwarded = $this->server_string( 'HTTP_X_FORWARDED_FOR' );
            if ( $forwarded !== '' ) {
                $parts = array_reverse( explode( ',', $forwarded ) );
                foreach ( $parts as $candidate ) {
                    $candidate = trim( $candidate );
                    if ( ! filter_var( $candidate, FILTER_VALIDATE_IP ) ) { continue; }
                    // Skip the trusted proxies of our own chain: the first
                    // non-proxy hop from the right is the real client. The
                    // left-most entry is whatever the client sent.
                    if ( in_array( $candidate, $trusted_proxies, true ) ) { continue; }
                    return $this->normalize_ip( $candidate );
                }
            }
        }

        return $this->normalize_ip( $remote_addr );
    }

    /**
     * Proxies whose forwarding headers may be believed.
     *
     * Empty by default: a site that is not behind a reverse proxy must never
     * trust X-Forwarded-For / X-Real-IP, otherwise any visitor could pick its
     * own rate-limit bucket by sending the header.
     *
     * @return string[]
     */
    private function trusted_proxies(): array {
        $list = (array) apply_filters( 'wssf_trusted_proxies', [] );

        $out = [];
        foreach ( $list as $proxy ) {
            if ( ! is_string( $proxy ) ) { continue; }
            $proxy = trim( $proxy );
            if ( $proxy === '' || ! filter_var( $proxy, FILTER_VALIDATE_IP ) ) { continue; }
            $out[] = $proxy;
        }

        return $out;
    }

    private function normalize_ip( string $ip ): string {
        if ( strpos( $ip, ':' ) !== false ) {
            $packed = @inet_pton( $ip );
            if ( $packed !== false ) {
                $hex = bin2hex( $packed );
                $hex = substr( $hex, 0, 16 ) . str_repeat( '0', 16 );
                $normalized = @inet_ntop( (string) hex2bin( $hex ) );
                if ( is_string( $normalized ) ) { return $normalized; }
            }
        }
        return $ip;
    }

    /**
     * Whether the active theme is the community theme (or a child of it).
     *
     * The only source is `get_template()` — the parent theme slug of the
     * active theme. A child theme of WPCommunity returns "wpcommunity" too,
     * which is what we want: the community features come from the parent.
     * Nothing from the request takes part: no cookie, no header, no option,
     * so a visitor cannot talk the limiter into the elevated limit.
     *
     * `wssf_rate_limit_theme_slug` lets the owner point the elevation at a
     * renamed or forked theme without touching the code.
     */
    public function is_community_theme(): bool {
        if ( self::$community_theme !== null ) {
            return self::$community_theme;
        }

        $expected = strtolower( trim( (string) apply_filters( 'wssf_rate_limit_theme_slug', self::COMMUNITY_THEME ) ) );

        $template = '';
        if ( function_exists( 'get_template' ) ) {
            $template = (string) get_template();
        }

        // Fallback for early requests: wp_get_theme()->get_template() is the
        // same value as get_template().
        if ( $template === '' && function_exists( 'wp_get_theme' ) ) {
            $theme = wp_get_theme();
            if ( is_object( $theme ) && method_exists( $theme, 'get_template' ) ) {
                $template = (string) $theme->get_template();
            }
        }

        $template = strtolower( trim( $template ) );

        self::$community_theme = ( $expected !== '' && $template !== '' && $template === $expected );

        return self::$community_theme;
    }

    /**
     * The request limit that applies to the current visitor.
     *
     * Guests always get the guest limit, whatever the theme. A logged-in user
     * gets the elevated limit only on the community theme; on any other theme
     * the foreign-theme limit applies. `wssf_rate_limit_max` overrides the
     * result (receives the limit, whether the visitor is logged in, and
     * whether the community theme was recognised).
     */
    public function effective_rate_limit(): int {
        $is_user      = get_current_user_id() > 0;
        $community    = $this->is_community_theme();

        if ( $is_user ) {
            $max = $community ? self::RATE_LIMIT_USER : self::RATE_LIMIT_USER_FOREIGN;
        } else {
            $max = self::RATE_LIMIT_GUEST;
        }

        $filtered = (int) apply_filters( 'wssf_rate_limit_max', $max, $is_user, $community );

        return max( 0, $filtered );
    }

    /**
     * @return array{0:string,1:int} Counter key and its limit.
     */
    private function rate_limit_key_and_max(): array {
        $max = $this->effective_rate_limit();

        $user_id = get_current_user_id();
        if ( $user_id > 0 ) {
            return [ 'wssf_rl_u_' . $user_id, $max ];
        }

        $ip = $this->get_client_ip();
        if ( $ip === '' ) {
            return [ self::UNKNOWN_CLIENT_KEY, $max ];
        }

        return [ 'wssf_rl_ip_' . md5( $ip ), $max ];
    }
}
