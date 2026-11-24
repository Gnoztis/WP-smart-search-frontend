<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! current_user_can( 'manage_options' ) ) { return; }

$index_stats = \WSSF\Admin_Actions::get_index_stats();
$sem_stats   = \WSSF\Admin_Actions::get_semantic_stats();

$env = [];
try { $env = \WSSF\Admin_Diagnostics::instance()->get_env_data(); }
catch ( \Throwable $e ) { $env = []; }

$nonce_reindex = wp_create_nonce( 'wssf_reindex_ajax' );
$ajax_url      = admin_url( 'admin-ajax.php' );

$semantic_url    = admin_url( 'options-general.php?page=wssf-settings&tab=semantic' );
$maintenance_url = admin_url( 'options-general.php?page=wssf-settings&tab=maintenance' );
$diagnostics_url = admin_url( 'options-general.php?page=wssf-settings&tab=diagnostics' );
?>

<!-- Индекс -->
<div class="card wssf-card">
    <h2><span class="dashicons dashicons-database"></span>
        <?php esc_html_e( 'Индекс', 'wp-smart-search-frontend' ); ?>
    </h2>

    <div class="wssf-stat-grid">
        <div class="wssf-stat">
            <div class="wssf-stat-label"><?php esc_html_e( 'Таблица индекса', 'wp-smart-search-frontend' ); ?></div>
            <div class="wssf-stat-value <?php echo $index_stats['exists'] ? 'ok' : 'bad'; ?>">
                <?php echo $index_stats['exists']
                    ? esc_html__( 'Существует', 'wp-smart-search-frontend' )
                    : esc_html__( 'Не создана', 'wp-smart-search-frontend' ); ?>
            </div>
        </div>
        <div class="wssf-stat">
            <div class="wssf-stat-label"><?php esc_html_e( 'Записей в индексе', 'wp-smart-search-frontend' ); ?></div>
            <div class="wssf-stat-value">
                <?php echo (int) $index_stats['count']; ?>
                <span class="wssf-stat-aux">/ <?php echo (int) $index_stats['total_posts']; ?></span>
            </div>
        </div>
        <div class="wssf-stat">
            <div class="wssf-stat-label"><?php esc_html_e( 'Покрытие', 'wp-smart-search-frontend' ); ?></div>
            <div class="wssf-stat-value <?php echo $index_stats['coverage'] >= 90 ? 'ok' : ( $index_stats['coverage'] > 0 ? '' : 'bad' ); ?>">
                <?php echo (int) $index_stats['coverage']; ?>%
            </div>
            <?php if ( $index_stats['missing'] > 0 ) : ?>
                <div class="wssf-stat-aux">
                    <?php printf( esc_html__( 'не проиндексировано: %d', 'wp-smart-search-frontend' ), (int) $index_stats['missing'] ); ?>
                </div>
            <?php endif; ?>
        </div>
        <div class="wssf-stat">
            <div class="wssf-stat-label"><?php esc_html_e( '«Сырой» объём текста', 'wp-smart-search-frontend' ); ?></div>
            <div class="wssf-stat-value">
                <?php echo esc_html( \WSSF\Admin_Actions::format_bytes( (int) $index_stats['raw_data_size'] ) ); ?>
            </div>
            <div class="wssf-stat-aux">
                <?php printf( esc_html__( 'средняя запись: %s', 'wp-smart-search-frontend' ), esc_html( \WSSF\Admin_Actions::format_bytes( (int) $index_stats['raw_avg_size'] ) ) ); ?>
            </div>
        </div>
        <div class="wssf-stat">
            <div class="wssf-stat-label"><?php esc_html_e( 'Физический размер в БД', 'wp-smart-search-frontend' ); ?></div>
            <div class="wssf-stat-value">
                <?php echo esc_html( \WSSF\Admin_Actions::format_bytes( (int) $index_stats['total_size'] ) ); ?>
            </div>
            <div class="wssf-stat-aux">
                <?php printf( esc_html__( 'данные %s / индексы %s', 'wp-smart-search-frontend' ), esc_html( \WSSF\Admin_Actions::format_bytes( (int) $index_stats['data_length'] ) ), esc_html( \WSSF\Admin_Actions::format_bytes( (int) $index_stats['index_length'] ) ) ); ?>
            </div>
        </div>
        <div class="wssf-stat">
            <div class="wssf-stat-label"><?php esc_html_e( 'Схема БД', 'wp-smart-search-frontend' ); ?></div>
            <div class="wssf-stat-value <?php echo $index_stats['optimized'] ? 'ok' : 'bad'; ?>">
                <?php echo $index_stats['optimized']
                    ? esc_html__( 'актуальна', 'wp-smart-search-frontend' )
                    : esc_html__( 'устарела', 'wp-smart-search-frontend' ); ?>
            </div>
        </div>
    </div>

    <?php if ( $index_stats['updated_at'] !== '' ) : ?>
        <p class="description">
            <?php printf( esc_html__( 'Последняя индексация: %s', 'wp-smart-search-frontend' ), '<strong>' . esc_html( $index_stats['updated_at'] ) . '</strong>' ); ?>
        </p>
    <?php endif; ?>

    <div class="wssf-action-row">
        <button type="button"
                class="button button-primary wssf-reindex-btn"
                data-mode="all"
                data-nonce="<?php echo esc_attr( $nonce_reindex ); ?>"
                data-ajax-url="<?php echo esc_url( $ajax_url ); ?>">
            <span class="dashicons dashicons-update"></span>
            <?php esc_html_e( 'Переиндексировать всё', 'wp-smart-search-frontend' ); ?>
        </button>
        <button type="button"
                class="button wssf-reindex-btn"
                data-mode="missing"
                data-nonce="<?php echo esc_attr( $nonce_reindex ); ?>"
                data-ajax-url="<?php echo esc_url( $ajax_url ); ?>"
                <?php disabled( $index_stats['missing'] === 0 ); ?>>
            <span class="dashicons dashicons-plus-alt2"></span>
            <?php esc_html_e( 'Проиндексировать недостающие', 'wp-smart-search-frontend' ); ?>
            <?php if ( $index_stats['missing'] > 0 ) : ?>
                <span class="wssf-stat-aux" style="margin-left:4px;">(<?php echo (int) $index_stats['missing']; ?>)</span>
            <?php endif; ?>
        </button>
    </div>

    <div class="wssf-reindex-progress" id="wssf-reindex-progress" hidden>
        <div class="wssf-reindex-bar-wrap">
            <div class="wssf-reindex-bar" id="wssf-reindex-bar"></div>
        </div>
        <div class="wssf-reindex-status" id="wssf-reindex-status" role="status" aria-live="polite"></div>
    </div>
