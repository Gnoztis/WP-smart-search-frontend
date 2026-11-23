<?php
declare(strict_types=1);

namespace WSSF;

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Assets {

    private const CSS_MODULES = [
        'assets/css/wssf-variables.css',
        'assets/css/wssf-base.css',
        'assets/css/wssf-form.css',
        'assets/css/wssf-progress.css',
        'assets/css/wssf-skeleton.css',
        'assets/css/wssf-sections.css',
        'assets/css/wssf-tags.css',
        'assets/css/wssf-posts.css',
        'assets/css/wssf-pagination.css',
        'assets/css/wssf-active-filters.css',
        'assets/css/wssf-history.css',
        'assets/css/wssf-suggestions.css',
        'assets/css/wssf-facets-panel.css',
        'assets/css/wssf-mobile.css',
        'assets/css/wssf-autocomplete.css',
        'assets/css/wssf-similar.css',
    ];

    private const JS_MODULES = [
        'assets/js/wssf-config.js',
        'assets/js/wssf-state.js',
        'assets/js/wssf-utils.js',
        'assets/js/wssf-progress.js',
        'assets/js/wssf-url.js',
        'assets/js/wssf-filters.js',
        'assets/js/wssf-skeleton.js',
        'assets/js/wssf-render-tags.js',
        'assets/js/wssf-render-posts.js',
        'assets/js/wssf-api.js',
        'assets/js/wssf-suggestions.js',
        'assets/js/wssf-search.js',
        'assets/js/wssf-history.js',
        'assets/js/wssf-facets-panel.js',
        'assets/js/wssf-autocomplete.js',
        'assets/js/wssf-render-similar.js',
        'assets/js/wssf-events.js',
        'assets/js/wssf-mobile.js',
    ];

    public function __construct( private Initial_State $initial ) {}

    public function enqueue_assets(): void {
        if ( ! is_search() ) { return; }

        $css = $this->load_css();
        $js  = $this->load_js();

        $url_mode = (bool) apply_filters( 'wssf_asset_url_mode', false );

        $inline_css = ! $url_mode && $css !== '';
        if ( $inline_css ) {
            wp_register_style( 'wssf-search', false, [], \WSSF_VERSION );
            wp_enqueue_style( 'wssf-search' );
            wp_add_inline_style( 'wssf-search', $css );
        } elseif ( $url_mode && is_readable( \WSSF_PATH . 'assets/search.css' ) ) {
            wp_enqueue_style( 'wssf-search', \WSSF_URL . 'assets/search.css', [], \WSSF_VERSION );
        }

        $inline_js = ! $url_mode && $js !== '';
        if ( $inline_js ) {
            wp_register_script( 'wssf-search', false, [ 'jquery' ], \WSSF_VERSION, true );
            wp_enqueue_script( 'wssf-search' );
        } elseif ( $url_mode && is_readable( \WSSF_PATH . 'assets/search.js' ) ) {
            wp_register_script( 'wssf-search', \WSSF_URL . 'assets/search.js', [ 'jquery' ], \WSSF_VERSION, true );
            wp_enqueue_script( 'wssf-search' );
        }

        if ( $css === '' && $js === '' ) { return; }

        $dym_on     = class_exists( 'WSSF_Admin_Settings' ) ? Admin_Settings::is_did_you_mean_enabled() : true;
        $sug_on     = class_exists( 'WSSF_Admin_Settings' ) ? Admin_Settings::is_suggestions_enabled() : true;
        $history_on = class_exists( 'WSSF_Admin_Settings' ) ? Admin_Settings::is_history_enabled() : true;
        $ac_on      = class_exists( 'WSSF_Admin_Settings' ) ? Admin_Settings::is_autocomplete_enabled() : true;
        $ac_min     = class_exists( 'WSSF_Admin_Settings' ) ? Admin_Settings::get_autocomplete_min_len() : 2;

        $data = [
            'ajaxUrl'              => admin_url( 'admin-ajax.php' ),
            'nonce'                => wp_create_nonce( Plugin::NONCE_ACTION ),
            'action'               => Plugin::AJAX_ACTION,
            'suggestAction'        => Plugin::SUGGEST_ACTION,
            'autocompleteEnabled'  => (bool) $ac_on,
            'autocompleteMinLen'   => (int) $ac_min,
            'minLen'               => Plugin::get_min_len(),
            'initialQuery'         => $this->initial->get_query(),
            'initialCat'           => $this->initial->get_cat(),
            'initialType'          => $this->initial->get_type(),
            'initialTags'          => $this->initial->get_tags(),
            'initialExcludeTags'   => $this->initial->get_exclude_tags(),
            'allowedPostTypes'     => Search_Engine::get_post_types(),
            'userId'               => get_current_user_id(),
            'pageUrl'              => home_url( '/' ),
            'isLoggedIn'           => is_user_logged_in(),
            'didYouMeanEnabled'    => (bool) $dym_on,
            'suggestionsEnabled'   => (bool) $sug_on,
            'historyEnabled'       => (bool) $history_on,
            'debug'                => (bool) apply_filters( 'wssf_debug', false ),
            'i18n'                 => [
                'searching'               => __( 'Поиск…', 'wp-smart-search-frontend' ),
                'noResults'               => __( 'Ничего не найдено', 'wp-smart-search-frontend' ),
                'noTags'                  => __( 'Метки не найдены', 'wp-smart-search-frontend' ),
                'noPosts'                 => __( 'Ничего не найдено', 'wp-smart-search-frontend' ),
                'error'                   => __( 'Ошибка поиска', 'wp-smart-search-frontend' ),
                'errorNetwork'            => __( 'Сеть недоступна. Проверьте подключение.', 'wp-smart-search-frontend' ),
                'error403'                => __( 'Проверка безопасности не прошла. Обновите страницу.', 'wp-smart-search-frontend' ),
                'error400'                => __( 'Некорректный запрос.', 'wp-smart-search-frontend' ),
                'error429'                => __( 'Слишком много запросов. Подождите немного.', 'wp-smart-search-frontend' ),
                'error500'                => __( 'Ошибка сервера. Попробуйте позже.', 'wp-smart-search-frontend' ),
                'tags'                    => __( 'Метки', 'wp-smart-search-frontend' ),
                'posts'                   => __( 'Записи', 'wp-smart-search-frontend' ),
                'postsAndPages'           => __( 'Записи и страницы', 'wp-smart-search-frontend' ),
                'typePost'                => __( 'Запись', 'wp-smart-search-frontend' ),
                'typePage'                => __( 'Страница', 'wp-smart-search-frontend' ),
                'minLenTpl'               => __( 'Введите не менее %d символов', 'wp-smart-search-frontend' ),
                'searchHint'              => __( 'Введите запрос или выберите фильтры', 'wp-smart-search-frontend' ),
                'prevPage'                => __( 'Предыдущая страница', 'wp-smart-search-frontend' ),
                'nextPage'                => __( 'Следующая страница', 'wp-smart-search-frontend' ),
                'pageLabel'               => __( 'Страница %d', 'wp-smart-search-frontend' ),
                'titleTpl'                => __( 'Результаты поиска: %s', 'wp-smart-search-frontend' ),
                'searchTag'               => __( 'Искать записи с меткой «%s»', 'wp-smart-search-frontend' ),
                'clearSearch'             => __( 'Очистить строку поиска', 'wp-smart-search-frontend' ),
                'includeTag'              => __( 'Включить фильтр по метке «%s»', 'wp-smart-search-frontend' ),
                'unincludeTag'            => __( 'Убрать фильтр по метке «%s»', 'wp-smart-search-frontend' ),
                'excludeTag'              => __( 'Исключить записи с меткой «%s»', 'wp-smart-search-frontend' ),
                'unexcludeTag'            => __( 'Убрать исключение метки «%s»', 'wp-smart-search-frontend' ),
                'activeIncludes'          => __( 'Включено:', 'wp-smart-search-frontend' ),
                'activeExcludes'          => __( 'Исключено:', 'wp-smart-search-frontend' ),
                'clearAllFilters'         => __( 'Сбросить все', 'wp-smart-search-frontend' ),
                'removeFilter'            => __( 'Убрать', 'wp-smart-search-frontend' ),
                'historyTitle'            => __( 'История поиска', 'wp-smart-search-frontend' ),
                'historyClear'            => __( 'Очистить', 'wp-smart-search-frontend' ),
                'historyEmpty'            => __( 'Пока пусто', 'wp-smart-search-frontend' ),
                'historyRemove'           => __( 'Убрать', 'wp-smart-search-frontend' ),
                'didYouMean'              => __( 'Возможно, вы имели в виду:', 'wp-smart-search-frontend' ),
                'didYouMeanAria'          => __( 'Искать «%s»', 'wp-smart-search-frontend' ),
                'suggestionsTitle'        => __( 'Ничего не найдено. Попробуйте:', 'wp-smart-search-frontend' ),
                'suggestionRemoveExclude' => __( 'Убрать исключения по меткам', 'wp-smart-search-frontend' ),
                'suggestionRemoveInclude' => __( 'Убрать фильтры по меткам', 'wp-smart-search-frontend' ),
                'suggestionRemoveLongest' => __( 'Убрать самое длинное слово', 'wp-smart-search-frontend' ),
                'suggestionOrMode'        => __( 'Искать любое из слов (OR)', 'wp-smart-search-frontend' ),
                'suggestionRemoveCat'     => __( 'Искать во всех категориях', 'wp-smart-search-frontend' ),
                'suggestionRemoveType'    => __( 'Искать все типы', 'wp-smart-search-frontend' ),
                'filtersToggle'           => __( 'Фильтры', 'wp-smart-search-frontend' ),
                'filtersPanelTitle'       => __( 'Фильтры', 'wp-smart-search-frontend' ),
                'filtersClose'            => __( 'Закрыть', 'wp-smart-search-frontend' ),
                'filterCatLabel'          => __( 'Категория', 'wp-smart-search-frontend' ),
                'filterTypeLabel'         => __( 'Тип контента', 'wp-smart-search-frontend' ),
                'filtersApply'            => __( 'Показать результаты', 'wp-smart-search-frontend' ),
                'autocompleteNo'          => __( 'Нет подсказок', 'wp-smart-search-frontend' ),
                'similarTitle'            => __( 'Похожие записи', 'wp-smart-search-frontend' ),
            ],
        ];

        wp_add_inline_script(
            'wssf-search',
            'window.WSSF = ' . wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP ) . ';',
            'before'
        );
        if ( $inline_js ) {
            wp_add_inline_script( 'wssf-search', $js, 'after' );
        }
    }

    private function load_css(): string {
        $css = '';
        foreach ( self::CSS_MODULES as $rel ) {
            $css .= $this->read_asset( $rel );
        }
        return $css;
    }

    private function load_js(): string {
        $js = '';
        foreach ( self::JS_MODULES as $rel ) {
            $js .= $this->read_asset( $rel );
        }
        return $js;
    }

    private function read_asset( string $relative_path ): string {
        $relative_path = ltrim( $relative_path, '/' );
        if ( $relative_path === '' || strpos( $relative_path, '..' ) !== false ) { return ''; }
        $path = \WSSF_PATH . $relative_path;
        if ( ! is_readable( $path ) ) { return ''; }
        $content = @file_get_contents( $path );
        return is_string( $content ) ? $content : '';
    }
}