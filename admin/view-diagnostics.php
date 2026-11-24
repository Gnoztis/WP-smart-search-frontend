<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! current_user_can( 'manage_options' ) ) { return; }

if ( ! isset( $report ) || ! is_string( $report ) ) { $report = ''; }
if ( ! isset( $search_report ) || ! is_string( $search_report ) ) { $search_report = ''; }
if ( ! isset( $search_last_query ) ) { $search_last_query = ''; }
if ( ! isset( $action_url ) ) { $action_url = admin_url( 'admin-post.php' ); }

$diag = \WSSF\Admin_Diagnostics::instance();
$env  = $diag->get_env_data();
$diag_enabled = (bool) $env['diag_enabled'];
?>

<!-- Окружение -->
<div class="card wssf-card">
    <h2><span class="dashicons dashicons-info"></span>
        <?php esc_html_e( 'Окружение', 'wp-smart-search-frontend' ); ?>
    </h2>

    <table class="wssf-env-table widefat striped">
        <tbody>
            <tr>
                <th><?php esc_html_e( 'PHP', 'wp-smart-search-frontend' ); ?></th>
                <td><?php echo esc_html( $env['php_version'] ); ?></td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'WordPress', 'wp-smart-search-frontend' ); ?></th>
                <td><?php echo esc_html( $env['wp_version'] ); ?></td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'MySQL', 'wp-smart-search-frontend' ); ?></th>
                <td><?php echo esc_html( $env['mysql_version'] ); ?></td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'mbstring', 'wp-smart-search-frontend' ); ?></th>
                <td>
                    <span class="<?php echo $env['mbstring'] === 'yes' ? 'ok' : 'bad'; ?>">
                        <?php echo esc_html( $env['mbstring'] ); ?>
                    </span>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Redis extension', 'wp-smart-search-frontend' ); ?></th>
                <td>
                    <span class="<?php echo $env['redis_ext'] === 'yes' ? 'ok' : 'bad'; ?>">
                        <?php echo esc_html( $env['redis_ext'] ); ?>
                    </span>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Тема и лимит запросов', 'wp-smart-search-frontend' ); ?></th>
                <td>
                    <code><?php echo esc_html( $env['theme_template'] !== '' ? (string) $env['theme_template'] : '—' ); ?></code>
                    · <?php esc_html_e( 'WPCommunity:', 'wp-smart-search-frontend' ); ?>
                    <span class="<?php echo esc_attr( $env['theme_is_community'] === 'yes' ? 'ok' : 'bad' ); ?>">
                        <?php echo esc_html( $env['theme_is_community'] === 'yes'
                            ? __( 'распознана', 'wp-smart-search-frontend' )
                            : __( 'нет', 'wp-smart-search-frontend' ) ); ?>
                    </span>
                    · <?php esc_html_e( 'лимит для вас:', 'wp-smart-search-frontend' ); ?>
                    <strong><?php echo (int) $env['rate_limit']; ?></strong>
                    <?php esc_html_e( 'запросов в минуту', 'wp-smart-search-frontend' ); ?>
                    <?php if ( $env['theme_is_community'] !== 'yes' ) : ?>
                        <p class="description">
                            <?php esc_html_e( 'Повышенный лимит действует только на теме WPCommunity (и её дочерних); на другой теме авторизованный получает гостевой лимит.', 'wp-smart-search-frontend' ); ?>
                        </p>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Кэш результатов', 'wp-smart-search-frontend' ); ?></th>
                <td>
                    <span class="<?php echo esc_attr( $env['cache_backend'] === 'redis' ? 'ok' : 'bad' ); ?>">
                        <code><?php echo esc_html( (string) $env['cache_backend'] ); ?></code>
                    </span>
                    <?php if ( (int) $env['cache_backoff'] > 0 ) : ?>
                        · <?php esc_html_e( 'Redis не отвечает, повтор через', 'wp-smart-search-frontend' ); ?>
                        <?php echo (int) $env['cache_backoff']; ?> <?php esc_html_e( 'с', 'wp-smart-search-frontend' ); ?>
                    <?php elseif ( $env['cache_backend'] === 'none' ) : ?>
                        · <?php esc_html_e( 'кэширования нет, поиск работает напрямую', 'wp-smart-search-frontend' ); ?>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Redis (сервер)', 'wp-smart-search-frontend' ); ?></th>
                <td>
                    <?php if ( ! empty( $env['redis_server_version'] ) ) : ?>
                        <span class="ok"><?php echo esc_html( $env['redis_server_version'] ); ?></span>
                    <?php else : ?>
                        <span class="bad"><?php esc_html_e( 'недоступен', 'wp-smart-search-frontend' ); ?></span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Redis (клиент phpredis)', 'wp-smart-search-frontend' ); ?></th>
                <td>
                    <?php if ( ! empty( $env['redis_client_version'] ) ) : ?>
                        <span class="ok"><?php echo esc_html( $env['redis_client_version'] ); ?></span>
                    <?php else : ?>
                        <span class="bad"><?php esc_html_e( 'не установлено', 'wp-smart-search-frontend' ); ?></span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Cache generation', 'wp-smart-search-frontend' ); ?></th>
                <td><?php echo (int) $env['cache_generation']; ?></td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Индекс (таблица)', 'wp-smart-search-frontend' ); ?></th>
                <td>
                    <span class="<?php echo $env['index_table_exists'] ? 'ok' : 'bad'; ?>">
                        <?php echo $env['index_table_exists']
                            ? esc_html__( 'существует', 'wp-smart-search-frontend' )
                            : esc_html__( 'не создана', 'wp-smart-search-frontend' ); ?>
                    </span>
                    — <?php echo (int) $env['index_count']; ?> / <?php echo (int) $env['index_total_posts']; ?>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'CleanTalk', 'wp-smart-search-frontend' ); ?></th>
                <td>
                    <?php echo esc_html( $env['cleantalk_active'] ); ?>
                    · <?php esc_html_e( 'режим:', 'wp-smart-search-frontend' ); ?>
                    <code><?php echo esc_html( (string) $env['cleantalk_mode'] ); ?></code>
                    · <?php esc_html_e( 'проверка:', 'wp-smart-search-frontend' ); ?>
                    <span class="<?php echo esc_attr( $env['cleantalk_effective'] === 'yes' ? 'ok' : 'bad' ); ?>">
                        <?php echo esc_html( $env['cleantalk_effective'] === 'yes'
                            ? __( 'выполняется', 'wp-smart-search-frontend' )
                            : __( 'не выполняется', 'wp-smart-search-frontend' ) ); ?>
                    </span>
                    <?php if ( $env['cleantalk_active'] === 'yes' ) : ?>
                        · <?php esc_html_e( 'key_is_ok:', 'wp-smart-search-frontend' ); ?>
                        <span class="<?php echo $env['cleantalk_key_ok'] === 'yes' ? 'ok' : 'bad'; ?>">
                            <?php echo esc_html( $env['cleantalk_key_ok'] ); ?>
                        </span>
                        · cookies: <code><?php echo esc_html( $env['cleantalk_cookies'] ); ?></code>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Сбор AJAX-диагностики', 'wp-smart-search-frontend' ); ?></th>
                <td>
                    <span class="<?php echo $diag_enabled ? 'ok' : 'bad'; ?>">
                        <?php echo $diag_enabled
                            ? esc_html__( 'включён', 'wp-smart-search-frontend' )
                            : esc_html__( 'выключен', 'wp-smart-search-frontend' ); ?>
                    </span>
                    <?php if ( ! $diag_enabled ) : ?>
                        — <code>add_filter( 'wssf_diag_enabled', '__return_true' );</code>
                    <?php endif; ?>
                </td>
            </tr>
        </tbody>
    </table>
