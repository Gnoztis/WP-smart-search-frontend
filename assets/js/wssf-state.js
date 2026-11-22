window.WSSF_State = (function ($) {
    'use strict';

    return {
        $input:         $('#wssf-input'),
        $clear:         $('#wssf-clear'),
        $cat:           $('#wssf-cat'),
        $type:          $('#wssf-type'),
        $submit:        $('.wssf-submit'),
        $status:        $('#wssf-status'),
        $results:       $('#wssf-results'),
        $progress:      $('#wssf-progress'),
        $bar:           $('#wssf-progress .wssf-progress-bar'),
        $titleQ:        $('#wssf-title-query'),
        $wrap:          $('.wssf-wrap'),
        $activeFilters: $('#wssf-active-filters'),
        $dym:           $('#wssf-did-you-mean-slot'),

        lastQuery:   '',
        lastCat:     0,
        lastType:    '',
        lastSig:     '',
        inflightSig: '',
        xhrMain:     null,
        pendingCount: 0,
        anyError:    false,

        searchDebounceTimer: null,

        activeTagIds: [],
        activeTagNames: {},
        activeExcludeTagIds: [],
        activeExcludeTagNames: {},

        // suggestions: { label, apply } — заполняется при 0 результатов
        suggestions: [],

        // History
        isLoggedIn: !!(window.WSSF && window.WSSF.isLoggedIn),

        progressTimer:   null,
        progressValue:   0,
        progressHideTid: null
    };
})(jQuery);