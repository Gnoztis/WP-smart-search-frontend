<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * IOC map — обратный индекс «IOC-значение → post_id».
 *
 * Наполняется при индексации извлекаемыми IOC (кроме 'word'):
 * CVE, BDU, ATT&CK, IP, домены, URL, хеши, кошельки, YARA-токены.
 *
 * Используется для co-occurrence:
 *   - «Связанные IOC» на карточке поста;
 *   - «Похожие запросы» на странице результатов.
 */
final class WSSF_IOC_Map {

    private const MAX_IOC_PER_POST = 500;
    private const COC_CACHE_TTL    = HOUR_IN_SECONDS;
    private const COC_MAX_RESULTS  = 10;
    private const COC_MIN_POSTS    = 1;

    public function __construct( private WSSF_Cache $cache ) {}

    public function table(): ?string {
        global $wpdb;
        $t = (string) $wpdb->prefix . 'wssf_ioc_map';
        return preg_match( '/^[a-zA-Z0-9_]+$/', $t ) ? $t : null;
    }

    public function is_enabled(): bool {
        return (bool) get_option( 'wssf_ioc_map_enabled', 1 );
    }

    /* ============================================================
     *  Наполнение
     * ============================================================ */

    /**
     * Обновляет IOC map для поста: удаляет старые, вставляет новые.
     *
     * @param int      $post_id
     * @param string[] $ioc_values  Уже извлечённые значения (с типом).
     */
    public function update_for_post( int $post_id, array $ioc_values ): void {
        if ( ! $this->is_enabled() ) { return; }
        $table = $this->table();
        if ( $table === null || $post_id <= 0 ) { return; }

        global $wpdb;

        try {
            $wpdb->delete( $table, [ 'post_id' => $post_id ], [ '%d' ] );
        } catch ( \Throwable $e ) { return; }

        if ( empty( $ioc_values ) ) { return; }

        // Дедупликация и лимит
        $seen  = [];
        $rows  = [];
        $types = [];
        foreach ( $ioc_values as $item ) {
            if ( is_array( $item ) && isset( $item['value'], $item['type'] ) ) {
                $val  = (string) $item['value'];
                $type = (string) $item['type'];
            } else {
                $val  = (string) $item;
                $type = WSSF_Indexer::classify_term( $val );
            }
            if ( $val === '' || $type === 'word' ) { continue; }
            if ( mb_strlen( $val ) > 200 ) { continue; }
            $key = $type . '|' . $val;
            if ( isset( $seen[ $key ] ) ) { continue; }
            $seen[ $key ] = true;
            $rows[]  = $val;
            $types[] = $type;
            if ( count( $rows ) >= self::MAX_IOC_PER_POST ) { break; }
        }
        if ( empty( $rows ) ) { return; }

        // Batch INSERT IGNORE
        $values = [];
        $params = [];
        foreach ( $rows as $i => $val ) {
            $values[] = '(%d, %s, %s)';
            $params[] = $post_id;
            $params[] = $val;
            $params[] = $types[ $i ];
        }
        $sql = "INSERT IGNORE INTO `{$table}` (post_id, ioc_value, ioc_type) VALUES "
             . implode( ',', $values );

        try {
            $wpdb->query( $wpdb->prepare( $sql, $params ) );
        } catch ( \Throwable $e ) { /* ignore */ }
    }

    public function delete_for_post( int $post_id ): void {
        $table = $this->table();
        if ( $table === null || $post_id <= 0 ) { return; }
        global $wpdb;
        try { $wpdb->delete( $table, [ 'post_id' => $post_id ], [ '%d' ] ); }
        catch ( \Throwable $e ) {}
    }

    /* ============================================================
     *  Co-occurrence
     * ============================================================ */