</div>

<!-- Расширение запроса -->
<div class="card wssf-card">
    <h2><span class="dashicons dashicons-networking"></span>
        <?php esc_html_e( 'Расширение запроса', 'wp-smart-search-frontend' ); ?>
    </h2>
    <table class="wssf-env-table widefat striped">
        <tbody>
            <tr>
                <th><?php esc_html_e( 'PRF (Rocchio/RM3)', 'wp-smart-search-frontend' ); ?></th>
                <td><span class="<?php echo $sem_stats['prf_on'] ? 'ok' : 'bad'; ?>"><?php echo $sem_stats['prf_on'] ? esc_html__( 'включён', 'wp-smart-search-frontend' ) : esc_html__( 'выключен', 'wp-smart-search-frontend' ); ?></span></td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Синонимия через теги', 'wp-smart-search-frontend' ); ?></th>
                <td><span class="<?php echo $sem_stats['syn_on'] ? 'ok' : 'bad'; ?>"><?php echo $sem_stats['syn_on'] ? esc_html__( 'включена', 'wp-smart-search-frontend' ) : esc_html__( 'выключена', 'wp-smart-search-frontend' ); ?></span></td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Опечатки (BK-tree + n-gram)', 'wp-smart-search-frontend' ); ?></th>
                <td><span class="<?php echo $sem_stats['typo_on'] ? 'ok' : 'bad'; ?>"><?php echo $sem_stats['typo_on'] ? esc_html__( 'включены', 'wp-smart-search-frontend' ) : esc_html__( 'выключены', 'wp-smart-search-frontend' ); ?></span></td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'YARA (извлечение имён)', 'wp-smart-search-frontend' ); ?></th>
                <td>
                    <?php $yara_on = (bool) get_option( 'wssf_yara_enabled', 1 ); ?>
                    <span class="<?php echo $yara_on ? 'ok' : 'bad'; ?>">
                        <?php echo $yara_on ? esc_html__( 'включено', 'wp-smart-search-frontend' ) : esc_html__( 'выключено', 'wp-smart-search-frontend' ); ?>
                    </span>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Стоп-слова', 'wp-smart-search-frontend' ); ?></th>
                <td>
                    <?php $sw_on = (bool) get_option( 'wssf_stopwords_enabled', 1 ); ?>
                    <span class="<?php echo $sw_on ? 'ok' : 'bad'; ?>">
                        <?php echo $sw_on ? esc_html__( 'включены', 'wp-smart-search-frontend' ) : esc_html__( 'выключены', 'wp-smart-search-frontend' ); ?>
                    </span>
                    · <?php echo (int) $index_stats['sw_count']; ?> <?php esc_html_e( 'терминов', 'wp-smart-search-frontend' ); ?>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Похожие запросы (co-occurrence)', 'wp-smart-search-frontend' ); ?></th>
                <td>
                    <?php $rel_on = (bool) get_option( 'wssf_related_enabled', 1 ); ?>
                    <span class="<?php echo $rel_on ? 'ok' : 'bad'; ?>">
                        <?php echo $rel_on ? esc_html__( 'включены', 'wp-smart-search-frontend' ) : esc_html__( 'выключены', 'wp-smart-search-frontend' ); ?>
                    </span>
                    · <?php echo (int) $index_stats['ioc_count']; ?> <?php esc_html_e( 'IOC в map', 'wp-smart-search-frontend' ); ?>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'MinHash (near-duplicate)', 'wp-smart-search-frontend' ); ?></th>
                <td>
                    <?php $mh_on = (bool) get_option( 'wssf_minhash_enabled', 1 ); ?>
                    <span class="<?php echo $mh_on ? 'ok' : 'bad'; ?>">
                        <?php echo $mh_on ? esc_html__( 'включён', 'wp-smart-search-frontend' ) : esc_html__( 'выключен', 'wp-smart-search-frontend' ); ?>
                    </span>
                    · <?php echo (int) $index_stats['minhash_count']; ?> <?php esc_html_e( 'signatures', 'wp-smart-search-frontend' ); ?>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Security Headers', 'wp-smart-search-frontend' ); ?></th>
                <td>
                    <?php
                    $hdr_on   = (bool) get_option( 'wssf_hdr_enabled', 1 );
                    $csp_mode = (string) get_option( 'wssf_csp_mode', 'off' );
                    ?>
                    <span class="<?php echo $hdr_on ? 'ok' : 'bad'; ?>">
                        <?php echo $hdr_on ? esc_html__( 'включены', 'wp-smart-search-frontend' ) : esc_html__( 'выключены', 'wp-smart-search-frontend' ); ?>
                    </span>
                    · CSP: <code><?php echo esc_html( $csp_mode ); ?></code>
                </td>
            </tr>
        </tbody>
    </table>

    <div class="wssf-action-row">
        <a href="<?php echo esc_url( $semantic_url ); ?>" class="button">
            <span class="dashicons dashicons-admin-settings"></span>
            <?php esc_html_e( 'Настроить', 'wp-smart-search-frontend' ); ?>
        </a>
    </div>
