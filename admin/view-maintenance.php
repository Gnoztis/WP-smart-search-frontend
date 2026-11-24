<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! current_user_can( 'manage_options' ) ) { return; }

$stats = \WSSF\Admin_Actions::get_index_stats();

$cron_status   = \WSSF\Hooks::instance( \WSSF\Plugin::instance() )->get_cron_status();
$cron_on       = (bool) $cron_status['enabled'];
$cron_next     = (int) $cron_status['next_run'];
$cron_last_run = (string) $cron_status['last_run'];
$cron_last_res = is_array( $cron_status['last_result'] ) ? $cron_status['last_result'] : [];
?>

<div class="card wssf-card">
    <h2><span class="dashicons dashicons-admin-tools"></span>
        <?php esc_html_e( 'Обслуживание', 'wp-smart-search-frontend' ); ?>
    </h2>

    <p class="description">
        <?php esc_html_e(
            'Очистка индекса и кешей. Действия необратимы; после сброса индекса поиск временно работает через LIKE-fallback.',
            'wp-smart-search-frontend'
        ); ?>
    </p>

    <!-- Кеш поиска -->
    <h3 class="wssf-maint-title"><?php esc_html_e( 'Кеш поиска', 'wp-smart-search-frontend' ); ?></h3>
    <p class="description">
        <?php esc_html_e(
            'Результаты поиска, метки, счётчики, PRF-расширения, fuzzy-альтернативы, MinHash-подписи, co-occurrence. После очистки следующий запрос пересчитает всё заново.',
            'wp-smart-search-frontend'
        ); ?>
    </p>

    <div class="wssf-maintenance-row">
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wssf-inline-form">
            <?php wp_nonce_field( 'wssf_clear_cache' ); ?>
            <input type="hidden" name="action" value="wssf_clear_cache">
            <button type="submit" class="button">
                <span class="dashicons dashicons-trash"></span>
                <?php esc_html_e( 'Очистить кеш поиска', 'wp-smart-search-frontend' ); ?>
            </button>
        </form>
    </div>

    <!-- CleanTalk -->
    <h3 class="wssf-maint-title"><?php esc_html_e( 'CleanTalk', 'wp-smart-search-frontend' ); ?></h3>
    <p class="description">
        <?php esc_html_e(
            'Вердикты CleanTalk по IP+запросу (TTL 60 сек / 1 час) и лок-ключи, блокирующие параллельные вызовы API.',
            'wp-smart-search-frontend'
        ); ?>
    </p>

    <div class="wssf-maintenance-row">
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wssf-inline-form">
            <?php wp_nonce_field( 'wssf_clear_ct_cache' ); ?>
            <input type="hidden" name="action" value="wssf_clear_ct_cache">
            <button type="submit" class="button">
                <span class="dashicons dashicons-shield"></span>
                <?php esc_html_e( 'Очистить вердикты CleanTalk', 'wp-smart-search-frontend' ); ?>
            </button>
        </form>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wssf-inline-form">
            <?php wp_nonce_field( 'wssf_clear_rate_limit' ); ?>
            <input type="hidden" name="action" value="wssf_clear_rate_limit">
            <button type="submit" class="button">
                <span class="dashicons dashicons-clock"></span>
                <?php esc_html_e( 'Очистить rate-limit', 'wp-smart-search-frontend' ); ?>
            </button>
        </form>
    </div>

    <!-- Диагностика -->
    <h3 class="wssf-maint-title"><?php esc_html_e( 'Диагностика', 'wp-smart-search-frontend' ); ?></h3>
    <p class="description">
        <?php esc_html_e(
            'Транзиенты с последним AJAX-запросом, результатами тестов CleanTalk и поиска. Хранятся 5 минут – 1 час.',
            'wp-smart-search-frontend'
        ); ?>
    </p>

    <div class="wssf-maintenance-row">
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wssf-inline-form">
            <?php wp_nonce_field( 'wssf_clear_diag_cache' ); ?>
            <input type="hidden" name="action" value="wssf_clear_diag_cache">
            <button type="submit" class="button">
                <span class="dashicons dashicons-editor-removeformatting"></span>
                <?php esc_html_e( 'Очистить транзиенты диагностики', 'wp-smart-search-frontend' ); ?>
            </button>
        </form>
    </div>

    <!-- Фоновая индексация (Cron) -->
    <h3 class="wssf-maint-title"><?php esc_html_e( 'Фоновая индексация (WP Cron)', 'wp-smart-search-frontend' ); ?></h3>

    <table class="wssf-env-table widefat striped" style="margin-bottom:12px;">
        <tbody>
            <tr>
                <th><?php esc_html_e( 'Статус', 'wp-smart-search-frontend' ); ?></th>
                <td>
                    <span class="<?php echo $cron_on ? 'ok' : 'bad'; ?>">
                        <?php echo $cron_on ? esc_html__( 'включена', 'wp-smart-search-frontend' ) : esc_html__( 'выключена', 'wp-smart-search-frontend' ); ?>
                    </span>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Интервал', 'wp-smart-search-frontend' ); ?></th>
                <td><?php echo esc_html( (string) $cron_status['interval'] ); ?></td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Следующий запуск', 'wp-smart-search-frontend' ); ?></th>
                <td>
                    <?php if ( $cron_next ) : ?>
                        <?php echo esc_html( wp_date( 'Y-m-d H:i:s', $cron_next ) ); ?>
                    <?php else : ?>
                        <span class="bad"><?php esc_html_e( 'не запланирован', 'wp-smart-search-frontend' ); ?></span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Последний запуск', 'wp-smart-search-frontend' ); ?></th>
                <td>
                    <?php echo $cron_last_run !== '' ? esc_html( $cron_last_run ) : '—'; ?>
                    <?php if ( isset( $cron_last_res['processed'] ) ) : ?>
                        — <?php printf( esc_html__( 'обработано: %d, осталось: %d', 'wp-smart-search-frontend' ), (int) $cron_last_res['processed'], (int) ( $cron_last_res['remaining'] ?? 0 ) ); ?>
                    <?php endif; ?>
                </td>
            </tr>
        </tbody>
    </table>

    <div class="wssf-maintenance-row">
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wssf-inline-form">
            <?php wp_nonce_field( 'wssf_cron_run_now' ); ?>
            <input type="hidden" name="action" value="wssf_cron_run_now">
            <button type="submit" class="button">
                <span class="dashicons dashicons-controls-play"></span>
                <?php esc_html_e( 'Запустить сейчас', 'wp-smart-search-frontend' ); ?>
            </button>
        </form>
    </div>

    <!-- IOC map -->
    <h3 class="wssf-maint-title"><?php esc_html_e( 'IOC map (co-occurrence)', 'wp-smart-search-frontend' ); ?></h3>
    <p class="description">
        <?php
        printf(
            esc_html__( 'Обратный индекс IOC → post_id. Сейчас в таблице: %d записей. После перестройки блок «Похожие запросы» начнёт работать.', 'wp-smart-search-frontend' ),
            (int) $stats['ioc_count']
        );
        ?>
    </p>

    <div class="wssf-maintenance-row">
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wssf-inline-form"
              onsubmit="return confirm('<?php echo esc_js( __( 'Перестроить IOC map? Может занять время на больших базах.', 'wp-smart-search-frontend' ) ); ?>');">
            <?php wp_nonce_field( 'wssf_ioc_map_rebuild' ); ?>
            <input type="hidden" name="action" value="wssf_ioc_map_rebuild">
            <button type="submit" class="button">
                <span class="dashicons dashicons-update"></span>
                <?php esc_html_e( 'Перестроить IOC map', 'wp-smart-search-frontend' ); ?>
            </button>
        </form>
    </div>

    <!-- Индекс -->
    <h3 class="wssf-maint-title"><?php esc_html_e( 'Индекс', 'wp-smart-search-frontend' ); ?></h3>
    <p class="description">
        <?php esc_html_e(
            'Полный сброс индекса или его пересоздание. Если нужно только дозаполнить — используйте «Проиндексировать недостающие» на вкладке «Статус».',
            'wp-smart-search-frontend'
        ); ?>
    </p>

    <div class="wssf-maintenance-row">
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wssf-inline-form"
              onsubmit="return confirm('<?php echo esc_js( __( 'Очистить индекс? Поиск будет работать через fallback (LIKE-поиск).', 'wp-smart-search-frontend' ) ); ?>');">
            <?php wp_nonce_field( 'wssf_reset_index' ); ?>
            <input type="hidden" name="action" value="wssf_reset_index">
            <button type="submit" class="button" <?php disabled( ! $stats['exists'] ); ?>>
                <span class="dashicons dashicons-editor-removeformatting"></span>
                <?php esc_html_e( 'Сбросить индекс', 'wp-smart-search-frontend' ); ?>
            </button>
        </form>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wssf-inline-form"
              onsubmit="return confirm('<?php echo esc_js( __( 'Пересоздать таблицу индекса и переиндексировать? Может занять время.', 'wp-smart-search-frontend' ) ); ?>');">
            <?php wp_nonce_field( 'wssf_drop_index' ); ?>
            <input type="hidden" name="action" value="wssf_drop_index">
            <button type="submit" class="button">
                <span class="dashicons dashicons-database-remove"></span>
                <?php esc_html_e( 'Пересоздать таблицу', 'wp-smart-search-frontend' ); ?>
            </button>
        </form>
    </div>

    <!-- Все кеши -->
    <h3 class="wssf-maint-title"><?php esc_html_e( 'Все кеши', 'wp-smart-search-frontend' ); ?></h3>
    <p class="description">
        <?php esc_html_e(
            'Очищает кеш поиска, вердикты CleanTalk, rate-limit и транзиенты диагностики за один клик. Индекс не трогается.',
            'wp-smart-search-frontend'
        ); ?>
    </p>

    <div class="wssf-maintenance-row">
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wssf-inline-form"
              onsubmit="return confirm('<?php echo esc_js( __( 'Очистить все кеши?', 'wp-smart-search-frontend' ) ); ?>');">
            <?php wp_nonce_field( 'wssf_clear_all_caches' ); ?>
            <input type="hidden" name="action" value="wssf_clear_all_caches">
            <button type="submit" class="button button-primary">
                <span class="dashicons dashicons-update-alt"></span>
                <?php esc_html_e( 'Очистить всё', 'wp-smart-search-frontend' ); ?>
            </button>
        </form>
    </div>
</div>