<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! current_user_can( 'manage_options' ) ) { return; }

$strict_mode  = WSSF_Admin_Settings::get_strict_mode();
$cleantalk    = WSSF_CleanTalk::state();
$ct_mode      = $cleantalk['mode'];
$ct_on        = $cleantalk['available'];
$ct_effective = $cleantalk['effective'];
$ttl_user     = WSSF_Admin_Settings::get_ttl_user();
$ttl_guest    = WSSF_Admin_Settings::get_ttl_guest();
$did_you_mean = WSSF_Admin_Settings::is_did_you_mean_enabled();
$suggestions  = WSSF_Admin_Settings::is_suggestions_enabled();
$history      = WSSF_Admin_Settings::is_history_enabled();
$ac_on        = WSSF_Admin_Settings::is_autocomplete_enabled();
$ac_min       = WSSF_Admin_Settings::get_autocomplete_min_len();
$ac_max       = WSSF_Admin_Settings::get_autocomplete_max();

$sw_enabled = (bool) get_option( 'wssf_stopwords_enabled', 1 );
$yara_on    = (bool) get_option( 'wssf_yara_enabled', 1 );

$cron_on       = (bool) get_option( 'wssf_cron_reindex_enabled', 1 );
$cron_interval = (string) get_option( 'wssf_cron_reindex_interval', 'daily' );

// 5.3.0
$reindex_batch = max( 10, min( 500, (int) get_option( 'wssf_reindex_batch_size', 200 ) ) );
$cron_batch    = max( 50, min( 2000, (int) get_option( 'wssf_cron_batch_size', 500 ) ) );
$async_save    = (bool) get_option( 'wssf_index_async_save', 0 );

$ioc_map_on = (bool) get_option( 'wssf_ioc_map_enabled', 1 );
$rel_on     = (bool) get_option( 'wssf_related_enabled', 1 );
$rel_max    = (int) get_option( 'wssf_related_max', 10 );

// 5.2.0
$mh_on = (bool) get_option( 'wssf_minhash_enabled', 1 );

$hdr_enabled = (bool) get_option( 'wssf_hdr_enabled', 1 );
$hdr_xcto    = (bool) get_option( 'wssf_hdr_xcto', 1 );
$hdr_xfo     = (bool) get_option( 'wssf_hdr_xfo', 1 );
$hdr_ref     = (bool) get_option( 'wssf_hdr_referrer', 1 );
$hdr_perm    = (bool) get_option( 'wssf_hdr_permissions', 1 );
$csp_mode    = (string) get_option( 'wssf_csp_mode', 'off' );
$csp_script  = (string) get_option( 'wssf_csp_script_src', '' );
$csp_style   = (string) get_option( 'wssf_csp_style_src', '' );
$csp_img     = (string) get_option( 'wssf_csp_img_src', '' );
$csp_conn    = (string) get_option( 'wssf_csp_connect_src', '' );

$saved_types = WSSF_Search_Engine::get_post_types();
$all_types   = get_post_types( [ 'public' => true ], 'objects' );

// Stopwords
$sw_lang = isset( $_GET['sw_lang'] ) ? sanitize_key( wp_unslash( $_GET['sw_lang'] ) ) : 'ru';
if ( ! in_array( $sw_lang, [ 'ru', 'en' ], true ) ) { $sw_lang = 'ru'; }

global $wpdb;
$sw_table = (string) $wpdb->prefix . 'wssf_stopwords';
$sw_rows  = [];
try {
    $sw_rows = $wpdb->get_col( $wpdb->prepare(
        "SELECT term FROM `{$sw_table}` WHERE lang = %s ORDER BY term ASC LIMIT 500",
        $sw_lang
    ) );
} catch ( \Throwable $e ) { $sw_rows = []; }
if ( ! is_array( $sw_rows ) ) { $sw_rows = []; }
?>

