<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class WSSF_Cache {

    private const CACHE_TTL        = 86400;
    private const CACHE_GEN_OPTION = 'wssf_cache_gen';

    private static ?\Redis $redis_instance = null;
    private static ?bool   $redis_ready    = null;
    private static ?string $redis_ns       = null;

    public static function default_ttl(): int { return self::CACHE_TTL; }

    public function get( string $key ): ?array {
        if ( $this->redis_available() && self::$redis_instance !== null ) {
            try {
                $raw = self::$redis_instance->get( $this->redis_key( $key ) );
                if ( is_string( $raw ) && $raw !== '' ) {
                    $data = @unserialize( $raw, [ 'allowed_classes' => false ] );
                    if ( is_array( $data ) ) { return $data; }
                }
                return null;
            } catch ( \Throwable $e ) { /* fallback */ }
        }
        $value = get_transient( $key );
        return is_array( $value ) ? $value : null;
    }

    public function set( string $key, array $value, ?int $ttl = null ): void {
        $ttl = $ttl ?? self::CACHE_TTL;
        if ( $this->redis_available() && self::$redis_instance !== null ) {
            try {
                $ok = self::$redis_instance->setex( $this->redis_key( $key ), $ttl, serialize( $value ) );
                if ( $ok ) { return; }
            } catch ( \Throwable $e ) { /* fallback */ }
        }
        set_transient( $key, $value, $ttl );
    }

    public function delete( string $key ): void {
        if ( $this->redis_available() && self::$redis_instance !== null ) {
            try {
                self::$redis_instance->del( $this->redis_key( $key ) );
            } catch ( \Throwable $e ) { /* ignore */ }
        }
        delete_transient( $key );
    }

    public function delete_transients_by_prefix( string $prefix ): int {
        if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', $prefix ) ) {
            return 0;
        }

        $count = 0;

        if ( $this->redis_available() && self::$redis_instance !== null ) {
            try {
                $pattern = $this->redis_key( $prefix ) . '*';
                $iterator = null;
                if ( method_exists( self::$redis_instance, 'scan' ) ) {
                    self::$redis_instance->setOption( \Redis::OPT_SCAN, \Redis::SCAN_RETRY );
                    while ( false !== ( $keys = self::$redis_instance->scan( $iterator, $pattern, 200 ) ) ) {
                        if ( ! is_array( $keys ) || empty( $keys ) ) { break; }
                        foreach ( $keys as $k ) {
                            if ( self::$redis_instance->del( $k ) ) { $count++; }
                        }
                        if ( (int) $iterator === 0 ) { break; }
                    }
                }
            } catch ( \Throwable $e ) { /* ignore */ }
        }

        global $wpdb;
        $like         = '_transient_' . $wpdb->esc_like( $prefix ) . '%';
        $like_timeout = '_transient_timeout_' . $wpdb->esc_like( $prefix ) . '%';

        try {
            $deleted = (int) $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->options}
                 WHERE option_name LIKE %s OR option_name LIKE %s",
                $like,
                $like_timeout
            ) );
            $count += $deleted;
        } catch ( \Throwable $e ) { /* ignore */ }

        return $count;
    }

    public function delete_options_by_prefix( string $prefix ): int {
        if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', $prefix ) ) {
            return 0;
        }

        global $wpdb;
        try {
            return (int) $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like( $prefix ) . '%'
            ) );
        } catch ( \Throwable $e ) {
            return 0;
        }
    }

    public function flush_rate_limit_group(): int {
        $count = 0;

        if ( wp_using_ext_object_cache() && function_exists( 'wp_cache_flush_group' ) ) {
            try {
                wp_cache_flush_group( 'wssf_rate_limit' );
                $count++;
            } catch ( \Throwable $e ) { /* ignore */ }
        }

        $count += $this->delete_options_by_prefix( '_wssf_rl_' );

        return $count;
    }

    public function generation(): int {
        return (int) get_option( self::CACHE_GEN_OPTION, 0 );
    }

    public function bump_generation(): void {
        $v = (int) get_option( self::CACHE_GEN_OPTION, 0 );
        update_option( self::CACHE_GEN_OPTION, $v + 1, true );
    }

    public function rate_incr( string $key, int $window ): ?int {
        if ( ! $this->redis_available() || self::$redis_instance === null ) { return null; }
        try {
            $redis_key = $this->redis_key( 'rl:' . md5( $key ) );
            $count     = (int) self::$redis_instance->incr( $redis_key );
            if ( $count === 1 ) { self::$redis_instance->expire( $redis_key, $window ); }
            return $count;
        } catch ( \Throwable $e ) { return null; }
    }

    /* ============================================================
     *  Redis версии (для диагностики и статуса)
     * ============================================================ */

    /**
     * Версия Redis-сервера (например "7.2.4").
     * Пустая строка, если Redis недоступен.
     */
    public function redis_server_version(): string {
        if ( ! $this->redis_available() || self::$redis_instance === null ) {
            return '';
        }
        try {
            $info = self::$redis_instance->info( 'server' );
            if ( is_array( $info ) && isset( $info['redis_version'] ) ) {
                return (string) $info['redis_version'];
            }
        } catch ( \Throwable $e ) { /* ignore */ }
        return '';
    }

    /**
     * Версия PHP-расширения phpredis (не путать с версией сервера).
     * Не требует соединения.
     */
    public static function redis_client_version(): string {
        if ( ! extension_loaded( 'redis' ) ) { return ''; }
        if ( defined( 'Redis::VERSION' ) ) {
            return (string) constant( 'Redis::VERSION' );
        }
        $v = phpversion( 'redis' );
        return is_string( $v ) ? $v : '';
    }

    /* ============================================================
     *  Внутренние
     * ============================================================ */

    private function redis_available(): bool {
        if ( self::$redis_ready !== null ) { return self::$redis_ready; }
        if ( ! extension_loaded( 'redis' ) || ! class_exists( '\Redis' ) ) { self::$redis_ready = false; return false; }
        $config = $this->get_redis_config();
        if ( ! $config ) { self::$redis_ready = false; return false; }
        try {
            $redis = new \Redis();
            if ( ! @$redis->connect( $config['host'], (int) $config['port'], (float) $config['timeout'] ) ) { self::$redis_ready = false; return false; }
            if ( $config['password'] !== '' && ! @$redis->auth( (string) $config['password'] ) ) { self::$redis_ready = false; return false; }
            if ( (int) $config['database'] > 0 && ! @$redis->select( (int) $config['database'] ) ) { self::$redis_ready = false; return false; }
            self::$redis_instance = $redis; self::$redis_ready = true; return true;
        } catch ( \Throwable $e ) { self::$redis_ready = false; return false; }
    }

    private function get_redis_config(): ?array {
        $defaults = [ 'host' => '127.0.0.1', 'port' => 6379, 'timeout' => 0.1, 'password' => '', 'database' => 0 ];
        $wp = [
            'host' => defined('WP_REDIS_HOST') ? (string) WP_REDIS_HOST : null,
            'port' => defined('WP_REDIS_PORT') ? (int) WP_REDIS_PORT : null,
            'timeout' => defined('WP_REDIS_TIMEOUT') ? (float) WP_REDIS_TIMEOUT : null,
            'password' => defined('WP_REDIS_PASSWORD') ? (string) WP_REDIS_PASSWORD : null,
            'database' => defined('WP_REDIS_DATABASE') ? (int) WP_REDIS_DATABASE : null,
        ];
        $wss = [
            'host' => defined('WSS_REDIS_HOST') ? (string) WSS_REDIS_HOST : null,
            'port' => defined('WSS_REDIS_PORT') ? (int) WSS_REDIS_PORT : null,
            'timeout' => defined('WSS_REDIS_TIMEOUT') ? (float) WSS_REDIS_TIMEOUT : null,
            'password' => defined('WSS_REDIS_PASSWORD') ? (string) WSS_REDIS_PASSWORD : null,
            'database' => defined('WSS_REDIS_DATABASE') ? (int) WSS_REDIS_DATABASE : null,
        ];
        $config = [];
        foreach ( $defaults as $key => $default_val ) {
            if ( $wp[$key] !== null ) $config[$key] = $wp[$key];
            elseif ( $wss[$key] !== null ) $config[$key] = $wss[$key];
            else $config[$key] = $default_val;
        }
        $config = apply_filters( 'wssf_redis_config', $config );
        if ( ! is_array( $config ) || empty( $config['host'] ) || empty( $config['port'] ) ) return null;
        return [
            'host' => (string) $config['host'], 'port' => (int) $config['port'],
            'timeout' => isset($config['timeout']) ? (float) $config['timeout'] : 0.1,
            'password' => isset($config['password']) ? (string) $config['password'] : '',
            'database' => isset($config['database']) ? (int) $config['database'] : 0,
        ];
    }

    private function redis_ns(): string {
        if ( self::$redis_ns === null ) self::$redis_ns = 'wssf:' . substr( md5( home_url() ), 0, 12 ) . ':';
        return self::$redis_ns;
    }

    private function redis_key( string $key ): string {
        return $this->redis_ns() . $key;
    }
}