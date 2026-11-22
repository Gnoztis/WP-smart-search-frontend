window.WSSF_Config = (function ($) {
    'use strict';

    var W = (window.WSSF && typeof window.WSSF === 'object') ? window.WSSF : {};

    var MIN_LEN = parseInt(W.minLen, 10);
    if (isNaN(MIN_LEN) || MIN_LEN < 1) { MIN_LEN = 3; }

    function i18n(key, fallback) {
        if (W && W.i18n && W.i18n[key]) { return String(W.i18n[key]); }
        return fallback || '';
    }

    var MIN_LEN_MSG = (W && W.i18n && W.i18n.minLenTpl)
        ? String(W.i18n.minLenTpl).replace('%d', MIN_LEN)
        : 'Введите не менее ' + MIN_LEN + ' символов';

    var EMPTY_MSG = (W && W.i18n && W.i18n.searchHint)
        ? String(W.i18n.searchHint)
        : 'Введите запрос или выберите фильтры';

    return {
        W: W,
        MIN_LEN: MIN_LEN,
        MIN_LEN_MSG: MIN_LEN_MSG,
        EMPTY_MSG: EMPTY_MSG,
        POST_TAGS_LIMIT: 6,
        DEBOUNCE_MS: 500,
        i18n: i18n
    };
})(jQuery);