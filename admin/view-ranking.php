<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! current_user_can( 'manage_options' ) ) { return; }

$synonyms_raw = (string) get_option( 'wssf_synonyms', '' );
$term_balance = WSSF_Admin_Settings::get_term_balance_power();
$saved_weights = WSSF_Admin_Settings::get_post_type_weights();
$post_types    = WSSF_Search_Engine::get_post_types();

// Формируем «читаемый» вид синонимов для textarea.
$synonyms_display = '';
if ( $synonyms_raw !== '' ) {
    $pairs = explode( ';', $synonyms_raw );
    $synonyms_display = implode( "\n", array_filter( array_map( 'trim', $pairs ) ) );
}
?>

<!-- Синонимы -->
<div class="card wssf-card">
    <h2><span class="dashicons dashicons-networking"></span>
        <?php esc_html_e( 'Ручные синонимы', 'wp-smart-search-frontend' ); ?>
    </h2>

    <p class="description">
        <?php esc_html_e(
            'Пары «ключ=значение» — синонимы добавляются к запросу опционально (без обязательного оператора). ' .
            'Полезно для раскрытия аббревиатур и связки идентификаторов.',
            'wp-smart-search-frontend'
        ); ?>
    </p>

    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <?php wp_nonce_field( 'wssf_save_ranking' ); ?>
        <input type="hidden" name="action" value="wssf_save_ranking">

        <table class="form-table" role="presentation">

            <tr>
                <th scope="row">
                    <label for="wssf_synonyms">
                        <?php esc_html_e( 'Синонимы', 'wp-smart-search-frontend' ); ?>
                    </label>
                </th>
                <td>
                    <textarea name="wssf_synonyms" id="wssf_synonyms"
                              rows="10" class="large-text code"
                              placeholder="cve=bdu&#10;rce=remote code execution&#10;lfi=local file inclusion&#10;уязвимость=vulnerability"><?php echo esc_textarea( $synonyms_display ); ?></textarea>
                    <p class="description">
                        <?php esc_html_e(
                            'Одна пара на строку: «ключ=значение». Ключ — термин из запроса (стем, в нижнем регистре), ' .
                            'значение — синоним. Можно несколько синонимов на один ключ (разные строки).',
                            'wp-smart-search-frontend'
                        ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="wssf_term_balance_power">
                        <?php esc_html_e( 'Term balance (GM/AM)', 'wp-smart-search-frontend' ); ?>
                    </label>
                </th>
                <td>
                    <input type="number" name="wssf_term_balance_power" id="wssf_term_balance_power"
                           value="<?php echo esc_attr( (string) $term_balance ); ?>"
                           min="0" max="5" step="0.1" class="small-text">
                    <p class="description">
                        <?php esc_html_e(
                            'Штраф за неравномерность терминов в AND-запросах. 0 — выключено (по умолчанию). ' .
                            '2 — квадратичный штраф. Помогает, когда документ содержит один термин 100 раз, ' .
                            'а другой — 1 раз; такой документ не должен выигрывать у равномерного.',
                            'wp-smart-search-frontend'
                        ); ?>
                    </p>
                </td>
            </tr>

        </table>

        <h3><?php esc_html_e( 'Веса типов контента', 'wp-smart-search-frontend' ); ?></h3>
        <p class="description">
            <?php esc_html_e(
                'Множитель релевантности по типу контента. 1.0 — нейтрально. 0.5 — половина веса. ' .
                '2.0 — удвоенный вес. Диапазон 0–10.',
                'wp-smart-search-frontend'
            ); ?>
        </p>

        <table class="widefat striped" style="max-width: 600px;">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Тип контента', 'wp-smart-search-frontend' ); ?></th>
                    <th style="width:120px;"><?php esc_html_e( 'Вес', 'wp-smart-search-frontend' ); ?></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $post_types as $pt ) :
                $obj = function_exists( 'get_post_type_object' ) ? get_post_type_object( $pt ) : null;
                $label = $obj && isset( $obj->labels->name ) && (string) $obj->labels->name !== ''
                    ? (string) $obj->labels->name
                    : $pt;
                $w = isset( $saved_weights[ $pt ] ) ? (float) $saved_weights[ $pt ] : 1.0;
            ?>
                <tr>
                    <td>
                        <strong><?php echo esc_html( $label ); ?></strong>
                        <code style="margin-left:8px;color:#646970;"><?php echo esc_html( $pt ); ?></code>
                    </td>
                    <td>
                        <input type="number"
                               name="wssf_pt_weight_<?php echo esc_attr( $pt ); ?>"
                               value="<?php echo esc_attr( (string) $w ); ?>"
                               min="0" max="10" step="0.1" class="small-text">
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <?php submit_button( __( 'Сохранить настройки ранжирования', 'wp-smart-search-frontend' ) ); ?>
    </form>
</div>

<!-- Информация о бустах -->
<div class="card wssf-card">
    <h2><span class="dashicons dashicons-info-outline"></span>
        <?php esc_html_e( 'Как это влияет на поиск', 'wp-smart-search-frontend' ); ?>
    </h2>

    <ol style="margin-left:20px;line-height:1.7;">
        <li>
            <?php esc_html_e(
                'IDF² — редкие термины (SHA256, GUID, редкие IOC) получают больший вес. Работает автоматически.',
                'wp-smart-search-frontend'
            ); ?>
        </li>
        <li>
            <?php esc_html_e(
                'Exact match — если весь запрос целиком встречается в title (×5) или content (×2), релевантность резко растёт.',
                'wp-smart-search-frontend'
            ); ?>
        </li>
        <li>
            <?php esc_html_e(
                'Post type weights — множитель по типу контента. Полезно, если новости перебивают базу уязвимостей.',
                'wp-smart-search-frontend'
            ); ?>
        </li>
        <li>
            <?php esc_html_e(
                'Term balance — для AND-запросов штрафует документы с неравномерным распределением терминов. ' .
                '0 отключает.',
                'wp-smart-search-frontend'
            ); ?>
        </li>
        <li>
            <?php esc_html_e(
                'Phrase SQL — если запрос в кавычках ("точная фраза"), оставляются только посты, ' .
                'содержащие эту фразу как точную подстроку в title или content.',
                'wp-smart-search-frontend'
            ); ?>
        </li>
        <li>
            <?php esc_html_e(
                'OR fallback — если строгий AND не дал результатов, автоматически ищется OR ' .
                'с BM25-ранжированием по всем терминам.',
                'wp-smart-search-frontend'
            ); ?>
        </li>
    </ol>
</div>