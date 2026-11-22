<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! current_user_can( 'manage_options' ) ) { return; }

$prf_on  = (bool) get_option( 'wssf_semantic_prf', 0 );
$syn_on  = (bool) get_option( 'wssf_semantic_synonyms', 0 );
$typo_on = (bool) get_option( 'wssf_semantic_typo', 0 );
?>

<div class="card wssf-card">
    <h2><span class="dashicons dashicons-networking"></span>
        <?php esc_html_e( 'Расширение запроса', 'wp-smart-search-frontend' ); ?>
    </h2>

    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
        <?php wp_nonce_field( 'wssf_save_semantic' ); ?>
        <input type="hidden" name="action" value="wssf_save_semantic">

        <table class="form-table" role="presentation">

            <tr>
                <th scope="row">
                    <?php esc_html_e( 'Pseudo Relevance Feedback', 'wp-smart-search-frontend' ); ?>
                </th>
                <td>
                    <label>
                        <input type="checkbox" name="wssf_semantic_prf" value="1" <?php checked( $prf_on ); ?>>
                        <?php esc_html_e( 'Расширять запрос топ-термами и биграммами из первых результатов', 'wp-smart-search-frontend' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e(
                            'Rocchio/RM3 с Dirichlet-сглаживанием. Берём топ-20 постов, извлекаем до 8 униграмм и до 5 биграмм, добавляем в запрос как опциональные. Оригинальные условия остаются обязательными. Помогает при синонимах и перефразировках.',
                            'wp-smart-search-frontend'
                        ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <?php esc_html_e( 'Синонимия через теги', 'wp-smart-search-frontend' ); ?>
                </th>
                <td>
                    <label>
                        <input type="checkbox" name="wssf_semantic_synonyms" value="1" <?php checked( $syn_on ); ?>>
                        <?php esc_html_e( 'Добавлять «соседние» теги к запросу', 'wp-smart-search-frontend' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e(
                            'Если в запросе есть имя тега, ищем теги, которые часто встречаются вместе с ним, и добавляем до 3 их стемов в запрос. Результат кешируется на 1 час.',
                            'wp-smart-search-frontend'
                        ); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <?php esc_html_e( 'Опечатки', 'wp-smart-search-frontend' ); ?>
                </th>
                <td>
                    <label>
                        <input type="checkbox" name="wssf_semantic_typo" value="1" <?php checked( $typo_on ); ?>>
                        <?php esc_html_e( 'Искать с учётом расстояния Левенштейна', 'wp-smart-search-frontend' ); ?>
                    </label>
                    <p class="description">
                        <?php esc_html_e(
                            'Применяется только когда базовый поиск вернул 0 результатов. Использует BK-tree и n-gram индекс по словарю стемов из индекса. Помогает при опечатках типа «уязвимост» → «уязвимость».',
                            'wp-smart-search-frontend'
                        ); ?>
                    </p>
                </td>
            </tr>

        </table>

        <?php submit_button( __( 'Сохранить настройки', 'wp-smart-search-frontend' ) ); ?>
    </form>
</div>

<div class="card wssf-card">
    <h2><span class="dashicons dashicons-info-outline"></span>
        <?php esc_html_e( 'Как это работает', 'wp-smart-search-frontend' ); ?>
    </h2>

    <ol style="margin-left:20px;line-height:1.7;">
        <li>
            <?php esc_html_e(
                'Запрос разбивается на стемы, строится BOOLEAN-строка и выполняется первый BM25-поиск (top-200).',
                'wp-smart-search-frontend'
            ); ?>
        </li>
        <li>
            <?php esc_html_e(
                'Если найдено 1–100 результатов и включён PRF — берутся топ-20 постов из индекса, ' .
                'вычисляются веса Rocchio/RM3, извлекаются униграммы и биграммы, запрос расширяется ' .
                'и выполняется повторный поиск.',
                'wp-smart-search-frontend'
            ); ?>
        </li>
        <li>
            <?php esc_html_e(
                'Если в запросе есть имя тега и включена синонимия — ищутся «соседние» теги на постах ' .
                'с этим тегом, их стемы добавляются в запрос.',
                'wp-smart-search-frontend'
            ); ?>
        </li>
        <li>
            <?php esc_html_e(
                'Если найдено 0 результатов и включены опечатки — по BK-tree и n-gram индексу подбираются ' .
                'варианты с edit distance ≤ 1–2 и запрос повторяется.',
                'wp-smart-search-frontend'
            ); ?>
        </li>
        <li>
            <?php esc_html_e(
                'Все полученные списки (BM25, PRF, fuzzy) сливаются через Reciprocal Rank Fusion (k=60), ' .
                'результат пагинируется и отдаётся клиенту.',
                'wp-smart-search-frontend'
            ); ?>
        </li>
    </ol>

    <p class="description">
        <?php esc_html_e(
            'Все три техники опциональны и включаются независимо. Если все выключены, ' .
            'поиск работает как классический BM25 + LIKE fallback.',
            'wp-smart-search-frontend'
        ); ?>
    </p>
</div>