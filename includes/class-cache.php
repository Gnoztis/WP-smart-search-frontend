<?php
declare(strict_types=1);

namespace WSSF;

if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Cache storage for search results.
 *
 * The only backend is the PHP redis extension (phpredis) over a direct
 * connection: no object-cache plugin, no drop-in, no transients. Transients
 * live in wp_options, so using them as a cache costs more than the query they
 * are supposed to save — the search works without cache instead.
 *
 * Resolution, lazily on the first cache operation:
 *
 *   1. redis — extension_loaded( 'redis' ) is true and the server answers;
 *   2. none  — the extension is missing or the server does not answer.
 *
 * A failed connection or a failed command marks the backend as "none" for the
 * rest of the request and stores `wssf_redis_down_until` (autoloaded option),
 * so a dead Redis costs at most one connection attempt per RETRY_TTL seconds.
 *
 * No method throws: every Redis call is wrapped in try/catch ( Throwable ).
 *
 * Rate-limit counters are the one exception to "no transients": a limiter
 * without storage would silently let every request through, so when Redis is
 * unavailable the counter lives in a TTL-bounded transient (see WSSF_Security).
 */
final class Cache {

    private const CACHE_TTL        = 86400;
    private const CACHE_GEN_OPTION = 'wssf_cache_gen';

    /** Autoloaded option: unix time until which Redis is not probed. */
    private const DOWN_OPTION = 'wssf_redis_down_until';

    /** Seconds to skip Redis after a failed connection attempt. */
    private const RETRY_TTL = 60;

    /** Upper bound for a single connect() call, seconds. */
    private const CONNECT_TIMEOUT = 0.2;

    /** Redis backend: 'redis' or 'none'. Resolved once per request. */
    private static ?string $backend = null;

    private static ?\Redis $redis = null;

    /** Key namespace, shared by every instance of the request. */
    private static ?string $ns = null;

    public static function default_ttl(): int { return self::CACHE_TTL; }

    /* ============================================================
     *  Backend resolution
     * ============================================================ */

    /**
     * Active backend: 'redis' or 'none'.
     */
    public static function backend(): string {
        if ( self::$backend !== null ) {
            return self::$backend;
        }

        if ( self::redis_client() instanceof \Redis ) {
            self::$backend = 'redis';
            return self::$backend;
        }

        self::$backend = 'none';
        return self::$backend;
    }

    public static function is_available(): bool {
        return self::backend() === 'redis';
    }

    /**
     * Unix time until which Redis is not probed; 0 when no backoff is active.
     */
    public static function backoff_until(): int {
        return (int) get_option( self::DOWN_OPTION, 0 );
    }

    /**
     * Lazily connects to Redis, honouring the failure backoff.
     */
    private static function redis_client(): ?\Redis {
        if ( self::$redis instanceof \Redis ) {
            return self::$redis;
        }

        if ( ! extension_loaded( 'redis' ) || ! class_exists( '\Redis' ) ) {
            return null;
        }

        if ( self::backoff_until() > time() ) {
            return null;
        }

        $config = self::redis_config();
        if ( $config === null ) {
            return null;
        }

        try {
            $client = new \Redis();
            if ( ! @$client->connect( $config['host'], $config['port'], $config['timeout'] ) ) {
                self::mark_down( 'connect() returned false' );
                return null;
            }
            if ( $config['password'] !== '' && ! @$client->auth( $config['password'] ) ) {
                self::mark_down( 'AUTH failed' );
                return null;
            }
            if ( $config['database'] > 0 && ! @$client->select( $config['database'] ) ) {
                self::mark_down( 'SELECT failed' );
                return null;
            }

            self::$redis = $client;
            return $client;
        } catch ( \Throwable $e ) {
            self::mark_down( $e->getMessage() );
            return null;
        }
    }