</div>

<!-- Тест поиска -->
<div class="card wssf-card">
    <h2><span class="dashicons dashicons-search"></span>
        <?php esc_html_e( 'Тест поиска', 'wp-smart-search-frontend' ); ?>
    </h2>

    <?php if ( isset( $_GET['search_ran'] ) ) : ?>
        <div class="notice notice-info inline"><p>
            <?php esc_html_e( 'Тест поиска выполнен. Результаты ниже.', 'wp-smart-search-frontend' ); ?>
        </p></div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url( $action_url ); ?>" class="wssf-diag-form">
        <?php wp_nonce_field( 'wssf_diag_search' ); ?>
        <input type="hidden" name="action" value="wssf_diag_search">
        <label for="wssf-diag-search-query" class="screen-reader-text">
            <?php esc_html_e( 'Поисковый запрос', 'wp-smart-search-frontend' ); ?>
        </label>
        <input type="text"
               id="wssf-diag-search-query"
               name="search_query"
               value="<?php echo esc_attr( $search_last_query !== '' ? $search_last_query : 'тест' ); ?>"
               placeholder="<?php esc_attr_e( 'Поисковый запрос', 'wp-smart-search-frontend' ); ?>"
               class="regular-text">
        <button type="submit" class="button button-primary">
            <span class="dashicons dashicons-controls-play"></span>
            <?php esc_html_e( 'Проверить поиск', 'wp-smart-search-frontend' ); ?>
        </button>
    </form>

    <p class="description">
        <?php esc_html_e(
            'Прогоняет запрос через ОБА алгоритма — FULLTEXT (индекс) и LIKE (fallback) — независимо. '
            . 'Показывает парсинг (стемы), проверку каждого стема в индексе, результаты обоих движков '
            . 'и автоматический диагноз: почему не найдено и где именно «ломается» поиск.',
            'wp-smart-search-frontend'
        ); ?>
    </p>

    <textarea readonly
              id="wssf-diag-search-report"
              class="wssf-diag-textarea"
              spellcheck="false"
              onclick="this.select()"><?php echo esc_textarea( $search_report ); ?></textarea>
