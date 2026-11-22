window.WSSF_Facets_Panel = (function ($) {
    'use strict';

    var S = window.WSSF_State;
    var C = window.WSSF_Config;
    var U = window.WSSF_Utils;

    var $toggle = null, $panel = null, $backdrop = null, $close = null, $body = null;
    var isOpen = false;
    var $savedNodes = null;

    function isMobile() {
        return window.matchMedia && window.matchMedia('(max-width: 767.98px)').matches;
    }

    function cacheNodes() {
        if ($toggle) return;
        $toggle   = $('#wssf-filters-toggle');
        $panel    = $('#wssf-facets-panel');
        $backdrop = $('#wssf-facets-backdrop');
        $close    = $('#wssf-facets-close');
        $body     = $panel.find('.wssf-facets-panel-body');
    }

    function moveIntoPanel() {
        if (!isMobile() || $savedNodes) return;
        var $cat     = S.$cat;
        var $type    = S.$type;
        var $filters = S.$activeFilters;

        // Запоминаем где были — чтобы вернуть точно туда же
        $savedNodes = {
            catParent:     $cat.parent(),
            typeParent:    $type.parent(),
            filtersParent: $filters.parent(),
        };

        var $catWrap = $('<div class="wssf-facets-row"></div>');
        $catWrap.append(
            $('<label class="wssf-facets-label"></label>').text(C.i18n('filterCatLabel', 'Категория'))
        ).append($cat);

        var $typeWrap = $('<div class="wssf-facets-row"></div>');
        $typeWrap.append(
            $('<label class="wssf-facets-label"></label>').text(C.i18n('filterTypeLabel', 'Тип контента'))
        ).append($type);

        var $filtersWrap = $('<div class="wssf-facets-active"></div>').append($filters);

        $body.empty().append($catWrap, $typeWrap, $filtersWrap);
    }

    function moveBack() {
        if (!isMobile() || !$savedNodes) return;
        var $form = $('.wssf-search-form');

        if ($form.length) {
            var $inputWrap = $form.find('.wssf-input-wrap');
            if ($inputWrap.length) {
                $inputWrap.after(S.$cat);
                S.$cat.after(S.$type);
            } else {
                $form.prepend(S.$cat);
                S.$cat.after(S.$type);
            }
            $form.after(S.$activeFilters);
        }
        $savedNodes = null;
    }

    function open() {
        if (isOpen) return;
        cacheNodes();
        if (!$panel.length) return;
        moveIntoPanel();
        $panel.addClass('is-open').attr('aria-hidden', 'false');
        $backdrop.removeAttr('hidden').addClass('is-open');
        $toggle.attr('aria-expanded', 'true');
        $('body').addClass('wssf-facets-open');
        isOpen = true;
    }

    function close() {
        if (!isOpen) return;
        $panel.removeClass('is-open').attr('aria-hidden', 'true');
        $backdrop.removeClass('is-open').attr('hidden', 'hidden');
        $toggle.attr('aria-expanded', 'false');
        $('body').removeClass('wssf-facets-open');
        moveBack();
        isOpen = false;
    }

    function updateBadge() {
        if (!$toggle || !$toggle.length) return;
        var n = S.activeTagIds.length + S.activeExcludeTagIds.length;
        if (S.$cat.length && S.$cat.val()) n++;
        if (S.$type.length && S.$type.val()) n++;
        var $badge = $toggle.find('.wssf-filters-toggle-badge');
        if (n > 0) { $badge.text(n).removeAttr('hidden'); }
        else       { $badge.attr('hidden', 'hidden'); }
    }

    function bind() {
        cacheNodes();
        if (!$toggle.length) return;

        $toggle.on('click', function (e) {
            e.preventDefault();
            isOpen ? close() : open();
        });
        $close.on('click', function (e) { e.preventDefault(); close(); });
        $backdrop.on('click', function () { close(); });

        $(document).on('keydown', function (e) {
            if (e.key === 'Escape' && isOpen) { close(); }
        });

        // Хук на renderActiveFilters — обновляем бейдж после любых изменений
        var origRender = window.WSSF_Filters.renderActiveFilters;
        window.WSSF_Filters.renderActiveFilters = function () {
            origRender.apply(this, arguments);
            updateBadge();
        };

        // Также при смене cat/type — пересчитать бейдж
        $(document).on('change', '#wssf-cat, #wssf-type', function () {
            updateBadge();
        });

        updateBadge();
    }

    return { bind: bind, open: open, close: close, updateBadge: updateBadge };
})(jQuery);