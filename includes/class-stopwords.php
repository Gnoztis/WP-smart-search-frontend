<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Стоп-слова.
 *
 * - Таблица wp_wssf_stopwords (term PK, lang).
 * - Дефолтные списки для ru/en — в assets/stopwords/{lang}.txt.
 * - Проверка в tokenize(): после стемминга, чтобы стоп-слово было в стемм-форме.
 * - Кеш: Redis/transient по ключу wssf_sw_v1_{lang}, TTL 6ч.
 */
final class WSSF_Stopwords {

    private const CACHE_TTL = 6 * HOUR_IN_SECONDS;
    private const LANGS     = [ 'ru', 'en' ];

    private static ?self $instance = null;

    private ?array $cache_mem = [];

    public static function instance(): self {
        if ( self::$instance === null ) { self::$instance = new self(); }
        return self::$instance;
    }

    public function is_enabled(): bool {
        return (bool) get_option( 'wssf_stopwords_enabled', 1 );
    }

    /**
     * Список стоп-слов для языка (по умолчанию — авто-определение).
     * @return array<string,true>  term => true (для O(1) проверки)
     */
    public function get_for_lang( string $lang = '' ): array {
        if ( $lang === '' ) { $lang = $this->detect_lang_from_query_context(); }
        if ( isset( $this->cache_mem[ $lang ] ) ) { return $this->cache_mem[ $lang ]; }

        $cache = WSSF_Plugin::instance()->cache;
        $key   = 'wssf_sw_v1_' . $lang;
        $data  = $cache->get( $key );
        if ( is_array( $data ) && isset( $data['terms'] ) && is_array( $data['terms'] ) ) {
            $out = [];
            foreach ( $data['terms'] as $t ) { $out[ (string) $t ] = true; }
            $this->cache_mem[ $lang ] = $out;
            return $out;
        }

        global $wpdb;
        $table = (string) $wpdb->prefix . 'wssf_stopwords';
        if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', $table ) ) { return []; }

        try {
            $rows = $wpdb->get_col( $wpdb->prepare(
                "SELECT term FROM `{$table}` WHERE lang = %s",
                $lang
            ) );
        } catch ( \Throwable $e ) { return []; }

        $terms = is_array( $rows ) ? array_map( 'strval', $rows ) : [];
        $out = [];
        foreach ( $terms as $t ) { $out[ $t ] = true; }

        $cache->set( $key, [ 'terms' => $terms ], self::CACHE_TTL );
        $this->cache_mem[ $lang ] = $out;
        return $out;
    }

    /**
     * Список для обоих языков (универсальная проверка).
     */
    public function get_all(): array {
        $out = $this->get_for_lang( 'ru' ) + $this->get_for_lang( 'en' );
        return $out;
    }

    public function is_stopword( string $term, string $lang = '' ): bool {
        if ( ! $this->is_enabled() ) { return false; }
        $term = (string) $term;
        if ( $term === '' ) { return false; }

        $list = $lang === '' ? $this->get_all() : $this->get_for_lang( $lang );
        return isset( $list[ $term ] );
    }

    /* ============================================================
     *  Управление
     * ============================================================ */

    public function add( string $term, string $lang = 'ru' ): bool {
        $term = trim( mb_strtolower( $term ) );
        if ( $term === '' || mb_strlen( $term ) > 200 ) { return false; }
        $lang = $this->normalize_lang( $lang );

        global $wpdb;
        $table = (string) $wpdb->prefix . 'wssf_stopwords';
        if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', $table ) ) { return false; }

        try {
            $wpdb->query( $wpdb->prepare(
                "INSERT IGNORE INTO `{$table}` (term, lang) VALUES (%s, %s)",
                $term, $lang
            ) );
        } catch ( \Throwable $e ) { return false; }

        $this->flush_cache();
        return true;
    }

    public function remove( string $term, string $lang = 'ru' ): bool {
        $term = trim( mb_strtolower( $term ) );
        if ( $term === '' ) { return false; }
        $lang = $this->normalize_lang( $lang );

        global $wpdb;
        $table = (string) $wpdb->prefix . 'wssf_stopwords';
        if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', $table ) ) { return false; }

        try {
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM `{$table}` WHERE term = %s AND lang = %s",
                $term, $lang
            ) );
        } catch ( \Throwable $e ) { return false; }

        $this->flush_cache();
        return true;
    }

    public function clear( string $lang = '' ): void {
        global $wpdb;
        $table = (string) $wpdb->prefix . 'wssf_stopwords';
        if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', $table ) ) { return; }

        try {
            if ( $lang === '' ) {
                $wpdb->query( "TRUNCATE TABLE `{$table}`" );
            } else {
                $lang = $this->normalize_lang( $lang );
                $wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE lang = %s", $lang ) );
            }
        } catch ( \Throwable $e ) {}

        $this->flush_cache();
    }

    /**
     * Загружает дефолтные стоп-слова из assets/stopwords/{lang}.txt.
     * Возвращает кол-во загруженных по каждому языку.
     *
     * @return array<string,int>
     */
    public function load_defaults(): array {
        $counts = [];

        global $wpdb;
        $table = (string) $wpdb->prefix . 'wssf_stopwords';
        if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', $table ) ) { return $counts; }

        foreach ( self::LANGS as $lang ) {
            $path = WSSF_PATH . 'assets/stopwords/' . $lang . '.txt';
            if ( ! is_readable( $path ) ) { $counts[ $lang ] = 0; continue; }

            $raw = @file_get_contents( $path );
            if ( ! is_string( $raw ) || $raw === '' ) { $counts[ $lang ] = 0; continue; }

            $lines = preg_split( '/\R/u', $raw );
            if ( ! is_array( $lines ) ) { $counts[ $lang ] = 0; continue; }

            $added = 0;
            $values = [];
            $params = [];
            foreach ( $lines as $line ) {
                $t = trim( mb_strtolower( (string) $line ) );
                if ( $t === '' || mb_strlen( $t ) > 200 ) { continue; }

                // Стеммим стоп-слово, чтобы совпадало с токенами индекса.
                try {
                    $morph = new WSSF_Morphology();
                    $stem  = $morph->stem( $t, $lang );
                    if ( $stem === '' ) { $stem = $t; }
                } catch ( \Throwable $e ) { $stem = $t; }

                $values[] = '(%s, %s)';
                $params[] = $stem;
                $params[] = $lang;
                $added++;
            }

            if ( ! empty( $values ) ) {
                $sql = "INSERT IGNORE INTO `{$table}` (term, lang) VALUES " . implode( ',', $values );
                try {
                    $wpdb->query( $wpdb->prepare( $sql, $params ) );
                } catch ( \Throwable $e ) {}
            }

            $counts[ $lang ] = $added;
        }

        $this->flush_cache();
        return $counts;
    }

    public function flush_cache(): void {
        $this->cache_mem = [];
        $cache = WSSF_Plugin::instance()->cache;
        $cache->delete( 'wssf_sw_v1_ru' );
        $cache->delete( 'wssf_sw_v1_en' );
    }

    /* ============================================================
     *  Утилиты
     * ============================================================ */

    private function normalize_lang( string $lang ): string {
        $lang = strtolower( substr( $lang, 0, 5 ) );
        return in_array( $lang, self::LANGS, true ) ? $lang : 'ru';
    }

    private function detect_lang_from_query_context(): string {
        $locale = (string) get_locale();
        return strpos( strtolower( $locale ), 'en' ) === 0 ? 'en' : 'ru';
    }
}