    /**
     * Records a failed Redis operation: backend becomes 'none' for the rest
     * of the request and probing pauses for RETRY_TTL seconds.
     */
    private static function mark_down( string $reason = '' ): void {
        self::$redis   = null;
        self::$backend = 'none';

        try {
            update_option( self::DOWN_OPTION, time() + self::RETRY_TTL, true );
        } catch ( \Throwable $e ) { /* an option write must never break a search */ }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG && $reason !== '' && function_exists( 'error_log' ) ) {
            error_log( '[WSSF] cache backend unavailable: ' . sanitize_text_field( $reason ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- WP_DEBUG only.
        }
    }

    /**
     * Connection settings.
     *
     * Precedence: WSSF_REDIS_* → WP_REDIS_* (shared with object-cache
     * plugins) → WSS_REDIS_* (the backend plugin of this project) → defaults,
     * and finally the `wssf_redis_config` filter.
     *
     * @return array{host:string,port:int,timeout:float,password:string,database:int}|null
     */
    private static function redis_config(): ?array {
        $config = [
            'host'     => '127.0.0.1',
            'port'     => 6379,
            'timeout'  => self::CONNECT_TIMEOUT,
            'password' => '',
            'database' => 0,
        ];

        $map = [
            'host'     => [ 'WSSF_REDIS_HOST', 'WP_REDIS_HOST', 'WSS_REDIS_HOST' ],
            'port'     => [ 'WSSF_REDIS_PORT', 'WP_REDIS_PORT', 'WSS_REDIS_PORT' ],
            'timeout'  => [ 'WSSF_REDIS_TIMEOUT', 'WP_REDIS_TIMEOUT', 'WSS_REDIS_TIMEOUT' ],
            'password' => [ 'WSSF_REDIS_PASSWORD', 'WP_REDIS_PASSWORD', 'WSS_REDIS_PASSWORD' ],
            'database' => [ 'WSSF_REDIS_DATABASE', 'WP_REDIS_DATABASE', 'WSS_REDIS_DATABASE' ],
        ];

        foreach ( $map as $key => $constants ) {
            foreach ( $constants as $constant ) {
                if ( ! defined( $constant ) ) { continue; }
                $config[ $key ] = constant( $constant );
                break;
            }
        }

        $config = apply_filters( 'wssf_redis_config', $config );
        if ( ! is_array( $config ) ) {
            return null;
        }

        $host = isset( $config['host'] ) ? trim( (string) $config['host'] ) : '';
        $port = isset( $config['port'] ) ? (int) $config['port'] : 0;
        if ( $host === '' || $port <= 0 || $port > 65535 ) {
            return null;
        }

        $timeout = isset( $config['timeout'] ) ? (float) $config['timeout'] : self::CONNECT_TIMEOUT;
        if ( $timeout <= 0 ) { $timeout = self::CONNECT_TIMEOUT; }

        return [
            'host'     => $host,
            'port'     => $port,
            'timeout'  => $timeout,
            'password' => isset( $config['password'] ) ? (string) $config['password'] : '',
            'database' => isset( $config['database'] ) ? max( 0, (int) $config['database'] ) : 0,
        ];
    }

    /**
     * Key namespace: `wssf:{hash}` over home URL, database name and table
     * prefix.
     *
     * home_url() alone is not enough — a staging clone keeps the production
     * URL — so the database name and the table prefix are part of the hash
     * too. Otherwise two installs that share one Redis database would serve
     * each other's cached results.
     */
    private function redis_ns(): string {
        if ( self::$ns === null ) {
            global $wpdb;

            $site = home_url( '/' )
                . '|' . ( defined( 'DB_NAME' ) ? (string) DB_NAME : '' )
                . '|' . ( is_object( $wpdb ) ? (string) $wpdb->prefix : '' );

            self::$ns = 'wssf:' . substr( md5( $site ), 0, 12 ) . ':';
        }

        return self::$ns;
    }

    private function redis_key( string $key ): string {
        return $this->redis_ns() . $key;
    }

    /* ============================================================
     *  Read / write
     * ============================================================ */

    /**
     * Cached array or null on miss. Never throws: a cache failure must not
     * break the search request.
     */
    public function get( string $key ): ?array {
        if ( self::backend() !== 'redis' || ! self::$redis instanceof \Redis ) {
            return null;
        }

        try {
            $raw = self::$redis->get( $this->redis_key( $key ) );
            if ( ! is_string( $raw ) || $raw === '' ) {
                return null;
            }
            $data = @unserialize( $raw, [ 'allowed_classes' => false ] );
            return is_array( $data ) ? $data : null;
        } catch ( \Throwable $e ) {
            self::mark_down( $e->getMessage() );
            return null;
        }
    }

    /**
     * Stores an array. Caching is optional, so a failure is silent.
     */
    public function set( string $key, array $value, ?int $ttl = null ): void {
        $ttl = max( 1, (int) ( $ttl ?? self::CACHE_TTL ) );

        if ( self::backend() !== 'redis' || ! self::$redis instanceof \Redis ) {
            return;
        }

        try {
            self::$redis->setex( $this->redis_key( $key ), $ttl, serialize( $value ) );
        } catch ( \Throwable $e ) {
            self::mark_down( $e->getMessage() );
        }
    }

    public function delete( string $key ): void {
        if ( self::backend() !== 'redis' || ! self::$redis instanceof \Redis ) {
            return;
        }

        try {
            self::$redis->del( $this->redis_key( $key ) );
        } catch ( \Throwable $e ) {
            self::mark_down( $e->getMessage() );
        }
    }

    /**
     * Deletes every cache entry whose key starts with $prefix.
     *
     * Redis is swept with SCAN (never KEYS: KEYS blocks the server on a large
     * database). The wp_options sweep removes cache rows written by plugin
     * versions before 5.4, which used transients.
     */
    public function delete_transients_by_prefix( string $prefix ): int {
        if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', $prefix ) ) {
            return 0;
        }

        $count = 0;

        if ( self::backend() === 'redis' && self::$redis instanceof \Redis ) {
            try {
                $pattern  = $this->redis_key( $prefix ) . '*';
                $iterator = null;
                if ( method_exists( self::$redis, 'scan' ) ) {
                    self::$redis->setOption( \Redis::OPT_SCAN, \Redis::SCAN_RETRY );
                    while ( false !== ( $keys = self::$redis->scan( $iterator, $pattern, 500 ) ) ) {
                        if ( is_array( $keys ) ) {
                            foreach ( $keys as $k ) {
                                if ( self::$redis->del( $k ) ) { $count++; }
                            }
                        }
                        if ( 0 === (int) $iterator ) { break; }
                    }
                }
            } catch ( \Throwable $e ) {
                self::mark_down( $e->getMessage() );
            }
        }

        $count += $this->delete_legacy_transient_rows( $prefix );

        return $count;
    }