    /**
     * По списку IOC находит посты, где они встречаются, затем — другие IOC
     * в этих постах (кроме исходных).
     *
     * @param string[] $ioc_values
     * @param int      $limit
     * @return array<int, array{value:string, type:string, cnt:int}>
     */
    public function co_occurring( array $ioc_values, int $limit = self::COC_MAX_RESULTS ): array {
        if ( ! $this->is_enabled() ) { return []; }
        $table = $this->table();
        if ( $table === null ) { return []; }

        $limit = max( 1, min( 50, $limit ) );

        // Очищаем и дедуплицируем вход.
        $clean = [];
        foreach ( $ioc_values as $v ) {
            $v = trim( (string) $v );
            if ( $v === '' || mb_strlen( $v ) > 200 ) { continue; }
            $clean[ $v ] = true;
        }
        $clean = array_keys( $clean );
        if ( empty( $clean ) ) { return []; }

        // Кеш по хешу списка + generation.
        $cache_key = 'wssf_coc_v1_' . $this->cache->generation() . '_' . md5( implode( '|', $clean ) . '|' . $limit );
        $cached    = $this->cache->get( $cache_key );
        if ( is_array( $cached ) && isset( $cached['items'] ) ) {
            return (array) $cached['items'];
        }

        global $wpdb;

        // Шаг 1: посты, где встречается хотя бы один IOC запроса.
        $in_ph   = implode( ',', array_fill( 0, count( $clean ), '%s' ) );
        $params1 = array_merge( $clean, [ self::COC_MAX_RESULTS * 50 ] ); // cap на кол-во постов

        try {
            $post_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT post_id FROM `{$table}` WHERE ioc_value IN ($in_ph) LIMIT %d",
                $params1
            ) );
        } catch ( \Throwable $e ) { return []; }

        if ( ! is_array( $post_ids ) || empty( $post_ids ) ) {
            $this->cache->set( $cache_key, [ 'items' => [] ], self::COC_CACHE_TTL );
            return [];
        }
        $post_ids = array_map( 'intval', $post_ids );
        $post_ph  = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

        // Шаг 2: другие IOC в этих постах.
        $params2 = array_merge( $post_ids, $clean, [ $limit ] );
        try {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT ioc_value, ioc_type, COUNT(DISTINCT post_id) AS cnt
                 FROM `{$table}`
                 WHERE post_id IN ($post_ph)
                   AND ioc_value NOT IN ($in_ph)
                   AND ioc_type <> 'word'
                 GROUP BY ioc_value, ioc_type
                 HAVING cnt >= %d
                 ORDER BY cnt DESC, ioc_value ASC
                 LIMIT %d",
                array_merge( $post_ids, $clean, [ self::COC_MIN_POSTS, $limit ] )
            ) );
        } catch ( \Throwable $e ) { return []; }

        $items = [];
        if ( is_array( $rows ) ) {
            foreach ( $rows as $r ) {
                $items[] = [
                    'value' => (string) $r->ioc_value,
                    'type'  => (string) $r->ioc_type,
                    'cnt'   => (int) $r->cnt,
                ];
            }
        }

        $this->cache->set( $cache_key, [ 'items' => $items ], self::COC_CACHE_TTL );
        return $items;
    }

    /**
     * Для одного поста: список IOC, встречающихся в других постах с теми же IOC.
     *
     * @param int $post_id
     * @param int $limit
     * @return array<int, array{value:string, type:string, cnt:int}>
     */
    public function related_for_post( int $post_id, int $limit = 10 ): array {
        if ( ! $this->is_enabled() ) { return []; }
        $table = $this->table();
        if ( $table === null || $post_id <= 0 ) { return []; }

        global $wpdb;

        $cache_key = 'wssf_ioc_rel_v1_' . $this->cache->generation() . '_' . $post_id . '_' . $limit;
        $cached    = $this->cache->get( $cache_key );
        if ( is_array( $cached ) && isset( $cached['items'] ) ) { return (array) $cached['items']; }

        try {
            $own = $wpdb->get_col( $wpdb->prepare(
                "SELECT ioc_value FROM `{$table}` WHERE post_id = %d",
                $post_id
            ) );
        } catch ( \Throwable $e ) { return []; }

        if ( ! is_array( $own ) || empty( $own ) ) {
            $this->cache->set( $cache_key, [ 'items' => [] ], self::COC_CACHE_TTL );
            return [];
        }
        $own = array_map( 'strval', $own );
        $items = $this->co_occurring( $own, $limit );
        $this->cache->set( $cache_key, [ 'items' => $items ], self::COC_CACHE_TTL );
        return $items;
    }

    /* ============================================================
     *  Статистика / очистка
     * ============================================================ */

    public function count_all(): int {
        $table = $this->table();
        if ( $table === null ) { return 0; }
        global $wpdb;
        try { return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ); }
        catch ( \Throwable $e ) { return 0; }
    }

    public function truncate(): void {
        $table = $this->table();
        if ( $table === null ) { return; }
        global $wpdb;
        try { $wpdb->query( "TRUNCATE TABLE `{$table}`" ); }
        catch ( \Throwable $e ) {}
    }
}