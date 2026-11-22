window.WSSF_Events = (function ($) {
    'use strict';

    var S      = window.WSSF_State;
    var C      = window.WSSF_Config;
    var U      = window.WSSF_Utils;
    var F      = window.WSSF_Filters;
    var Search = window.WSSF_Search;

    function clearDebounce() {
        if (S.searchDebounceTimer) {
            clearTimeout(S.searchDebounceTimer);
            S.searchDebounceTimer = null;
        }
    }

    function bind() {
        // ---- Перехват формы шапки (не даём GET-запросу уйти) ----
        $(document).on('submit', 'form.search-form', function (e) {
            var $form = $(this);
            if ($form.hasClass('wssf-search-form')) return;
            e.preventDefault();
            var q = $.trim($form.find('input[name="s"]').val() || '');
            if (!q) return;
            S.$input.val(q);
            U.syncClearVisibility();
            clearDebounce();
            Search.doSearch(true);
            var $target = S.$results.length ? S.$results : S.$wrap;
            if ($target.length) {
                $('html, body').animate({ scrollTop: Math.max(0, $target.offset().top - 40) }, 200);
            }
        });

        // ---- Горячая клавиша `/` — фокус в поиск ----
        $(document).on('keydown', function (e) {
            if (e.key !== '/') return;
            var tag = (e.target && e.target.tagName || '').toUpperCase();
            if (tag === 'INPUT' || tag === 'TEXTAREA' || (e.target && e.target.isContentEditable)) return;
            e.preventDefault();
            S.$input.trigger('focus');
        });

        // ---- Ввод в поле ----
        S.$input.on('input', function () {
            U.syncClearVisibility();
            clearDebounce();

            var q = $.trim(S.$input.val());
            var cat = U.getSelectedCat();
            var type = U.getSelectedType();
            var hasFilt = S.activeTagIds.length || S.activeExcludeTagIds.length || cat > 0 || type !== '';

            if (q.length < C.MIN_LEN && !hasFilt) { Search.doSearch(false); return; }

            var sig = Search.requestSignature(q, cat, type, S.activeTagIds, S.activeExcludeTagIds);
            if (sig === S.lastSig) return;

            S.searchDebounceTimer = setTimeout(function () {
                S.searchDebounceTimer = null;
                Search.doSearch(false);
            }, C.DEBOUNCE_MS);
        });

        // ---- Enter / Esc / Ctrl+Enter на поле ----
        S.$input.on('keydown', function (e) {
            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                e.preventDefault();
                clearDebounce();
                Search.doSearch(true);
                return;
            }
            if (e.key === 'Enter') {
                e.preventDefault();
                clearDebounce();
                Search.doSearch(true);
            }
            if (e.key === 'Escape') {
                if ($.trim(S.$input.val() || '') !== '') {
                    e.preventDefault();
                    S.$clear.trigger('click');
                }
            }
        });

        S.$clear.on('click', function (e) {
            e.preventDefault();
            clearDebounce();
            S.$input.val('');
            U.syncClearVisibility();
            Search.doSearch(true);
            try { S.$input.trigger('focus'); } catch (err) {}
        });

        S.$cat.on('change', function () { clearDebounce(); Search.doSearch(true); });

        S.$type.on('change', function () {
            if (U.getSelectedType() === 'page' && S.$cat.length) S.$cat.val('');
            clearDebounce();
            Search.doSearch(true);
        });

        S.$submit.on('click', function (e) {
            e.preventDefault();
            clearDebounce();
            Search.doSearch(true);
            try { S.$input.trigger('focus'); } catch (err) {}
        });

        // ---- Пагинация ----
        S.$results.on('click', '.wssf-page[data-page]', function (e) {
            e.preventDefault();
            var $btn = $(this);
            if ($btn.hasClass('disabled') || $btn.hasClass('active')) return;
            var p = parseInt($btn.attr('data-page'), 10);
            if (p > 0) {
                Search.loadPostsPage(p);
                var $section = $btn.closest('.wssf-slot-posts');
                if ($section.length) {
                    $('html, body').animate({ scrollTop: Math.max(0, $section.offset().top - 40) }, 200);
                }
            }
        });

        // ---- Клики по тегам и фильтрам (внутри #wssf-results) ----
        S.$results.on('click', '.wssf-tag-search', function (e) {
            e.preventDefault(); e.stopPropagation();
            Search.runTagSearch($(this).attr('data-tag'));
        });
        S.$results.on('click', '.wssf-tag-include, .wssf-post-tag-include', function (e) {
            e.preventDefault(); e.stopPropagation();
            var $b = $(this);
            F.toggleInclude($b.attr('data-tag-id'), $b.attr('data-tag-name') || '');
        });
        S.$results.on('click', '.wssf-tag-exclude, .wssf-post-tag-exclude', function (e) {
            e.preventDefault(); e.stopPropagation();
            var $b = $(this);
            F.toggleExclude($b.attr('data-tag-id'), $b.attr('data-tag-name') || '');
        });

        // ---- Активные фильтры и кнопка «Сбросить все» (внутри #wssf-active-filters) ----
        S.$wrap.on('click', '.wssf-filter-remove', function (e) {
            e.preventDefault(); e.stopPropagation();
            var id = parseInt($(this).attr('data-tag-id'), 10);
            var kind = $(this).attr('data-kind') || 'include';
            if (kind === 'exclude') F.removeExclude(id);
            else                    F.removeInclude(id);
        });
        S.$wrap.on('click', '.wssf-filter-clear', function (e) {
            e.preventDefault(); e.stopPropagation();
            F.clearAll();
        });

        // ---- Клики по suggestions (внутри #wssf-results) ----
        S.$results.on('click', '.wssf-suggestion', function (e) {
            e.preventDefault();
            var idx = parseInt($(this).attr('data-idx'), 10);
            if (isNaN(idx) || !S.suggestions || !S.suggestions[idx]) return;
            var fn = S.suggestions[idx].apply;
            if (typeof fn === 'function') fn();
        });

        // ---- Клик по «Возможно, вы имели в виду…» ----
        // Кнопка рендерится в #wssf-did-you-mean-slot, который находится
        // ВНЕ #wssf-results. Поэтому делегируем на document, а не на $results.
        $(document).on('click', '.wssf-did-you-mean-btn', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var q = String($(this).attr('data-query') || '').trim();
            if (!q) return;
            S.$input.val(q);
            U.syncClearVisibility();
            clearDebounce();
            Search.doSearch(true, q);
            try { S.$input.trigger('focus'); } catch (err) {}
        });
    }

    $(function () {
        if (!S.$input.length || !S.$results.length) return;

        if (window.WSSF_History && typeof window.WSSF_History.init === 'function') {
            window.WSSF_History.init();
        }
        if (window.WSSF_Facets_Panel && typeof window.WSSF_Facets_Panel.bind === 'function') {
            window.WSSF_Facets_Panel.bind();
        }

        bind();
        setTimeout(function () { Search.initSearch(); }, 50);
    });

    return { bind: bind };
})(jQuery);