    /**
     * Cache rows of pre-5.4 versions: `_transient_<prefix>*` in wp_options.
     */
    private function delete_legacy_transient_rows( string $prefix ): int {
        global $wpdb;

        try {
            $like         = '_transient_' . $wpdb->esc_like( $prefix ) . '%';
            $like_timeout = '_transient_timeout_' . $wpdb->esc_like( $prefix ) . '%';

            return (int) $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->options}
                 WHERE option_name LIKE %s OR option_name LIKE %s",
                $like,
                $like_timeout
            ) );
        } catch ( \Throwable $e ) {
            return 0;
        }
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

    /**
     * Drops every rate-limit counter: Redis `rl:*` keys, the transient
     * fallback and the wp_options rows left by the pre-5.4 implementation.
     */
    public function flush_rate_limit_group(): int {
        $count = 0;

        if ( self::backend() === 'redis' && self::$redis instanceof \Redis ) {
            try {
                $iterator = null;
                if ( method_exists( self::$redis, 'scan' ) ) {
                    self::$redis->setOption( \Redis::OPT_SCAN, \Redis::SCAN_RETRY );
                    while ( false !== ( $keys = self::$redis->scan( $iterator, $this->redis_ns() . 'rl:*', 500 ) ) ) {
                        if ( is_array( $keys ) ) {
                            foreach ( $keys as $k ) {
                                if ( self::$redis->del( $k ) ) { $count++; }
                            }
                        }
                        if ( 0 === (int) $iterator ) { break; }
                    }
                }
            } catch ( \Throwable $e ) {
                self::mark_down( $e->getMessage() );
            }
        }

        $count += $this->delete_transients_by_prefix( 'wssf_rl_' );
        $count += $this->delete_options_by_prefix( '_wssf_rl_' );

        return $count;
    }

    /* ============================================================
     *  Invalidation
     * ============================================================ */

    /**
     * Cache generation: every key carries it, so bumping it makes all
     * existing entries unreachable. A SCAN + DEL sweep is deliberately not
     * done here — TTL expires the entries and there is nothing to keep
     * consistent.
     */
    public function generation(): int {
        return (int) get_option( self::CACHE_GEN_OPTION, 0 );
    }

    public function bump_generation(): void {
        $v = (int) get_option( self::CACHE_GEN_OPTION, 0 );
        update_option( self::CACHE_GEN_OPTION, $v + 1, true );
    }

    /* ============================================================
     *  Rate limiting
     * ============================================================ */

    /**
     * Increments a counter, or null when Redis is not available — the caller
     * then falls back to a transient.
     */
    public function rate_incr( string $key, int $window ): ?int {
        if ( self::backend() !== 'redis' || ! self::$redis instanceof \Redis ) {
            return null;
        }

        $window = max( 1, $window );

        try {
            $redis_key = $this->redis_key( 'rl:' . md5( $key ) );
            $count     = (int) self::$redis->incr( $redis_key );

            // A counter without TTL would never reset; re-arm it whenever the
            // key lost its expiry (e.g. the EXPIRE of the first INCR failed).
            if ( $count === 1 || (int) self::$redis->ttl( $redis_key ) < 0 ) {
                self::$redis->expire( $redis_key, $window );
            }

            return $count;
        } catch ( \Throwable $e ) {
            self::mark_down( $e->getMessage() );
            return null;
        }
    }

    /* ============================================================
     *  Diagnostics
     * ============================================================ */

    /**
     * Redis server version (e.g. "7.2.4"), empty when Redis is unavailable.
     */
    public function redis_server_version(): string {
        if ( self::backend() !== 'redis' || ! self::$redis instanceof \Redis ) {
            return '';
        }

        try {
            $info = self::$redis->info( 'server' );
            if ( is_array( $info ) && isset( $info['redis_version'] ) ) {
                return (string) $info['redis_version'];
            }
        } catch ( \Throwable $e ) {
            self::mark_down( $e->getMessage() );
        }

        return '';
    }

    /**
     * phpredis client version (not the server version); needs no connection.
     */
    public static function redis_client_version(): string {
        if ( ! extension_loaded( 'redis' ) ) { return ''; }
        if ( defined( 'Redis::VERSION' ) ) {
            return (string) constant( 'Redis::VERSION' );
        }
        $v = phpversion( 'redis' );
        return is_string( $v ) ? $v : '';
    }
}
