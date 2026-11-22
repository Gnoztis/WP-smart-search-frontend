<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

get_header();

$raw_s = '';
if ( isset( $_GET['s'] ) ) {
    $raw_s = wp_unslash( $_GET['s'] );
    if ( ! is_string( $raw_s ) ) { $raw_s = ''; }
}
if ( $raw_s === '' && ! empty( $_SERVER['REQUEST_URI'] ) ) {
    $request_uri = (string) $_SERVER['REQUEST_URI'];
    $q_pos       = strpos( $request_uri, '?' );
    if ( $q_pos !== false ) {
        $qs     = substr( $request_uri, $q_pos + 1 );
        $params = [];
        parse_str( $qs, $params );
        if ( isset( $params['s'] ) && is_string( $params['s'] ) ) {
            $raw_s = $params['s'];
        }
    }
}
if ( $raw_s === '' ) { $raw_s = get_search_query(); }

$initial_query = is_string( $raw_s ) ? trim( $raw_s ) : '';
$min_len       = WSSF_Plugin::get_min_len();
$query_len     = mb_strlen( $initial_query );
$is_too_short  = ( $initial_query !== '' && $query_len < $min_len );
$has_query     = $initial_query !== '';

$current_cat  = isset( $_GET['cat'] ) ? absint( wp_unslash( $_GET['cat'] ) ) : 0;
$current_type = isset( $_GET['pt'] ) ? sanitize_key( wp_unslash( $_GET['pt'] ) ) : '';

$post_types = WSSF_Search_Engine::get_post_types();
if ( ! in_array( $current_type, $post_types, true ) ) {
    $current_type = '';
}

$type_labels = [];
foreach ( $post_types as $pt ) {
    $obj = get_post_type_object( $pt );
    if ( $obj && isset( $obj->labels->name ) && (string) $obj->labels->name !== '' ) {
        $type_labels[ $pt ] = (string) $obj->labels->name;
    } else {
        $type_labels[ $pt ] = $pt;
    }
}

$categories = get_categories( [ 'hide_empty' => false ] );
?>

<main id="primary" class="site-main">
    <div class="site-main-inner">
        <div class="wssf-wrap">

            <h1 class="wssf-title">
                <?php
                printf(
                    esc_html__( 'Результаты поиска: %s', 'wp-smart-search-frontend' ),
                    '<span id="wssf-title-query">' . esc_html( $initial_query ) . '</span>'
                );
                ?>
            </h1>

            <form role="search" method="get" class="wssf-search-form"
                  action="<?php echo esc_url( home_url( '/' ) ); ?>">

                <div class="wssf-input-wrap">
                    <input type="search"
                           name="s"
                           id="wssf-input"
                           class="wssf-input<?php if ( $is_too_short ) { echo ' wssf-input--error'; } ?>"
                           value="<?php echo esc_attr( $initial_query ); ?>"
                           placeholder="<?php echo esc_attr_x( 'Поиск…', 'placeholder', 'wp-smart-search-frontend' ); ?>"
                           aria-label="<?php esc_attr_e( 'Поиск', 'wp-smart-search-frontend' ); ?>"
                           <?php if ( $is_too_short ) { echo 'aria-invalid="true"'; } ?>
                           autocomplete="off" />

                    <button type="button"
                            id="wssf-clear"
                            class="wssf-clear"
                            aria-label="<?php esc_attr_e( 'Очистить строку поиска', 'wp-smart-search-frontend' ); ?>"
                            title="<?php esc_attr_e( 'Очистить', 'wp-smart-search-frontend' ); ?>"
                            <?php if ( ! $has_query ) { echo 'hidden'; } ?>>
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>

                <?php if ( ! empty( $categories ) ) : ?>
                    <select name="cat" id="wssf-cat" class="wssf-select"
                            aria-label="<?php esc_attr_e( 'Фильтр по категории', 'wp-smart-search-frontend' ); ?>">
                        <option value=""><?php esc_html_e( 'Все категории', 'wp-smart-search-frontend' ); ?></option>
                        <?php foreach ( $categories as $cat ) : ?>
                            <option value="<?php echo esc_attr( $cat->term_id ); ?>"
                                <?php selected( $current_cat, (int) $cat->term_id ); ?>>
                                <?php echo esc_html( $cat->name ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>

                <?php if ( ! empty( $post_types ) ) : ?>
                    <select name="pt" id="wssf-type" class="wssf-select"
                            aria-label="<?php esc_attr_e( 'Тип контента', 'wp-smart-search-frontend' ); ?>">
                        <option value="" <?php selected( $current_type, '' ); ?>>
                            <?php esc_html_e( 'Всё', 'wp-smart-search-frontend' ); ?>
                        </option>
                        <?php foreach ( $post_types as $pt ) : ?>
                            <option value="<?php echo esc_attr( $pt ); ?>"
                                <?php selected( $current_type, $pt ); ?>>
                                <?php echo esc_html( $type_labels[ $pt ] ?? $pt ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>

                <button type="submit" class="wssf-submit">
                    <?php esc_html_e( 'Найти', 'wp-smart-search-frontend' ); ?>
                </button>

                <?php
                // Hidden fields of the CleanTalk check: empty unless the
                // integration is active (see WSSF_CleanTalk::active()).
                echo WSSF_CleanTalk::form_fields(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- third-party CleanTalk markup, returned only when the integration is active.
                ?>
            </form>

            <div id="wssf-did-you-mean-slot" class="wssf-did-you-mean-slot"></div>

            <button type="button" id="wssf-filters-toggle" class="wssf-filters-toggle"
                    aria-expanded="false" aria-controls="wssf-facets-panel">
                <span class="wssf-filters-toggle-icon" aria-hidden="true">⚙</span>
                <span class="wssf-filters-toggle-label"><?php esc_html_e( 'Фильтры', 'wp-smart-search-frontend' ); ?></span>
                <span class="wssf-filters-toggle-badge" hidden>0</span>
            </button>

            <div class="wssf-active-filters" id="wssf-active-filters" hidden></div>

            <div class="wssf-progress" id="wssf-progress" aria-hidden="true">
                <div class="wssf-progress-bar"></div>
            </div>

            <div id="wssf-status" class="wssf-status" role="status" aria-live="polite"><?php
                if ( $is_too_short ) {
                    printf(
                        esc_html__( 'Введите не менее %d символов', 'wp-smart-search-frontend' ),
                        $min_len
                    );
                }
            ?></div>

            <div id="wssf-results" class="wssf-results"></div>

        </div>
    </div>
</main>

<div class="wssf-facets-panel" id="wssf-facets-panel" aria-hidden="true" role="dialog" aria-modal="true">
    <div class="wssf-facets-panel-header">
        <span class="wssf-facets-panel-title"><?php esc_html_e( 'Фильтры', 'wp-smart-search-frontend' ); ?></span>
        <button type="button" class="wssf-facets-panel-close" id="wssf-facets-close"
                aria-label="<?php esc_attr_e( 'Закрыть', 'wp-smart-search-frontend' ); ?>">×</button>
    </div>
    <div class="wssf-facets-panel-body"></div>
</div>
<div class="wssf-facets-backdrop" id="wssf-facets-backdrop" hidden></div>

<?php
get_footer();