</div>

<!-- Тест CleanTalk -->
<div class="card wssf-card">
    <h2><span class="dashicons dashicons-shield-alt"></span>
        <?php esc_html_e( 'Тест CleanTalk', 'wp-smart-search-frontend' ); ?>
    </h2>

    <?php if ( isset( $_GET['ran'] ) ) : ?>
        <div class="notice notice-info inline"><p>
            <?php esc_html_e( 'Тест CleanTalk выполнен. Результаты ниже.', 'wp-smart-search-frontend' ); ?>
        </p></div>
    <?php endif; ?>

    <form method="post" action="<?php echo esc_url( $action_url ); ?>" class="wssf-diag-form">
        <?php wp_nonce_field( 'wssf_diag_run' ); ?>
        <input type="hidden" name="action" value="wssf_diag_run">
        <label for="wssf-diag-query" class="screen-reader-text">
            <?php esc_html_e( 'Тестовый запрос', 'wp-smart-search-frontend' ); ?>
        </label>
        <input type="text"
               id="wssf-diag-query"
               name="test_query"
               value="<?php echo esc_attr( 'viagra casino cheap' ); ?>"
               placeholder="<?php esc_attr_e( 'Тестовый запрос', 'wp-smart-search-frontend' ); ?>"
               class="regular-text">
        <button type="submit" class="button button-primary">
            <span class="dashicons dashicons-controls-play"></span>
            <?php esc_html_e( 'Запустить тест', 'wp-smart-search-frontend' ); ?>
        </button>
    </form>

    <p class="description">
        <?php esc_html_e( '4 сценария: без exception_action, exception_action=0, + email, + cookies. Клик по полю ниже — выделить всё.', 'wp-smart-search-frontend' ); ?>
    </p>

    <textarea readonly
              id="wssf-diag-report"
              class="wssf-diag-textarea"
              spellcheck="false"
              onclick="this.select()"><?php echo esc_textarea( $report ); ?></textarea>
</div>