</div>

<!-- Окружение (кратко) -->
<div class="card wssf-card">
    <h2><span class="dashicons dashicons-info-outline"></span>
        <?php esc_html_e( 'Окружение', 'wp-smart-search-frontend' ); ?>
    </h2>
    <table class="wssf-env-table widefat striped">
        <tbody>
            <tr><th>PHP</th><td><?php echo esc_html( $env['php_version'] ?? '—' ); ?></td></tr>
            <tr><th>WordPress</th><td><?php echo esc_html( $env['wp_version'] ?? '—' ); ?></td></tr>
            <tr><th>MySQL</th><td><?php echo esc_html( $env['mysql_version'] ?? '—' ); ?></td></tr>
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
                <th><?php esc_html_e( 'Cache generation', 'wp-smart-search-frontend' ); ?></th>
                <td><?php echo (int) ( $env['cache_generation'] ?? 0 ); ?></td>
            </tr>
        </tbody>
    </table>

    <div class="wssf-action-row">
        <a href="<?php echo esc_url( $diagnostics_url ); ?>" class="button">
            <span class="dashicons dashicons-shield-alt"></span>
            <?php esc_html_e( 'Подробнее в диагностике', 'wp-smart-search-frontend' ); ?>
        </a>
        <a href="<?php echo esc_url( $maintenance_url ); ?>" class="button">
            <span class="dashicons dashicons-admin-tools"></span>
            <?php esc_html_e( 'Обслуживание', 'wp-smart-search-frontend' ); ?>
        </a>
    </div>
</div>