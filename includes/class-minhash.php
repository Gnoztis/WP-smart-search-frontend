<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * MinHash для near-duplicate detection.
 *
 * 64-элементная signature по 3-граммам слов (шинглы). Jaccard двух
 * signature ≈ Jaccard двух множеств шинглов.
 *
 * Хеш-функция: xxh64 (PHP 8.1+, ~50ns/вызов), fallback crc32.
 * Jaccard-погрешность при HASH_COUNT=64 — ±0.125, для порога 0.5 приемлемо.
 */
final class WSSF_MinHash {

    private const HASH_COUNT     = 64;
    private const SHINGLE_SIZE   = 3;
    private const MIN_SIMILARITY = 0.5;
    private const MAX_CANDIDATES = 500;
    private const MAX_RESULTS    = 5;

    public function __construct( private WSSF_Cache $cache ) {}

    public function is_enabled(): bool {
        return (bool) get_option( 'wssf_minhash_enabled', 1 );
    }

    public function get_table(): ?string {
        global $wpdb;
        $t = (string) $wpdb->prefix . 'wssf_minhash';
        return preg_match( '/^[a-zA-Z0-9_]+$/', $t ) ? $t : null;
    }

    public function create_table(): void {
        global $wpdb;
        $table = $this->get_table();
        if ( $table === null ) { return; }
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE `{$table}` (
            post_id BIGINT UNSIGNED NOT NULL,
            signature BLOB NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (post_id),
            KEY updated_idx (updated_at)
        ) ENGINE=InnoDB {$charset};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    public function signature( array $tokens ): string {
        $tokens = array_values( array_filter( array_map( 'strval', $tokens ), static fn( $t ) => $t !== '' ) );
        if ( count( $tokens ) < self::SHINGLE_SIZE ) { return ''; }

        $shingles = [];
        $n = count( $tokens );
        for ( $i = 0; $i <= $n - self::SHINGLE_SIZE; $i++ ) {
            $shingles[] = implode( ' ', array_slice( $tokens, $i, self::SHINGLE_SIZE ) );
        }
        if ( empty( $shingles ) ) { return ''; }

        $algo = ( function_exists( 'hash' ) && in_array( 'xxh64', hash_algos(), true ) ) ? 'xxh64' : null;

        $mins = array_fill( 0, self::HASH_COUNT, PHP_INT_MAX );
        foreach ( $shingles as $sh ) {
            for ( $h = 0; $h < self::HASH_COUNT; $h++ ) {
                $key = $sh . '|' . $h;
                if ( $algo !== null ) {
                    $hash = (int) hexdec( substr( hash( $algo, $key ), 0, 8 ) );
                } else {
                    $hash = crc32( $key );
                }
                if ( $hash < $mins[ $h ] ) { $mins[ $h ] = $hash; }
            }
        }
        return pack( 'N*', ...$mins );
    }

    public function jaccard( string $a, string $b ): float {
        if ( strlen( $a ) !== self::HASH_COUNT * 4 || strlen( $b ) !== self::HASH_COUNT * 4 ) {
            return 0.0;
        }
        $va = unpack( 'N*', $a );
        $vb = unpack( 'N*', $b );
        if ( ! is_array( $va ) || ! is_array( $vb ) ) { return 0.0; }

        $same = 0;
        for ( $i = 1; $i <= self::HASH_COUNT; $i++ ) {
            if ( $va[ $i ] === $vb[ $i ] ) { $same++; }
        }
        return $same / self::HASH_COUNT;
    }

    public function update_for_post( int $post_id, array $tokens ): void {
        if ( ! $this->is_enabled() ) { return; }
        $table = $this->get_table();
        if ( $table === null || $post_id <= 0 ) { return; }

        $sig = $this->signature( $tokens );
        if ( $sig === '' ) { $this->delete_for_post( $post_id ); return; }

        global $wpdb;
        try {
            $wpdb->replace( $table, [
                'post_id'    => $post_id,
                'signature'  => $sig,
                'updated_at' => current_time( 'mysql' ),
            ], [ '%d', '%s', '%s' ] );
        } catch ( \Throwable $e ) {}
    }

    public function delete_for_post( int $post_id ): void {
        $table = $this->get_table();
        if ( $table === null || $post_id <= 0 ) { return; }
        global $wpdb;
        try { $wpdb->delete( $table, [ 'post_id' => $post_id ], [ '%d' ] ); }
        catch ( \Throwable $e ) {}
    }

    /**
     * @return array<int, array{post_id:int, similarity:float}>
     */
    public function similar_to( int $post_id ): array {
        if ( ! $this->is_enabled() ) { return []; }
        $table = $this->get_table();
        if ( $table === null || $post_id <= 0 ) { return []; }

        $cache_key = 'wssf_mh_sim_v2_' . $this->cache->generation() . '_' . $post_id;
        $cached = $this->cache->get( $cache_key );
        if ( is_array( $cached ) && isset( $cached['items'] ) ) {
            return (array) $cached['items'];
        }

        global $wpdb;
        try {
            $sig = $wpdb->get_var( $wpdb->prepare(
                "SELECT signature FROM `{$table}` WHERE post_id = %d", $post_id
            ) );
        } catch ( \Throwable $e ) { return []; }
        if ( ! is_string( $sig ) || $sig === '' ) {
            $this->cache->set( $cache_key, [ 'items' => [] ], HOUR_IN_SECONDS );
            return [];
        }

        try {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT post_id, signature FROM `{$table}`
                 WHERE post_id != %d
                 ORDER BY updated_at DESC
                 LIMIT %d",
                $post_id, self::MAX_CANDIDATES
            ) );
        } catch ( \Throwable $e ) { return []; }
        if ( ! is_array( $rows ) ) { $rows = []; }

        $similar = [];
        foreach ( $rows as $row ) {
            $j = $this->jaccard( $sig, (string) $row->signature );
            if ( $j >= self::MIN_SIMILARITY ) {
                $similar[] = [ 'post_id' => (int) $row->post_id, 'similarity' => round( $j, 3 ) ];
            }
        }
        usort( $similar, static fn( $a, $b ) => $b['similarity'] <=> $a['similarity'] );
        $similar = array_slice( $similar, 0, self::MAX_RESULTS );

        $this->cache->set( $cache_key, [ 'items' => $similar ], HOUR_IN_SECONDS );
        return $similar;
    }

    public function count_all(): int {
        $table = $this->get_table();
        if ( $table === null ) { return 0; }
        global $wpdb;
        try { return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ); }
        catch ( \Throwable $e ) { return 0; }
    }

    public function truncate(): void {
        $table = $this->get_table();
        if ( $table === null ) { return; }
        global $wpdb;
        try { $wpdb->query( "TRUNCATE TABLE `{$table}`" ); }
        catch ( \Throwable $e ) {}
    }
}