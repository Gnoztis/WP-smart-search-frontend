<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class WSSF_Security {

    private const RATE_LIMIT_GUEST  = 30;
    private const RATE_LIMIT_USER   = 300;
    private const RATE_LIMIT_WINDOW = 60;

    public function __construct( private WSSF_Cache $cache ) {}

    public function verify_request(): bool {
        $xrw = isset( $_SERVER['HTTP_X_REQUESTED_WITH'] )
            ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REQUESTED_WITH'] ) ) ) : '';

        $nonce = isset( $_POST[ WSSF_Plugin::NONCE_FIELD ] )
            ? sanitize_text_field( wp_unslash( $_POST[ WSSF_Plugin::NONCE_FIELD ] ) ) : '';

        if ( $xrw === 'xmlhttprequest' ) {
            if ( ! check_ajax_referer( WSSF_Plugin::NONCE_ACTION, WSSF_Plugin::NONCE_FIELD, false ) ) {
                return false;
            }
            $site_host = wp_parse_url( home_url(), PHP_URL_HOST );
            if ( ! $site_host ) { return false; }

            $source = '';
            if ( ! empty( $_SERVER['HTTP_ORIGIN'] ) ) {
                $source = sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) );
            } elseif ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
                $source = sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) );
            }
            if ( $source === '' ) { return false; }

            $source_host = wp_parse_url( $source, PHP_URL_HOST );
            if ( ! $source_host ) { return false; }

            $site_host   = preg_replace( '/^www\./i', '', $site_host );
            $source_host = preg_replace( '/^www\./i', '', $source_host );
            return strcasecmp( $site_host, $source_host ) === 0;
        }

        return $nonce !== '' && (bool) wp_verify_nonce( $nonce, WSSF_Plugin::NONCE_ACTION );
    }

    public function rate_limit_ok(): bool {
        [ $key, $max ] = $this->rate_limit_key_and_max();
        if ( $key === '' || $max <= 0 ) { return true; }

        $count = $this->rate_limit_incr( $key, self::RATE_LIMIT_WINDOW );
        return $count <= $max;
    }

    /**
     * Атомарный инкремент счётчика.
     *  - Объектный кеш (Redis/Memcached) → wp_cache_incr.
     *  - Fallback → атомарный INSERT … ON DUPLICATE KEY UPDATE в wp_options.
     * Это устраняет гонку get→set при параллельных запросах (аудит №19).
     */
    private function rate_limit_incr( string $key, int $window ): int {
        if ( wp_using_ext_object_cache() ) {
            $group = 'wssf_rate_limit';
            $count = wp_cache_incr( $key, 1, $group );
            if ( false === $count ) {
                wp_cache_set( $key, 1, $group, $window );
                $count = 1;
            }
            return (int) $count;
        }

        global $wpdb;
        $option_name = '_wssf_rl_' . md5( $key );
        $expires     = time() + $window;

        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
             VALUES (%s, 1, 'no')
             ON DUPLICATE KEY UPDATE
                option_value = IF( CAST(option_value AS UNSIGNED) < %d, CAST(option_value AS UNSIGNED) + 1, 1 )",
            $option_name,
            $expires
        ) );

        $value = $wpdb->get_var( $wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            $option_name
        ) );

        return (int) $value;
    }

    public function get_client_ip(): string {
        $remote_addr = isset( $_SERVER['REMOTE_ADDR'] )
            ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
            : '';

        if ( ! filter_var( $remote_addr, FILTER_VALIDATE_IP ) ) {
            return '';
        }

        $trusted_proxies = (array) apply_filters( 'wssf_trusted_proxies', [] );

        if ( in_array( $remote_addr, $trusted_proxies, true ) ) {
            if ( ! empty( $_SERVER['HTTP_X_REAL_IP'] ) ) {
                $real_ip = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REAL_IP'] ) );
                if ( filter_var( $real_ip, FILTER_VALIDATE_IP ) ) {
                    return $this->normalize_ip( $real_ip );
                }
            }
            if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
                $raw = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
                $parts = explode( ',', $raw );
                $first = trim( $parts[0] );
                if ( filter_var( $first, FILTER_VALIDATE_IP ) ) {
                    return $this->normalize_ip( $first );
                }
            }
        }

        return $this->normalize_ip( $remote_addr );
    }

    private function normalize_ip( string $ip ): string {
        if ( strpos( $ip, ':' ) !== false ) {
            $packed = @inet_pton( $ip );
            if ( $packed !== false ) {
                $hex = bin2hex( $packed );
                $hex = substr( $hex, 0, 16 ) . str_repeat( '0', 16 );
                $normalized = @inet_ntop( hex2bin( $hex ) );
                if ( $normalized !== false ) { return $normalized; }
            }
        }
        return $ip;
    }

    private function rate_limit_key_and_max(): array {
        $user_id = get_current_user_id();
        if ( $user_id > 0 ) {
            return [ 'wssf_rl_u_' . $user_id, self::RATE_LIMIT_USER ];
        }
        $ip = $this->get_client_ip();
        if ( $ip === '' ) { return [ '', 0 ]; }
        return [ 'wssf_rl_ip_' . md5( $ip ), self::RATE_LIMIT_GUEST ];
    }
}