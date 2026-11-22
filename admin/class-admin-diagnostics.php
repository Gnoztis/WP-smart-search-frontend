<?php
declare(strict_types=1);
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class WSSF_Admin_Diagnostics {

    private static ?self $instance = null;

    public static function instance(): self {
        if ( self::$instance === null ) { self::$instance = new self(); }
        return self::$instance;
    }

    public function render_tab(): void {
        if ( ! current_user_can( 'manage_options' ) ) { return; }

        $report = '';
        try {
            $results   = get_transient( 'wssf_diag_results' );
            $last_ajax = get_transient( 'wssf_diag_last' );
            $report    = $this->build_report( $results, $last_ajax );
        } catch ( \Throwable $e ) {
            $report = '';
        }

        if ( ! is_string( $report ) || trim( $report ) === '' ) {
            $report = $this->build_fallback_report();
        }

        $search_report = '';
        $search_last_query = '';
        try {
            $search_data = get_transient( 'wssf_diag_search_result' );
            if ( is_array( $search_data ) && isset( $search_data['result'] ) ) {
                $search_report = $this->build_search_report( $search_data );
                $search_last_query = (string) ( $search_data['query'] ?? '' );
            }
        } catch ( \Throwable $e ) {
            $search_report = 'SEARCH_REPORT_ERROR: ' . sanitize_text_field( $e->getMessage() );
        }

        $view = WSSF_PATH . 'admin/view-diagnostics.php';
        if ( file_exists( $view ) ) {
            $action_url = admin_url( 'admin-post.php' );
            require $view;
            return;
        }
        echo '<div class="notice notice-error"><p><b>View not found:</b> <code>'
            . esc_html( $view ) . '</code></p></div>';
    }

    /* ============================================================
     *  Окружение
     * ============================================================ */

    public function get_env_data(): array {
        global $wpdb, $apbct;

        $env = [
            'php_version'          => PHP_VERSION,
            'wp_version'           => get_bloginfo( 'version' ),
            'mysql_version'        => '',
            'mbstring'             => extension_loaded( 'mbstring' ) ? 'yes' : 'no',
            'redis_ext'            => extension_loaded( 'redis' ) ? 'yes' : 'no',
            'cache_backend'        => 'none',
            'cache_backoff'        => 0,
            'redis_server_version' => '',
            'redis_client_version' => '',
            'cache_generation'     => 0,
            'index_table_exists'   => false,
            'index_count'          => 0,
            'index_total_posts'    => 0,
            'cleantalk_active'     => function_exists( 'apbct_base_call' ) ? 'yes' : 'no',
            'cleantalk_mode'       => class_exists( 'WSSF_Admin_Settings' ) ? WSSF_Admin_Settings::get_cleantalk_mode() : 'auto',
            'cleantalk_effective'  => ( class_exists( 'WSSF_CleanTalk' ) && WSSF_CleanTalk::active() ) ? 'yes' : 'no',
            'cleantalk_key_ok'     => 'N/A',
            'cleantalk_cookies'    => 'N/A',
            'diag_enabled'         => (bool) apply_filters( 'wssf_diag_enabled', false ),
            'theme_template'       => '',
            'theme_is_community'   => 'no',
            'rate_limit'           => 0,
        ];

        try {
            $env['theme_template'] = function_exists( 'get_template' ) ? (string) get_template() : '';
            $plugin = WSSF_Plugin::instance();
            if ( $plugin instanceof WSSF_Plugin ) {
                $env['theme_is_community'] = $plugin->security->is_community_theme() ? 'yes' : 'no';
                $env['rate_limit']         = $plugin->security->effective_rate_limit();
            }
        } catch ( \Throwable $e ) { /* ignore */ }

        try {
            $env['mysql_version'] = (string) $wpdb->get_var( 'SELECT VERSION()' );
        } catch ( \Throwable $e ) { /* ignore */ }

        try {
            $plugin = WSSF_Plugin::instance();
            if ( $plugin instanceof WSSF_Plugin ) {
                $env['cache_generation']     = $plugin->cache->generation();
                $env['redis_server_version'] = $plugin->cache->redis_server_version();
            }
            $env['cache_backend']        = WSSF_Cache::backend();
            $env['cache_backoff']        = max( 0, WSSF_Cache::backoff_until() - time() );
            $env['redis_client_version'] = WSSF_Cache::redis_client_version();
        } catch ( \Throwable $e ) { /* ignore */ }

        try {
            $table = (string) $wpdb->prefix . 'wssf_index';
            if ( preg_match( '/^[a-zA-Z0-9_]+$/', $table ) ) {
                $env['index_table_exists'] = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
                if ( $env['index_table_exists'] ) {
                    $env['index_count'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- literal SQL; the table name is validated with /^[a-zA-Z0-9_]+$/.
                }
            }
            $env['index_total_posts'] = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- literal SQL without user input; the table name is validated with /^[a-zA-Z0-9_]+$/.
                "SELECT COUNT(*) FROM {$wpdb->posts}
                 WHERE post_status = 'publish' AND post_type IN ('post','page')"
            );
        } catch ( \Throwable $e ) { /* ignore */ }

        if ( isset( $apbct ) && is_object( $apbct ) ) {
            $env['cleantalk_key_ok']  = ! empty( $apbct->key_is_ok ) ? 'yes' : 'no';
            $env['cleantalk_cookies'] = (string) ( $apbct->data['cookies_type'] ?? 'N/A' );
        }

        return $env;
    }

    /* ============================================================
     *  CleanTalk — тест
     * ============================================================ */

    public function handle_run(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Forbidden', 403 ); }
        check_admin_referer( 'wssf_diag_run' );

        $query = isset( $_POST['test_query'] )
            ? sanitize_text_field( wp_unslash( $_POST['test_query'] ) )
            : '';
        if ( $query === '' ) { $query = 'viagra casino cheap'; }

        try {
            $results = $this->execute_all_scenarios( $query );
        } catch ( \Throwable $e ) {
            $results = [ 'error' => 'EXCEPTION: ' . sanitize_text_field( $e->getMessage() ) ];
        }

        set_transient( 'wssf_diag_results', [
            'query'   => $query,
            'results' => $results,
            'time'    => current_time( 'mysql' ),
        ], 5 * MINUTE_IN_SECONDS );

        wp_safe_redirect( add_query_arg(
            [ 'page' => 'wssf-settings', 'tab' => 'diagnostics', 'ran' => 1 ],
            admin_url( 'options-general.php' )
        ) );
        exit;
    }

    /* ============================================================
     *  Тест поиска
     * ============================================================ */

    public function handle_search_test(): void {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Forbidden', 403 ); }
        check_admin_referer( 'wssf_diag_search' );

        $query = isset( $_POST['search_query'] )
            ? sanitize_text_field( wp_unslash( $_POST['search_query'] ) )
            : '';
        if ( $query === '' ) { $query = 'тест'; }

        try {
            $result = $this->run_search_test( $query );
        } catch ( \Throwable $e ) {
            $result = [ 'error' => 'EXCEPTION: ' . sanitize_text_field( $e->getMessage() ) ];
        }

        set_transient( 'wssf_diag_search_result', [
            'query'  => $query,
            'result' => $result,
            'time'   => current_time( 'mysql' ),
        ], 5 * MINUTE_IN_SECONDS );

        wp_safe_redirect( add_query_arg(
            [ 'page' => 'wssf-settings', 'tab' => 'diagnostics', 'search_ran' => 1 ],
            admin_url( 'options-general.php' )
        ) );
        exit;
    }

    public function run_search_test( string $query ): array {
        global $wpdb;

        $out = [
            'query'             => $query,
            'sanitized'         => '',
            'parsed_groups'     => [],
            'flat_tokens'       => [],
            'regular_tokens'    => [],
            'technical_tokens'  => [],
            'boolean_string'    => '',
            'index_exists'      => false,
            'index_count'       => 0,
            'index_total_posts' => 0,
            'index_updated_at'  => '',
            'stem_probe'        => [],
            'fulltext'          => [
                'ran'        => false,
                'reason'     => '',
                'total'      => 0,
                'elapsed_ms' => 0.0,
                'items'      => [],
            ],
            'like'              => [
                'ran'        => false,
                'reason'     => '',
                'total'      => 0,
                'elapsed_ms' => 0.0,
                'items'      => [],
            ],
            'diagnosis'         => [],
            'error'             => null,
        ];

        $table = (string) $wpdb->prefix . 'wssf_index';
        $table_valid = preg_match( '/^[a-zA-Z0-9_]+$/', $table ) === 1;

        try {
            $out['index_exists'] = $table_valid
                && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
            if ( $out['index_exists'] ) {
                $out['index_count']      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- literal SQL; the table name is validated with /^[a-zA-Z0-9_]+$/.
                $out['index_updated_at'] = (string) get_option( 'wssf_index_updated_at', '' );
            }
            $out['index_total_posts'] = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- literal SQL without user input; the table name is validated with /^[a-zA-Z0-9_]+$/.
                "SELECT COUNT(*) FROM {$wpdb->posts}
                 WHERE post_status = 'publish' AND post_type IN ('post','page')"
            );
        } catch ( \Throwable $e ) { /* ignore */ }

        $plugin = WSSF_Plugin::instance();
        if ( ! $plugin instanceof WSSF_Plugin ) {
            $out['error'] = 'WSSF_Plugin not initialized';
            return $out;
        }

        $out['sanitized'] = WSSF_Query_Parser::sanitize( $query );
        $groups = $plugin->parser->parse( $query, true );
        if ( is_wp_error( $groups ) ) {
            $out['error'] = $groups->get_error_message();
            return $out;
        }
        $out['parsed_groups'] = $groups;

        $tokenizer = new WSSF_Tokenizer( new WSSF_Morphology() );
        $flat = [];
        foreach ( $groups as $group ) {
            foreach ( $group as $tok ) {
                $tok = (string) $tok;
                $flat[] = $tok;
                if ( $tokenizer->is_technical_id( $tok ) ) {
                    $out['technical_tokens'][] = $tok;
                } else {
                    $out['regular_tokens'][] = $tok;
                }
            }
        }
        $out['flat_tokens']       = array_values( array_unique( $flat ) );
        $out['regular_tokens']    = array_values( array_unique( $out['regular_tokens'] ) );
        $out['technical_tokens']  = array_values( array_unique( $out['technical_tokens'] ) );

        try {
            $out['boolean_string'] = $tokenizer->groups_to_boolean_string( $groups );
        } catch ( \Throwable $e ) {
            $out['boolean_string'] = '';
        }

        if ( $out['index_exists'] && $out['index_count'] > 0 && ! empty( $out['flat_tokens'] ) ) {
            foreach ( $out['flat_tokens'] as $stem ) {
                $stem_like = '%' . $wpdb->esc_like( $stem ) . '%';
                try {
                    $row = $wpdb->get_row( $wpdb->prepare(
                        "SELECT
                            SUM(CASE WHEN title_stem   LIKE %s THEN 1 ELSE 0 END) AS in_title,
                            SUM(CASE WHEN content_stem LIKE %s THEN 1 ELSE 0 END) AS in_content,
                            SUM(CASE WHEN excerpt_stem LIKE %s THEN 1 ELSE 0 END) AS in_excerpt,
                            SUM(CASE WHEN tags_stem    LIKE %s THEN 1 ELSE 0 END) AS in_tags,
                            COUNT(*) AS total_rows
                         FROM `{$table}`
                         WHERE title_stem   LIKE %s
                            OR content_stem LIKE %s
                            OR excerpt_stem LIKE %s
                            OR tags_stem    LIKE %s",
                        $stem_like, $stem_like, $stem_like, $stem_like,
                        $stem_like, $stem_like, $stem_like, $stem_like
                    ) );
                    if ( $row ) {
                        $out['stem_probe'][] = [
                            'stem'         => (string) $stem,
                            'is_technical' => $tokenizer->is_technical_id( (string) $stem ),
                            'in_title'     => (int) $row->in_title,
                            'in_content'   => (int) $row->in_content,
                            'in_excerpt'   => (int) $row->in_excerpt,
                            'in_tags'      => (int) $row->in_tags,
                            'total_rows'   => (int) $row->total_rows,
                        ];
                    }
                } catch ( \Throwable $e ) { /* ignore */ }
            }
        }

        $fulltext_available = $out['index_exists']
            && $out['index_count'] > 0
            && $out['boolean_string'] !== '';

        if ( $fulltext_available ) {
            $start = microtime( true );
            try {
                $probe = $plugin->engine->search_fulltext( $groups, 1, 0, '', [], [] );
                $out['fulltext']['ran']        = true;
                $out['fulltext']['total']      = (int) ( $probe['total'] ?? 0 );
                $out['fulltext']['items']      = array_slice( (array) ( $probe['items'] ?? [] ), 0, 5 );
                $out['fulltext']['elapsed_ms'] = round( ( microtime( true ) - $start ) * 1000, 2 );
            } catch ( \Throwable $e ) {
                $out['fulltext']['ran']    = false;
                $out['fulltext']['reason'] = 'EXCEPTION: ' . sanitize_text_field( $e->getMessage() );
            }
        } else {
            $out['fulltext']['ran'] = false;
            if ( ! $out['index_exists'] ) {
                $out['fulltext']['reason'] = 'Таблица индекса не создана';
            } elseif ( $out['index_count'] === 0 ) {
                $out['fulltext']['reason'] = 'Индекс пуст (0 записей)';
            } elseif ( $out['boolean_string'] === '' ) {
                $out['fulltext']['reason'] = 'Пустой boolean-запрос (нет значимых токенов)';
            } else {
                $out['fulltext']['reason'] = 'Неизвестно';
            }
        }

        $start = microtime( true );
        try {
            $probe_like = $plugin->engine->search_content( $groups, 1, 0, '', [], [] );
            $out['like']['ran']        = true;
            $out['like']['total']      = (int) ( $probe_like['total'] ?? 0 );
            $out['like']['items']      = array_slice( (array) ( $probe_like['items'] ?? [] ), 0, 5 );
            $out['like']['elapsed_ms'] = round( ( microtime( true ) - $start ) * 1000, 2 );
        } catch ( \Throwable $e ) {
            $out['like']['ran']    = false;
            $out['like']['reason'] = 'EXCEPTION: ' . sanitize_text_field( $e->getMessage() );
        }

        $out['diagnosis'] = $this->diagnose( $out );

        return $out;
    }

    private function diagnose( array $out ): array {
        $d = [];

        if ( ! empty( $out['error'] ) ) {
            $d[] = 'ОШИБКА: ' . (string) $out['error'];
            return $d;
        }

        if ( $out['sanitized'] === '' ) {
            $d[] = 'Запрос пустой после sanitize — искать нечего.';
            return $d;
        }

        if ( empty( $out['flat_tokens'] ) ) {
            $d[] = 'Запрос не дал ни одного токена (все слова короче минимальной длины).';
            return $d;
        }

        if ( ! $out['index_exists'] ) {
            $d[] = 'Индекс не создан → FULLTEXT не работает, поиск всегда идёт через LIKE-fallback. '
                 . 'Решение: Настройки → WP Smart Search → «Пересоздать таблицу».';
        } elseif ( $out['index_count'] === 0 ) {
            $d[] = 'Индекс пуст (0 записей) → FULLTEXT не работает, поиск идёт через LIKE-fallback. '
                 . 'Решение: «Переиндексировать».';
        } elseif ( $out['index_count'] < $out['index_total_posts'] ) {
            $d[] = sprintf(
                'Индекс заполнен не полностью (%d из %d записей) — часть контента не проиндексирована. '
                . 'Решение: «Переиндексировать».',
                (int) $out['index_count'],
                (int) $out['index_total_posts']
            );
        }

        $missing = [];
        if ( ! empty( $out['stem_probe'] ) ) {
            foreach ( $out['stem_probe'] as $p ) {
                if ( (int) $p['total_rows'] === 0 ) {
                    $missing[] = $p['stem'];
                }
            }
        }
        if ( ! empty( $missing ) ) {
            $d[] = 'Следующие токены отсутствуют в индексе полностью: '
                 . implode( ', ', $missing )
                 . '. Возможные причины: стеммер дал форму, которой нет в индексе; запись не проиндексирована; '
                 . 'опечатка; слово не встречается в контенте.';
        }

        $ft = $out['fulltext'];
        $lk = $out['like'];

        if ( ! $ft['ran'] ) {
            $d[] = sprintf(
                'FULLTEXT не запускался (причина: %s). LIKE-поиск нашёл: %d.',
                (string) $ft['reason'],
                (int) $lk['total']
            );
        } else {
            $ft_total = (int) $ft['total'];
            $lk_total = (int) $lk['total'];

            if ( $ft_total > 0 && $lk_total === 0 ) {
                $d[] = sprintf(
                    'FULLTEXT нашёл %d, LIKE — 0. Это ожидаемо, если в запросе стем («уязвим»), '
                    . 'а в тексте слово в другой форме («уязвимость»). FULLTEXT работает корректно.',
                    $ft_total
                );
            } elseif ( $ft_total === 0 && $lk_total > 0 ) {
                $d[] = sprintf(
                    'FULLTEXT не нашёл ничего, но LIKE-поиск нашёл %d. '
                    . 'Причина: стемы в запросе не совпадают со стемами в индексе. '
                    . 'Смотрите блок «Стемы в индексе» — если стем отсутствует, значит индексация дала другую форму '
                    . 'или запись не проиндексирована.',
                    $lk_total
                );
            } elseif ( $ft_total === 0 && $lk_total === 0 ) {
                $d[] = 'Оба движка нашли 0. Скорее всего, данных по этому запросу нет. '
                     . 'Проверьте блок «Стемы в индексе» — если хотя бы один токен отсутствует, '
                     . 'значит слово не встречается в контенте.';
            } elseif ( $ft_total === $lk_total ) {
                $d[] = sprintf( 'Оба движка нашли одинаковое количество (%d). Всё в порядке.', $ft_total );
            } else {
                $d[] = sprintf(
                    'FULLTEXT нашёл %d, LIKE — %d. Разница ожидаема: FULLTEXT ищет по стемам (даёт больше совпадений '
                    . 'по формам слова), LIKE — только точное вхождение подстроки. Расхождение не означает ошибку.',
                    $ft_total,
                    $lk_total
                );
            }

            if ( $ft['ran'] && $ft_total > 0 && (float) $ft['elapsed_ms'] > 1000 ) {
                $d[] = sprintf(
                    'FULLTEXT выполняется медленно (%.2f ms). Возможно, большая таблица индекса или тяжёлый запрос.',
                    (float) $ft['elapsed_ms']
                );
            }
        }

        if ( ! empty( $out['technical_tokens'] ) ) {
            $d[] = 'В запросе есть технические ID: ' . implode( ', ', $out['technical_tokens'] )
                 . '. Они не стеммятся и ищутся точным совпадением подстроки в индексе.';
        }

        return $d;
    }

    private function build_search_report( array $data ): string {
        $query  = (string) ( $data['query']  ?? '' );
        $result = is_array( $data['result'] ?? null ) ? $data['result'] : [];
        $time   = (string) ( $data['time']   ?? '' );

        $lines = [];
        $lines[] = '=== WSSF SEARCH TEST ===';
        $lines[] = 'ran_at           : ' . sanitize_text_field( $time );
        $lines[] = 'query            : ' . sanitize_text_field( $query );

        if ( isset( $result['error'] ) && $result['error'] !== null ) {
            $lines[] = 'error            : ' . sanitize_text_field( (string) $result['error'] );
            return implode( "\n", $lines );
        }

        $lines[] = 'sanitized        : ' . sanitize_text_field( (string) ( $result['sanitized'] ?? '' ) );
        $groups_json = wp_json_encode( $result['parsed_groups'] ?? [], JSON_UNESCAPED_UNICODE );
        $lines[] = 'parsed_groups    : ' . ( is_string( $groups_json ) ? $groups_json : '[]' );
        $lines[] = 'flat_tokens      : ' . sanitize_text_field( implode( ', ', (array) ( $result['flat_tokens'] ?? [] ) ) );
        $lines[] = 'regular_tokens   : ' . sanitize_text_field( implode( ', ', (array) ( $result['regular_tokens'] ?? [] ) ) );
        $lines[] = 'technical_tokens : ' . sanitize_text_field( implode( ', ', (array) ( $result['technical_tokens'] ?? [] ) ) );
        $lines[] = 'boolean_string   : ' . sanitize_text_field( (string) ( $result['boolean_string'] ?? '' ) );
        $lines[] = '';

        $lines[] = '# INDEX';
        $lines[] = 'exists           : ' . ( ! empty( $result['index_exists'] ) ? 'yes' : 'no' );
        $lines[] = 'count            : ' . (int) ( $result['index_count'] ?? 0 );
        $lines[] = 'total_posts      : ' . (int) ( $result['index_total_posts'] ?? 0 );
        $lines[] = 'updated_at       : ' . sanitize_text_field( (string) ( $result['index_updated_at'] ?? '' ) );
        $lines[] = '';

        $lines[] = '# STEMS IN INDEX';
        $probes = $result['stem_probe'] ?? [];
        if ( empty( $probes ) ) {
            $lines[] = '(пусто — индекс не создан, пуст, или в запросе нет токенов)';
        } else {
            $lines[] = sprintf(
                '%-32s  %-4s  %-5s  %-7s  %-7s  %-5s  %s',
                'stem', 'tech', 'title', 'content', 'excerpt', 'tags', 'total'
            );
            foreach ( $probes as $p ) {
                $lines[] = sprintf(
                    '%-32s  %-4s  %-5d  %-7d  %-7d  %-5d  %d',
                    sanitize_text_field( (string) $p['stem'] ),
                    ! empty( $p['is_technical'] ) ? 'yes' : 'no',
                    (int) $p['in_title'],
                    (int) $p['in_content'],
                    (int) $p['in_excerpt'],
                    (int) $p['in_tags'],
                    (int) $p['total_rows']
                );
            }
        }
        $lines[] = '';

        $ft = $result['fulltext'] ?? [];
        $lines[] = '# FULLTEXT';
        $lines[] = 'ran              : ' . ( ! empty( $ft['ran'] ) ? 'yes' : 'no' );
        if ( ! empty( $ft['ran'] ) ) {
            $lines[] = 'total            : ' . (int) ( $ft['total'] ?? 0 );
            $lines[] = 'elapsed_ms       : ' . number_format( (float) ( $ft['elapsed_ms'] ?? 0 ), 2, '.', '' );
            $items = (array) ( $ft['items'] ?? [] );
            if ( empty( $items ) ) {
                $lines[] = 'top_results      : (пусто)';
            } else {
                $lines[] = 'top_results      :';
                foreach ( $items as $i => $item ) {
                    $lines[] = sprintf(
                        '  [%d] id=%d, "%s"',
                        $i + 1,
                        (int) ( $item['id'] ?? 0 ),
                        sanitize_text_field( (string) ( $item['title'] ?? '' ) )
                    );
                }
            }
        } else {
            $lines[] = 'reason           : ' . sanitize_text_field( (string) ( $ft['reason'] ?? '' ) );
        }
        $lines[] = '';

        $lk = $result['like'] ?? [];
        $lines[] = '# LIKE (fallback)';
        $lines[] = 'ran              : ' . ( ! empty( $lk['ran'] ) ? 'yes' : 'no' );
        if ( ! empty( $lk['ran'] ) ) {
            $lines[] = 'total            : ' . (int) ( $lk['total'] ?? 0 );
            $lines[] = 'elapsed_ms       : ' . number_format( (float) ( $lk['elapsed_ms'] ?? 0 ), 2, '.', '' );
            $items = (array) ( $lk['items'] ?? [] );
            if ( empty( $items ) ) {
                $lines[] = 'top_results      : (пусто)';
            } else {
                $lines[] = 'top_results      :';
                foreach ( $items as $i => $item ) {
                    $lines[] = sprintf(
                        '  [%d] id=%d, "%s"',
                        $i + 1,
                        (int) ( $item['id'] ?? 0 ),
                        sanitize_text_field( (string) ( $item['title'] ?? '' ) )
                    );
                }
            }
        } else {
            $lines[] = 'reason           : ' . sanitize_text_field( (string) ( $lk['reason'] ?? '' ) );
        }
        $lines[] = '';

        $lines[] = '# DIAGNOSIS';
        $diag = $result['diagnosis'] ?? [];
        if ( empty( $diag ) ) {
            $lines[] = '(нет замечаний)';
        } else {
            foreach ( $diag as $line ) {
                $lines[] = '- ' . sanitize_text_field( (string) $line );
            }
        }

        return implode( "\n", $lines );
    }

    /* ============================================================
     *  CleanTalk — сценарии
     * ============================================================ */

    private function execute_all_scenarios( string $query ): array {
        if ( ! function_exists( 'apbct_base_call' ) ) {
            return [ 'error' => 'CleanTalk не обнаружен — тест недоступен' ];
        }
        if ( ! WSSF_CleanTalk::active() ) {
            return [ 'error' => 'Проверка CleanTalk выключена режимом «выключена» — тест не выполняется' ];
        }
        $scenarios = [
            'default_no_exception_action' => [
                'label'            => 'БЕЗ exception_action',
                'strip_cookies'    => true,
                'strip_post'       => true,
                'reset_executed'   => true,
                'comment_type'     => 'site_search_wordpress',
                'exception_action' => null,
                'sender_email'     => '',
            ],
            'exception_zero' => [
                'label'            => 'exception_action=0 (sender_email="")',
                'strip_cookies'    => true,
                'strip_post'       => true,
                'reset_executed'   => true,
                'comment_type'     => 'site_search_wordpress',
                'exception_action' => 0,
                'sender_email'     => '',
            ],
            'exception_zero_with_email' => [
                'label'            => 'exception_action=0 + sender_email=noreply@host',
                'strip_cookies'    => true,
                'strip_post'       => true,
                'reset_executed'   => true,
                'comment_type'     => 'site_search_wordpress',
                'exception_action' => 0,
                'sender_email'     => 'noreply@' . wp_parse_url( home_url(), PHP_URL_HOST ),
            ],
            'exception_zero_with_cookies' => [
                'label'            => 'exception_action=0 + cookies НЕ очищены',
                'strip_cookies'    => false,
                'strip_post'       => false,
                'reset_executed'   => true,
                'comment_type'     => 'site_search_wordpress',
                'exception_action' => 0,
                'sender_email'     => '',
            ],
        ];
        $out = [];
        foreach ( $scenarios as $key => $sc ) {
            $out[ $key ] = $this->run_scenario( $sc, $query );
        }
        return $out;
    }

    private function run_scenario( array $sc, string $query ): array {
        global $cleantalk_executed, $apbct;

        $saved_post        = $_POST;
        $saved_cookie      = $_COOKIE;
        $saved_request     = $_REQUEST;
        $saved_exec        = $cleantalk_executed;
        $saved_request_ids = is_object( $apbct ) && isset( $apbct->plugin_request_ids )
            ? $apbct->plugin_request_ids : null;
        $saved_force       = null;

        if ( class_exists( '\\Cleantalk\\ApbctWP\\Variables\\Cookie' ) ) {
            $saved_force = \Cleantalk\ApbctWP\Variables\Cookie::$force_alt_cookies_global ?? null;
        }

        if ( $sc['strip_cookies'] ) {
            foreach ( [
                'ct_checkjs', 'ct_pointer_data', 'ct_fkp_timestamp', 'ct_ps_timestamp',
                'ct_screen_info', 'ct_timezone', 'ct_has_scrolled', 'ct_mouse_moved',
                'ct_has_key_up', 'ct_has_input_focused', 'ct_bot_detector_event_token',
                'apbct_cookies_test', 'apbct_headless', 'ct_gathering_loaded',
                'apbct_prev_referer', 'apbct_site_landing_ts', 'apbct_page_hits',
                'apbct_alt_session',
            ] as $k ) { unset( $_COOKIE[ $k ] ); }
        }

        if ( $sc['strip_post'] ) { $_POST = []; }
        $_POST['apbct_do_not_exclude'] = 1;

        if ( $sc['reset_executed'] ) { $cleantalk_executed = false; }
        if ( is_object( $apbct ) ) { $apbct->plugin_request_ids = []; }

        $post_url = home_url( '/?s=' . rawurlencode( $query ) );

        $params = [
            'message'         => $query,
            'sender_email'    => $sc['sender_email'],
            'sender_nickname' => '',
            'js_on'           => 0,
            'post_info'       => [
                'comment_type' => $sc['comment_type'],
                'post_url'     => $post_url,
            ],
            'sender_info'     => [ 'form_name' => 'WSSF Diagnostics' ],
        ];
        if ( $sc['exception_action'] !== null ) {
            $params['exception_action'] = $sc['exception_action'];
        }

        $result = null;
        $exc    = null;
        try {
            $result = apbct_base_call( $params );
        } catch ( \Throwable $e ) {
            $exc = sanitize_text_field( $e->getMessage() );
        }

        $_POST    = $saved_post;
        $_COOKIE  = $saved_cookie;
        $_REQUEST = $saved_request;
        $cleantalk_executed = $saved_exec;
        if ( is_object( $apbct ) && $saved_request_ids !== null ) {
            $apbct->plugin_request_ids = $saved_request_ids;
        }
        if ( $saved_force !== null && class_exists( '\\Cleantalk\\ApbctWP\\Variables\\Cookie' ) ) {
            \Cleantalk\ApbctWP\Variables\Cookie::$force_alt_cookies_global = $saved_force;
        }

        $allow          = null;
        $spam           = null;
        $errno          = null;
        $comment        = '';
        $received       = null;
        $account_status = null;
        $api_id         = '';
        $codes          = [];
        if ( is_array( $result ) && isset( $result['ct_result'] ) && is_object( $result['ct_result'] ) ) {
            $allow          = isset( $result['ct_result']->allow )          ? (int) $result['ct_result']->allow          : null;
            $spam           = isset( $result['ct_result']->spam )           ? (int) $result['ct_result']->spam           : null;
            $errno          = isset( $result['ct_result']->errno )          ? (int) $result['ct_result']->errno          : null;
            $received       = isset( $result['ct_result']->received )       ? (int) $result['ct_result']->received       : null;
            $account_status = isset( $result['ct_result']->account_status ) ? (int) $result['ct_result']->account_status : null;
            $comment        = isset( $result['ct_result']->comment )        ? sanitize_text_field( (string) $result['ct_result']->comment ) : '';
            $api_id         = isset( $result['ct_result']->id )             ? (string) $result['ct_result']->id          : '';
            $codes          = isset( $result['ct_result']->codes ) && is_array( $result['ct_result']->codes )
                ? array_map( 'sanitize_text_field', $result['ct_result']->codes ) : [];
        }
        $error_raw = is_array( $result ) && isset( $result['error'] ) ? $result['error'] : null;

        return [
            'label'             => $sc['label'],
            'comment_type'      => $sc['comment_type'],
            'exception_action'  => $sc['exception_action'],
            'sender_email'      => $sc['sender_email'],
            'strip_cookies'     => $sc['strip_cookies'],
            'strip_post'        => $sc['strip_post'],
            'reset_executed'    => $sc['reset_executed'],
            'executed_before'   => (bool) $saved_exec,
            'allow'             => $allow,
            'spam'              => $spam,
            'errno'             => $errno,
            'received'          => $received,
            'account_status'    => $account_status,
            'api_called'        => ( $api_id !== '' ),
            'api_id'            => $api_id,
            'codes'             => $codes,
            'comment'           => $comment,
            'error'             => $error_raw,
            'exception'         => $exc,
            'raw_result'        => $result,
        ];
    }

    /* ============================================================
     *  Отчёты CleanTalk
     * ============================================================ */

    private function build_fallback_report(): string {
        $env = $this->get_env_data();
        $out = [];
        $out[] = '=== WSSF DIAGNOSTICS ===';
        $out[] = 'generated_at : ' . current_time( 'mysql' );
        $out[] = 'version      : ' . ( defined( 'WSSF_VERSION' ) ? WSSF_VERSION : '?' );
        $out[] = 'site_url     : ' . home_url( '/' );
        $out[] = '';
        $out[] = '# ENV';
        $out[] = 'PHP          : ' . $env['php_version'];
        $out[] = 'WordPress    : ' . $env['wp_version'];
        $out[] = 'MySQL        : ' . $env['mysql_version'];
        $out[] = 'mbstring     : ' . $env['mbstring'];
        $out[] = 'theme        : ' . $env['theme_template'] . ( $env['theme_is_community'] === 'yes' ? ' (WPCommunity recognised)' : ' (not WPCommunity)' );
        $out[] = 'rate limit   : ' . ( $env['rate_limit'] > 0 ? (string) (int) $env['rate_limit'] . '/min' : 'off' );
        $out[] = 'redis ext    : ' . $env['redis_ext'];
        $out[] = 'cache backend: ' . $env['cache_backend'] . ( $env['cache_backoff'] > 0 ? ' (backoff ' . (int) $env['cache_backoff'] . 's)' : '' );
        $out[] = 'redis server : ' . ( $env['redis_server_version'] !== '' ? $env['redis_server_version'] : 'N/A' );
        $out[] = 'redis client : ' . ( $env['redis_client_version'] !== '' ? $env['redis_client_version'] : 'N/A' );
        $out[] = 'diag_enabled : ' . ( $env['diag_enabled'] ? 'true' : 'false' );
        $out[] = '';
        $out[] = 'Нажмите «Запустить тест» для проверки CleanTalk.';
        return implode( "\n", $out );
    }

    private function build_report( $results, $last_ajax ): string {
        $out = [];
        $out[] = '=== WSSF DIAGNOSTICS ===';
        $out[] = 'generated_at : ' . current_time( 'mysql' );
        $out[] = 'version      : ' . ( defined( 'WSSF_VERSION' ) ? WSSF_VERSION : '?' );
        $out[] = 'site_url     : ' . home_url( '/' );
        $out[] = 'diag_enabled : ' . ( apply_filters( 'wssf_diag_enabled', false ) ? 'true' : 'false' );
        $out[] = '';

        try {
            $env = $this->get_env_data();
            $out[] = '# ENV';
            $out[] = 'php_version          : ' . $env['php_version'];
            $out[] = 'wp_version           : ' . $env['wp_version'];
            $out[] = 'mysql_version        : ' . $env['mysql_version'];
            $out[] = 'mbstring             : ' . $env['mbstring'];
            $out[] = 'redis_ext            : ' . $env['redis_ext'];
            $out[] = 'theme                : ' . $env['theme_template'] . ( $env['theme_is_community'] === 'yes' ? ' (WPCommunity recognised)' : ' (not WPCommunity)' );
            $out[] = 'rate_limit           : ' . ( $env['rate_limit'] > 0 ? (string) (int) $env['rate_limit'] . '/min' : 'off' );
            $out[] = 'cache_backend        : ' . $env['cache_backend'] . ( $env['cache_backoff'] > 0 ? ' (backoff ' . (int) $env['cache_backoff'] . 's)' : '' );
            $out[] = 'redis_server_version : ' . ( $env['redis_server_version'] !== '' ? $env['redis_server_version'] : 'N/A' );
            $out[] = 'redis_client_version : ' . ( $env['redis_client_version'] !== '' ? $env['redis_client_version'] : 'N/A' );
            $out[] = 'cache_generation     : ' . (string) $env['cache_generation'];
            $out[] = 'index_table_exists   : ' . ( $env['index_table_exists'] ? 'yes' : 'no' );
            $out[] = 'index_count          : ' . (string) $env['index_count'];
            $out[] = 'index_total_posts    : ' . (string) $env['index_total_posts'];
            $out[] = 'cleantalk_active     : ' . $env['cleantalk_active'];
            $out[] = 'cleantalk_mode       : ' . $env['cleantalk_mode'];
            $out[] = 'cleantalk_effective  : ' . $env['cleantalk_effective'];
            $out[] = 'cleantalk_key_ok     : ' . $env['cleantalk_key_ok'];
            $out[] = 'cleantalk_cookies    : ' . $env['cleantalk_cookies'];
        } catch ( \Throwable $e ) {
            $out[] = '# ENV — ERROR: ' . sanitize_text_field( $e->getMessage() );
        }
        $out[] = '';

        $out[] = '# LAST AJAX';
        try {
            $out[] = $this->last_ajax_text( $last_ajax );
        } catch ( \Throwable $e ) {
            $out[] = 'ERROR: ' . sanitize_text_field( $e->getMessage() );
        }
        $out[] = '';

        $out[] = '# TEST RESULTS';
        if ( ! is_array( $results ) || ! isset( $results['results'] ) || ! is_array( $results['results'] ) ) {
            $out[] = 'status : not_run';
            $out[] = 'hint   : нажмите «Запустить тест» выше';
        } else {
            $out[] = 'run_time : ' . sanitize_text_field( (string) ( $results['time'] ?? '' ) );
            $out[] = 'query    : ' . sanitize_text_field( (string) ( $results['query'] ?? '' ) );
            $out[] = '';
            if ( isset( $results['results']['error'] ) && is_string( $results['results']['error'] ) ) {
                $out[] = 'error : ' . sanitize_text_field( $results['results']['error'] );
            } else {
                $idx = 0;
                foreach ( $results['results'] as $key => $res ) {
                    $idx++;
                    try {
                        $out[] = $this->scenario_text( $idx, (string) $key, $res );
                    } catch ( \Throwable $e ) {
                        $out[] = '[' . $idx . '] ' . sanitize_text_field( (string) $key ) . ' — SCENARIO_ERROR: ' . sanitize_text_field( $e->getMessage() );
                    }
                    $out[] = '';
                }
            }
        }

        return implode( "\n", $out );
    }

    private function last_ajax_text( $data ): string {
        if ( ! is_array( $data ) ) {
            $hint = 'сделайте AJAX-поиск на фронте, затем вернитесь на эту страницу';
            if ( ! apply_filters( 'wssf_diag_enabled', false ) ) {
                $hint .= "\n         (transient отключён: включите фильтр wssf_diag_enabled=true в functions.php)";
            }
            return "status : never_run\nhint   : " . $hint;
        }
        $lines = [];
        foreach ( [
            'time','query','strict','has_cache','cache_hit','cache_age','cache_stored',
            'executed_before','api_called','api_id','allow','spam','errno','codes',
            'comment','exception','api_rate_limited','cache_hit_after_lock','lock_timeout_allow',
        ] as $key ) {
            if ( ! array_key_exists( $key, $data ) ) { continue; }
            $v = $data[ $key ];
            if ( is_bool( $v ) ) { $v = $v ? 'true' : 'false'; }
            elseif ( is_array( $v ) ) { $v = implode( ',', array_map( 'sanitize_text_field', $v ) ); }
            $lines[] = sprintf( '%-20s : %s', $key, sanitize_text_field( (string) $v ) );
        }
        $lines[] = '';
        $lines[] = '-- raw_result (JSON) --';
        $lines[] = $this->json( $data['raw_result'] ?? null );
        return implode( "\n", $lines );
    }

    private function scenario_text( int $idx, string $key, $res ): string {
        if ( ! is_array( $res ) ) {
            return sprintf( '## [%d] %s — UNKNOWN (non-array: %s)', $idx, sanitize_text_field( $key ), gettype( $res ) );
        }
        $allow  = $res['allow'] ?? null;
        $called = ! empty( $res['api_called'] );
        if ( ! $called ) { $verdict = 'API_NOT_CALLED'; }
        elseif ( $allow === 0 ) { $verdict = 'BLOCKED'; }
        elseif ( $allow === 1 ) { $verdict = 'ALLOWED'; }
        else { $verdict = 'UNKNOWN'; }

        $lines = [];
        $lines[] = sprintf( '## [%d] %s → %s', $idx, sanitize_text_field( $key ), $verdict );
        $lines[] = sprintf( 'label             : %s', sanitize_text_field( (string) ( $res['label'] ?? '' ) ) );
        $lines[] = sprintf( 'comment_type      : %s', sanitize_text_field( (string) ( $res['comment_type'] ?? '' ) ) );
        $lines[] = sprintf( 'exception_action  : %s', $res['exception_action'] === null ? '(not passed)' : sanitize_text_field( (string) $res['exception_action'] ) );
        $lines[] = sprintf( 'sender_email      : %s', empty( $res['sender_email'] ) ? '(empty)' : sanitize_text_field( (string) $res['sender_email'] ) );
        $lines[] = sprintf( 'strip_cookies     : %s', ! empty( $res['strip_cookies'] ) ? 'yes' : 'no' );
        $lines[] = sprintf( 'strip_post        : %s', ! empty( $res['strip_post'] ) ? 'yes' : 'no' );
        $lines[] = sprintf( 'executed_before   : %s', ! empty( $res['executed_before'] ) ? 'true' : 'false' );
        $lines[] = sprintf( 'api_called        : %s', $called ? 'yes' : 'no' );
        $lines[] = sprintf( 'api_id            : %s', ! empty( $res['api_id'] ) ? sanitize_text_field( (string) $res['api_id'] ) : '(empty)' );
        $lines[] = sprintf( 'received          : %s', $res['received'] === null ? 'null' : sanitize_text_field( (string) $res['received'] ) );
        $lines[] = sprintf( 'account_status    : %s', $res['account_status'] === null ? 'null' : sanitize_text_field( (string) $res['account_status'] ) );
        $lines[] = sprintf( 'allow             : %s', $allow === null ? 'null' : sanitize_text_field( (string) $allow ) );
        $lines[] = sprintf( 'spam              : %s', ( $res['spam'] ?? null ) === null ? 'null' : sanitize_text_field( (string) $res['spam'] ) );
        $lines[] = sprintf( 'errno             : %s', ( $res['errno'] ?? null ) === null ? 'null' : sanitize_text_field( (string) $res['errno'] ) );
        $lines[] = sprintf( 'codes             : %s', ! empty( $res['codes'] ) ? sanitize_text_field( implode( ',', (array) $res['codes'] ) ) : '(empty)' );
        $lines[] = sprintf( 'comment           : %s', ! empty( $res['comment'] ) ? sanitize_text_field( (string) $res['comment'] ) : '(empty)' );
        if ( ! empty( $res['exception'] ) ) {
            $lines[] = sprintf( 'exception         : %s', sanitize_text_field( (string) $res['exception'] ) );
        }
        if ( ! empty( $res['error'] ) ) {
            $lines[] = sprintf( 'error             : %s',
                is_array( $res['error'] ) ? $this->json( $res['error'] ) : sanitize_text_field( (string) $res['error'] ) );
        }
        $lines[] = '';
        $lines[] = '-- raw_result (JSON) --';
        $lines[] = $this->json( $res['raw_result'] ?? null );
        return implode( "\n", $lines );
    }

    private function json( $value ): string {
        $json = wp_json_encode(
            $value,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        return is_string( $json ) ? $json : 'null';
    }
}