<div class="card wssf-card">
    <h2><span class="dashicons dashicons-admin-settings"></span>
        <?php esc_html_e( 'Основные настройки', 'wp-smart-search-frontend' ); ?>
    </h2>

    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <?php wp_nonce_field( 'wssf_save_settings' ); ?>
        <input type="hidden" name="action" value="wssf_save_settings">

        <table class="form-table" role="presentation">

            <tr>
                <th scope="row"><?php esc_html_e( 'Типы контента', 'wp-smart-search-frontend' ); ?></th>
                <td>
                    <fieldset>
                        <legend class="screen-reader-text">
                            <?php esc_html_e( 'Типы контента для поиска', 'wp-smart-search-frontend' ); ?>
                        </legend>
                        <?php if ( empty( $all_types ) ) : ?>
                            <em><?php esc_html_e( 'Публичные типы контента не найдены.', 'wp-smart-search-frontend' ); ?></em>
                        <?php else : ?>
                            <?php foreach ( $all_types as $pt_name => $pt_obj ) :
                                $pt_label = isset( $pt_obj->labels->name ) && $pt_obj->labels->name !== ''
                                    ? (string) $pt_obj->labels->name
                                    : (string) $pt_name;
                                $checked = in_array( (string) $pt_name, $saved_types, true );
                            ?>
                                <label style="display:block;margin-bottom:6px;">
                                    <input type="checkbox"
                                           name="wssf_search_post_types[]"
                                           value="<?php echo esc_attr( (string) $pt_name ); ?>"
                                        <?php checked( $checked ); ?>>
                                    <?php echo esc_html( $pt_label ); ?>
                                    <code><?php echo esc_html( (string) $pt_name ); ?></code>
                                </label>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </fieldset>
                    <p class="description">
                        <?php esc_html_e( 'Типы контента, в которых ведётся поиск. Если ничего не выбрано — используются «Записи» и «Страницы».', 'wp-smart-search-frontend' ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="wssf_cleantalk_mode">
                        <?php esc_html_e( 'Антиспам CleanTalk', 'wp-smart-search-frontend' ); ?>
                    </label>
                </th>
                <td>
                    <select name="wssf_cleantalk_mode" id="wssf_cleantalk_mode">
                        <option value="auto" <?php selected( $ct_mode, 'auto' ); ?>>
                            <?php esc_html_e( 'Авто — только если CleanTalk установлен', 'wp-smart-search-frontend' ); ?>
                        </option>
                        <option value="on" <?php selected( $ct_mode, 'on' ); ?><?php if ( ! $ct_on && $ct_mode !== 'on' ) { echo ' disabled'; } ?>>
                            <?php esc_html_e( 'Всегда включена', 'wp-smart-search-frontend' ); ?>
                        </option>
                        <option value="off" <?php selected( $ct_mode, 'off' ); ?>>
                            <?php esc_html_e( 'Выключена', 'wp-smart-search-frontend' ); ?>
                        </option>
                    </select>

                    <p class="description">
                        <?php if ( $ct_on ) : ?>
                            <span class="wssf-ct-ok">&#10004;</span>
                            <?php esc_html_e( 'CleanTalk обнаружен.', 'wp-smart-search-frontend' ); ?>
                        <?php else : ?>
                            <span class="wssf-ct-bad">&#10007;</span>
                            <?php esc_html_e( 'CleanTalk не обнаружен — проверка отключена, поиск работает как обычно.', 'wp-smart-search-frontend' ); ?>
                        <?php endif; ?>

                        <?php if ( $ct_on && ! $ct_effective ) : ?>
                            <?php esc_html_e( 'Сейчас проверка выключена выбранным режимом.', 'wp-smart-search-frontend' ); ?>
                        <?php elseif ( ! $ct_on && $ct_mode === 'on' ) : ?>
                            <?php esc_html_e( 'Режим «Всегда включена» выбран, но без плагина CleanTalk проверка выполняться не будет.', 'wp-smart-search-frontend' ); ?>
                        <?php endif; ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="wssf_strict_mode">
                        <?php esc_html_e( 'Strict-режим CleanTalk', 'wp-smart-search-frontend' ); ?>
                    </label>
                </th>
                <td>
                    <select name="wssf_strict_mode" id="wssf_strict_mode">
                        <option value="auto" <?php selected( $strict_mode, 'auto' ); ?>>
                            <?php esc_html_e( 'Авто — выкл., если есть кеш-плагин', 'wp-smart-search-frontend' ); ?>
                        </option>
                        <option value="on" <?php selected( $strict_mode, 'on' ); ?>>
                            <?php esc_html_e( 'Всегда включён (максимум защиты)', 'wp-smart-search-frontend' ); ?>
                        </option>
                        <option value="off" <?php selected( $strict_mode, 'off' ); ?>>
                            <?php esc_html_e( 'Всегда выключен (максимум совместимости)', 'wp-smart-search-frontend' ); ?>
                        </option>
                    </select>
                    <p class="description">
                        <?php esc_html_e( 'Очистка полей CleanTalk перед AJAX-проверкой. Действует только когда проверка CleanTalk включена.', 'wp-smart-search-frontend' ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="wssf_ttl_user">
                        <?php esc_html_e( 'TTL вердикта (авторизованные, мин)', 'wp-smart-search-frontend' ); ?>
                    </label>
                </th>
                <td>
                    <input type="number" name="wssf_ttl_user" id="wssf_ttl_user"
                           value="<?php echo esc_attr( (string) $ttl_user ); ?>"
                           min="0" step="1" class="small-text">
                    <p class="description">
                        <?php esc_html_e( '0 — не кешировать.', 'wp-smart-search-frontend' ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="wssf_ttl_guest">
                        <?php esc_html_e( 'TTL вердикта (гости, сек)', 'wp-smart-search-frontend' ); ?>
                    </label>
                </th>
                <td>
                    <input type="number" name="wssf_ttl_guest" id="wssf_ttl_guest"
                           value="<?php echo esc_attr( (string) $ttl_guest ); ?>"
                           min="0" step="1" class="small-text">
                </td>
            </tr>

        </table>

        <h3><?php esc_html_e( 'YARA', 'wp-smart-search-frontend' ); ?></h3>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'YARA', 'wp-smart-search-frontend' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wssf_yara_enabled" value="1" <?php checked( $yara_on ); ?>>
                        <?php esc_html_e( 'Извлекать имена YARA-правил из контента и индексировать как yara:NameOfRule', 'wp-smart-search-frontend' ); ?>
                    </label>
                </td>
            </tr>
        </table>

        <h3><?php esc_html_e( 'Автодополнение', 'wp-smart-search-frontend' ); ?></h3>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Включить', 'wp-smart-search-frontend' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wssf_autocomplete_enabled" value="1" <?php checked( $ac_on ); ?>>
                        <?php esc_html_e( 'Показывать подсказки при вводе', 'wp-smart-search-frontend' ); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wssf_autocomplete_min_len">
                        <?php esc_html_e( 'Мин. длина запроса', 'wp-smart-search-frontend' ); ?>
                    </label>
                </th>
                <td>
                    <input type="number" name="wssf_autocomplete_min_len" id="wssf_autocomplete_min_len"
                           value="<?php echo esc_attr( (string) $ac_min ); ?>"
                           min="1" max="10" step="1" class="small-text">
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wssf_autocomplete_max">
                        <?php esc_html_e( 'Макс. подсказок', 'wp-smart-search-frontend' ); ?>
                    </label>
                </th>
                <td>
                    <input type="number" name="wssf_autocomplete_max" id="wssf_autocomplete_max"
                           value="<?php echo esc_attr( (string) $ac_max ); ?>"
                           min="1" max="20" step="1" class="small-text">
                </td>
            </tr>
        </table>

        <h3><?php esc_html_e( 'Индексация и WP Cron', 'wp-smart-search-frontend' ); ?></h3>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Фоновая индексация', 'wp-smart-search-frontend' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wssf_cron_reindex_enabled" value="1" <?php checked( $cron_on ); ?>>
                        <?php esc_html_e( 'Периодически доиндексировать недостающие посты', 'wp-smart-search-frontend' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e( 'Запускается по расписанию WP Cron и индексирует недостающие посты батчами.', 'wp-smart-search-frontend' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wssf_cron_reindex_interval">
                        <?php esc_html_e( 'Интервал cron', 'wp-smart-search-frontend' ); ?>
                    </label>
                </th>
                <td>
                    <select name="wssf_cron_reindex_interval" id="wssf_cron_reindex_interval">
                        <option value="wssf_hourly" <?php selected( $cron_interval, 'wssf_hourly' ); ?>>
                            <?php esc_html_e( 'Каждый час', 'wp-smart-search-frontend' ); ?>
                        </option>
                        <option value="wssf_twicedaily" <?php selected( $cron_interval, 'wssf_twicedaily' ); ?>>
                            <?php esc_html_e( 'Два раза в день', 'wp-smart-search-frontend' ); ?>
                        </option>
                        <option value="daily" <?php selected( $cron_interval, 'daily' ); ?>>
                            <?php esc_html_e( 'Раз в день', 'wp-smart-search-frontend' ); ?>
                        </option>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wssf_cron_batch_size">
                        <?php esc_html_e( 'Постов за один cron-запуск', 'wp-smart-search-frontend' ); ?>
                    </label>
                </th>
                <td>
                    <input type="number" name="wssf_cron_batch_size" id="wssf_cron_batch_size"
                           value="<?php echo esc_attr( (string) $cron_batch ); ?>"
                           min="50" max="2000" step="50" class="small-text">
                    <p class="description">
                        <?php esc_html_e( 'Сколько постов обрабатывать за один запуск WP-Cron (50–2000). Больше — быстрее, но нагрузка на PHP-процесс выше.', 'wp-smart-search-frontend' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wssf_reindex_batch_size">
                        <?php esc_html_e( 'Постов за один AJAX-батч', 'wp-smart-search-frontend' ); ?>
                    </label>
                </th>
                <td>
                    <input type="number" name="wssf_reindex_batch_size" id="wssf_reindex_batch_size"
                           value="<?php echo esc_attr( (string) $reindex_batch ); ?>"
                           min="10" max="500" step="10" class="small-text">
                    <p class="description">
                        <?php esc_html_e( 'Размер батча при «Переиндексировать всё» / «Проиндексировать недостающие» на вкладке «Статус» (10–500). Больше — быстрее, но больший риск таймаута PHP.', 'wp-smart-search-frontend' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Асинхронная индексация при сохранении', 'wp-smart-search-frontend' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wssf_index_async_save" value="1" <?php checked( $async_save ); ?>>
                        <?php esc_html_e( 'Ставить индексацию поста в WP-Cron (задержка 5–15 сек вместо блокировки редактора)', 'wp-smart-search-frontend' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e( 'Если WP-Cron отключён (DISABLE_WP_CRON) или задача не запланировалась — индексация выполнится синхронно.', 'wp-smart-search-frontend' ); ?>
                    </p>
                </td>
            </tr>
        </table>

        <h3><?php esc_html_e( 'Похожие запросы (co-occurrence)', 'wp-smart-search-frontend' ); ?></h3>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'IOC map', 'wp-smart-search-frontend' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wssf_ioc_map_enabled" value="1" <?php checked( $ioc_map_on ); ?>>
                        <?php esc_html_e( 'Заполнять IOC map при индексации', 'wp-smart-search-frontend' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e( 'Обратный индекс IOC → post_id. Используется для блока «Похожие запросы». После включения нужна переиндексация.', 'wp-smart-search-frontend' ); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Показывать «Похожие запросы»', 'wp-smart-search-frontend' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wssf_related_enabled" value="1" <?php checked( $rel_on ); ?>>
                        <?php esc_html_e( 'Блок «Также упоминается в этой связи» на странице результатов', 'wp-smart-search-frontend' ); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="wssf_related_max">
                        <?php esc_html_e( 'Макс. IOC в блоке', 'wp-smart-search-frontend' ); ?>
                    </label>
                </th>
                <td>
                    <input type="number" name="wssf_related_max" id="wssf_related_max"
                           value="<?php echo esc_attr( (string) $rel_max ); ?>"
                           min="1" max="30" step="1" class="small-text">
                </td>
            </tr>
        </table>

        <h3><?php esc_html_e( 'MinHash (near-duplicate)', 'wp-smart-search-frontend' ); ?></h3>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Включить', 'wp-smart-search-frontend' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wssf_minhash_enabled" value="1" <?php checked( $mh_on ); ?>>
                        <?php esc_html_e( 'Считать MinHash-signature и показывать «Похожие записи» на карточке результата', 'wp-smart-search-frontend' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e( '64-элементная signature (xxh64), Jaccard ≥ 0.5. Требует переиндексации после включения.', 'wp-smart-search-frontend' ); ?>
                    </p>
                </td>
            </tr>
        </table>

        <h3><?php esc_html_e( 'Security Headers', 'wp-smart-search-frontend' ); ?></h3>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Базовые заголовки', 'wp-smart-search-frontend' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wssf_hdr_enabled" value="1" <?php checked( $hdr_enabled ); ?>>
                        <?php esc_html_e( 'Отправлять security-заголовки', 'wp-smart-search-frontend' ); ?>
                    </label>
                    <p>
                        <label><input type="checkbox" name="wssf_hdr_xcto" value="1" <?php checked( $hdr_xcto ); ?>> X-Content-Type-Options: nosniff</label><br>
                        <label><input type="checkbox" name="wssf_hdr_xfo" value="1" <?php checked( $hdr_xfo ); ?>> X-Frame-Options: SAMEORIGIN</label><br>
                        <label><input type="checkbox" name="wssf_hdr_referrer" value="1" <?php checked( $hdr_ref ); ?>> Referrer-Policy: strict-origin-when-cross-origin</label><br>
                        <label><input type="checkbox" name="wssf_hdr_permissions" value="1" <?php checked( $hdr_perm ); ?>> Permissions-Policy (geolocation, mic, camera, payment, usb)</label>
                    </p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'CSP', 'wp-smart-search-frontend' ); ?></th>
                <td>
                    <select name="wssf_csp_mode">
                        <option value="off" <?php selected( $csp_mode, 'off' ); ?>><?php esc_html_e( 'Выключено', 'wp-smart-search-frontend' ); ?></option>
                        <option value="report_only" <?php selected( $csp_mode, 'report_only' ); ?>><?php esc_html_e( 'Report-Only (не блокирует)', 'wp-smart-search-frontend' ); ?></option>
                        <option value="enforce" <?php selected( $csp_mode, 'enforce' ); ?>><?php esc_html_e( 'Enforce (блокирует)', 'wp-smart-search-frontend' ); ?></option>
                    </select>
                    <p class="description">
                        <?php esc_html_e( 'Сначала включите report-only, проверьте консоль браузера, потом enforce.', 'wp-smart-search-frontend' ); ?>
                    </p>
                    <p>
                        <label>script-src: <textarea name="wssf_csp_script_src" rows="2" class="large-text code" placeholder="https://www.google-analytics.com https://..."><?php echo esc_textarea( $csp_script ); ?></textarea></label>
                    </p>
                    <p>
                        <label>style-src: <textarea name="wssf_csp_style_src" rows="2" class="large-text code" placeholder="https://fonts.googleapis.com"><?php echo esc_textarea( $csp_style ); ?></textarea></label>
                    </p>
                    <p>
                        <label>img-src: <textarea name="wssf_csp_img_src" rows="2" class="large-text code" placeholder="https://..."><?php echo esc_textarea( $csp_img ); ?></textarea></label>
                    </p>
                    <p>
                        <label>connect-src: <textarea name="wssf_csp_connect_src" rows="2" class="large-text code" placeholder="https://api.example.com"><?php echo esc_textarea( $csp_conn ); ?></textarea></label>
                    </p>
                </td>
            </tr>
        </table>

        <h3><?php esc_html_e( 'Подсказки и история', 'wp-smart-search-frontend' ); ?></h3>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e( 'Опции поиска', 'wp-smart-search-frontend' ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="wssf_did_you_mean" value="1" <?php checked( $did_you_mean ); ?>>
                        <?php esc_html_e( '«Возможно, вы имели в виду…» (опечатка раскладки)', 'wp-smart-search-frontend' ); ?>
                    </label>
                    <br>
                    <label>
                        <input type="checkbox" name="wssf_suggestions" value="1" <?php checked( $suggestions ); ?>>
                        <?php esc_html_e( '«Похожие запросы» при пустой выдаче', 'wp-smart-search-frontend' ); ?>
                    </label>
                    <br>
                    <label>
                        <input type="checkbox" name="wssf_history_enabled" value="1" <?php checked( $history ); ?>>
                        <?php esc_html_e( 'История поиска', 'wp-smart-search-frontend' ); ?>
                    </label>
                </td>
            </tr>
        </table>

        <?php submit_button( __( 'Сохранить настройки', 'wp-smart-search-frontend' ) ); ?>
    </form>
</div>

<!-- ============================================================
     Стоп-слова — единый блок
     ============================================================ -->
<div class="card wssf-card">
    <h2><span class="dashicons dashicons-editor-strikethrough"></span>
        <?php esc_html_e( 'Стоп-слова', 'wp-smart-search-frontend' ); ?>
    </h2>

    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:16px;">
        <?php wp_nonce_field( 'wssf_save_stopwords' ); ?>
        <input type="hidden" name="action" value="wssf_save_stopwords">
        <input type="hidden" name="wssf_sw_action" value="save_enabled">
        <input type="hidden" name="wssf_sw_lang" value="<?php echo esc_attr( $sw_lang ); ?>">

        <label>
            <input type="checkbox" name="wssf_stopwords_enabled" value="1" <?php checked( $sw_enabled ); ?>>
            <?php esc_html_e( 'Исключать стоп-слова из индекса и запросов', 'wp-smart-search-frontend' ); ?>
        </label>
        <p class="description">
            <?php esc_html_e( 'Применяется после стемминга. Список редактируется ниже.', 'wp-smart-search-frontend' ); ?>
        </p>
        <p>
            <button type="submit" class="button">
                <span class="dashicons dashicons-saved"></span>
                <?php esc_html_e( 'Сохранить', 'wp-smart-search-frontend' ); ?>
            </button>
        </p>
    </form>

    <p class="description">
        <?php
        printf(
            esc_html__( 'Язык: %s. Всего в списке: %d (показаны первые 500).', 'wp-smart-search-frontend' ),
            '<strong>' . esc_html( $sw_lang === 'en' ? 'English' : 'Русский' ) . '</strong>',
            count( $sw_rows )
        );
        ?>
    </p>

    <p>
        <?php foreach ( [ 'ru' => 'Русский', 'en' => 'English' ] as $code => $label ) : ?>
            <a href="<?php echo esc_url( add_query_arg(
                [ 'page' => 'wssf-settings', 'tab' => 'general', 'sw_lang' => $code ],
                admin_url( 'options-general.php' )
            ) ); ?>"
               class="button <?php echo $sw_lang === $code ? 'button-primary' : ''; ?>">
                <?php echo esc_html( $label ); ?>
            </a>
        <?php endforeach; ?>
    </p>

    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:16px;">
        <?php wp_nonce_field( 'wssf_save_stopwords' ); ?>
        <input type="hidden" name="action" value="wssf_save_stopwords">
        <input type="hidden" name="wssf_sw_action" value="add">
        <input type="hidden" name="wssf_sw_lang" value="<?php echo esc_attr( $sw_lang ); ?>">

        <textarea name="wssf_sw_terms" rows="4" class="large-text code"
                  placeholder="<?php esc_attr_e( 'Одно стоп-слово на строку (можно с запятыми)', 'wp-smart-search-frontend' ); ?>"></textarea>

        <p>
            <button type="submit" class="button button-primary">
                <span class="dashicons dashicons-plus-alt2"></span>
                <?php esc_html_e( 'Добавить стоп-слова', 'wp-smart-search-frontend' ); ?>
            </button>
        </p>
    </form>

    <details style="margin-top:16px;">
        <summary style="cursor:pointer;font-weight:600;">
            <?php esc_html_e( 'Показать текущий список (первые 500)', 'wp-smart-search-frontend' ); ?>
        </summary>

        <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:12px;max-height:400px;overflow:auto;padding:8px;border:1px solid #dcdcde;border-radius:4px;background:#f6f7f7;">
            <?php if ( empty( $sw_rows ) ) : ?>
                <em><?php esc_html_e( 'Список пуст. Загрузите дефолтные или добавьте вручную.', 'wp-smart-search-frontend' ); ?></em>
            <?php else : ?>
                <?php foreach ( $sw_rows as $term ) : ?>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                        <?php wp_nonce_field( 'wssf_save_stopwords' ); ?>
                        <input type="hidden" name="action" value="wssf_save_stopwords">
                        <input type="hidden" name="wssf_sw_action" value="remove">
                        <input type="hidden" name="wssf_sw_lang" value="<?php echo esc_attr( $sw_lang ); ?>">
                        <input type="hidden" name="wssf_sw_term" value="<?php echo esc_attr( (string) $term ); ?>">
                        <button type="submit"
                                class="button button-small"
                                title="<?php esc_attr_e( 'Удалить', 'wp-smart-search-frontend' ); ?>"
                                style="font-family:ui-monospace,Menlo,Consolas,monospace;">
                            <?php echo esc_html( (string) $term ); ?> ×
                        </button>
                    </form>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </details>

    <div class="wssf-action-row" style="margin-top:16px;">
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <?php wp_nonce_field( 'wssf_stopwords_reload' ); ?>
            <input type="hidden" name="action" value="wssf_stopwords_reload">
            <button type="submit" class="button">
                <span class="dashicons dashicons-update"></span>
                <?php esc_html_e( 'Загрузить дефолтные (ru + en)', 'wp-smart-search-frontend' ); ?>
            </button>
        </form>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
              onsubmit="return confirm('<?php echo esc_js( __( 'Очистить список стоп-слов для этого языка?', 'wp-smart-search-frontend' ) ); ?>');">
            <?php wp_nonce_field( 'wssf_stopwords_clear' ); ?>
            <input type="hidden" name="action" value="wssf_stopwords_clear">
            <input type="hidden" name="lang" value="<?php echo esc_attr( $sw_lang ); ?>">
            <button type="submit" class="button">
                <span class="dashicons dashicons-trash"></span>
                <?php esc_html_e( 'Очистить текущий язык', 'wp-smart-search-frontend' ); ?>
            </button>
        </form>
    </div>

    <p class="description" style="margin-top:16px;">
        <?php esc_html_e(
            'Применяется после стемминга. Например, «и», «в», «the», «and» — служебные слова, ' .
            'которые не несут смысла в поиске. После изменения стоп-слов нужна переиндексация.',
            'wp-smart-search-frontend'
        ); ?>
    </p>
</div>