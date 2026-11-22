window.WSSF_Filters = (function ($) {
    'use strict';

    var S = window.WSSF_State;
    var C = window.WSSF_Config;
    var U = window.WSSF_Utils;

    function isTagIncluded(id) {
        id = parseInt(id, 10);
        return !isNaN(id) && id > 0 && S.activeTagIds.indexOf(id) !== -1;
    }

    function isTagExcluded(id) {
        id = parseInt(id, 10);
        return !isNaN(id) && id > 0 && S.activeExcludeTagIds.indexOf(id) !== -1;
    }

    function renderActiveFilters() {
        if (!S.$activeFilters.length) return;

        if (!S.activeTagIds.length && !S.activeExcludeTagIds.length) {
            S.$activeFilters.attr('hidden', 'hidden').empty();
            return;
        }

        var i18nIncLabel = C.i18n('activeIncludes',  'Включено:');
        var i18nExcLabel = C.i18n('activeExcludes',  'Исключено:');
        var i18nClear    = C.i18n('clearAllFilters', 'Сбросить все');
        var i18nRemove   = C.i18n('removeFilter',    'Убрать');

        var html = '';

        if (S.activeTagIds.length) {
            html += '<span class="wssf-active-filters-label wssf-active-filters-label--include">' +
                U.escapeHtml(i18nIncLabel) + '</span>';
            S.activeTagIds.forEach(function (id) {
                var name = S.activeTagNames[id] || ('#' + id);
                var label = i18nRemove + ': ' + name;
                html += '<span class="wssf-filter-chip wssf-filter-chip--include">';
                html +=     '<span class="wssf-filter-chip-name">' + U.escapeHtml(name) + '</span>';
                html +=     '<button type="button" class="wssf-filter-remove"';
                html +=         ' data-tag-id="' + id + '" data-kind="include"';
                html +=         ' aria-label="' + U.escapeHtml(label) + '"';
                html +=         ' title="' + U.escapeHtml(label) + '">';
                html +=         '<span aria-hidden="true">×</span>';
                html +=     '</button>';
                html += '</span>';
            });
        }

        if (S.activeExcludeTagIds.length) {
            html += '<span class="wssf-active-filters-label wssf-active-filters-label--exclude">' +
                U.escapeHtml(i18nExcLabel) + '</span>';
            S.activeExcludeTagIds.forEach(function (id) {
                var name = S.activeExcludeTagNames[id] || ('#' + id);
                var label = i18nRemove + ': ' + name;
                html += '<span class="wssf-filter-chip wssf-filter-chip--exclude">';
                html +=     '<span class="wssf-filter-chip-name">' + U.escapeHtml(name) + '</span>';
                html +=     '<button type="button" class="wssf-filter-remove"';
                html +=         ' data-tag-id="' + id + '" data-kind="exclude"';
                html +=         ' aria-label="' + U.escapeHtml(label) + '"';
                html +=         ' title="' + U.escapeHtml(label) + '">';
                html +=         '<span aria-hidden="true">×</span>';
                html +=     '</button>';
                html += '</span>';
            });
        }

        html += '<button type="button" class="wssf-filter-clear">' + U.escapeHtml(i18nClear) + '</button>';

        S.$activeFilters.removeAttr('hidden').html(html);
    }

    function addInclude(id, name) {
        id = parseInt(id, 10);
        if (isNaN(id) || id <= 0) return;

        var exIdx = S.activeExcludeTagIds.indexOf(id);
        if (exIdx !== -1) {
            S.activeExcludeTagIds.splice(exIdx, 1);
            delete S.activeExcludeTagNames[id];
        }
        if (S.activeTagIds.indexOf(id) === -1) {
            S.activeTagIds.push(id);
            S.activeTagNames[id] = String(name || '');
        }
        renderActiveFilters();
        window.WSSF_Search.doSearch(true);
    }

    function removeInclude(id) {
        id = parseInt(id, 10);
        var idx = S.activeTagIds.indexOf(id);
        if (idx === -1) return;
        S.activeTagIds.splice(idx, 1);
        delete S.activeTagNames[id];
        renderActiveFilters();
        window.WSSF_Search.doSearch(true);
    }

    function addExclude(id, name) {
        id = parseInt(id, 10);
        if (isNaN(id) || id <= 0) return;

        var inIdx = S.activeTagIds.indexOf(id);
        if (inIdx !== -1) {
            S.activeTagIds.splice(inIdx, 1);
            delete S.activeTagNames[id];
        }
        if (S.activeExcludeTagIds.indexOf(id) === -1) {
            S.activeExcludeTagIds.push(id);
            S.activeExcludeTagNames[id] = String(name || '');
        }
        renderActiveFilters();
        window.WSSF_Search.doSearch(true);
    }

    function removeExclude(id) {
        id = parseInt(id, 10);
        var idx = S.activeExcludeTagIds.indexOf(id);
        if (idx === -1) return;
        S.activeExcludeTagIds.splice(idx, 1);
        delete S.activeExcludeTagNames[id];
        renderActiveFilters();
        window.WSSF_Search.doSearch(true);
    }

    function toggleInclude(id, name) {
        id = parseInt(id, 10);
        if (isNaN(id) || id <= 0) return;
        if (isTagExcluded(id)) { addInclude(id, name); return; }
        if (isTagIncluded(id)) { removeInclude(id); return; }
        addInclude(id, name);
    }

    function toggleExclude(id, name) {
        id = parseInt(id, 10);
        if (isNaN(id) || id <= 0) return;
        if (isTagIncluded(id)) { addExclude(id, name); return; }
        if (isTagExcluded(id)) { removeExclude(id); return; }
        addExclude(id, name);
    }

    function clearAll() {
        if (!S.activeTagIds.length && !S.activeExcludeTagIds.length) return;
        S.activeTagIds = []; S.activeTagNames = {};
        S.activeExcludeTagIds = []; S.activeExcludeTagNames = {};
        renderActiveFilters();
        window.WSSF_Search.doSearch(true);
    }

    return {
        isTagIncluded: isTagIncluded,
        isTagExcluded: isTagExcluded,
        renderActiveFilters: renderActiveFilters,
        addInclude: addInclude,
        removeInclude: removeInclude,
        addExclude: addExclude,
        removeExclude: removeExclude,
        toggleInclude: toggleInclude,
        toggleExclude: toggleExclude,
        clearAll: clearAll
    };
})(jQuery);