window.WSSF_RenderTags = (function ($) {
    'use strict';

    var C = window.WSSF_Config;
    var U = window.WSSF_Utils;
    var F = window.WSSF_Filters;

    function tagChipHtml(t) {
        var tagId    = parseInt(t && t.id, 10) || 0;
        var tagName  = (t && t.name) ? String(t.name) : '';
        var tagUrl   = (t && t.url)  ? String(t.url)  : '#';
        var tagCount = (t && t.count != null) ? t.count : 0;
        var isExact  = !!(t && t.exact);
        var isIncl   = F.isTagIncluded(tagId);
        var isExcl   = F.isTagExcluded(tagId);

        var cls = 'wssf-tag';
        if (isExact) cls += ' wssf-tag--exact';
        if (isIncl)  cls += ' wssf-tag--included';
        if (isExcl)  cls += ' wssf-tag--excluded';

        var searchLabel = C.i18n('searchTag', 'Искать записи с меткой «%s»').replace('%s', tagName);
        var incBase     = isIncl ? C.i18n('unincludeTag', 'Убрать фильтр по метке «%s»')
                                 : C.i18n('includeTag',   'Включить фильтр по метке «%s»');
        var excBase     = isExcl ? C.i18n('unexcludeTag', 'Убрать исключение метки «%s»')
                                 : C.i18n('excludeTag',   'Исключить записи с меткой «%s»');
        var incLabel    = String(incBase).replace('%s', tagName);
        var excLabel    = String(excBase).replace('%s', tagName);

        var html = '<span class="' + cls + '">';
        html +=     '<a class="wssf-tag-link" href="' + U.escapeHtml(tagUrl) + '">';
        html +=         '<span class="wssf-tag-name">' + U.escapeHtml(tagName) + '</span>';
        html +=         '<span class="wssf-tag-count">' + U.escapeHtml(tagCount) + '</span>';
        html +=     '</a>';
        html +=     '<button type="button" class="wssf-tag-search"';
        html +=         ' data-tag="' + U.escapeHtml(tagName) + '"';
        html +=         ' aria-label="' + U.escapeHtml(searchLabel) + '"';
        html +=         ' title="' + U.escapeHtml(searchLabel) + '">';
        html +=         '<svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true" focusable="false">';
        html +=             '<circle cx="10.5" cy="10.5" r="6.5" fill="none" stroke="currentColor" stroke-width="2"/>';
        html +=             '<line x1="15.5" y1="15.5" x2="20" y2="20" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>';
        html +=         '</svg>';
        html +=     '</button>';
        html +=     '<button type="button" class="wssf-tag-include"';
        html +=         ' data-tag-id="' + tagId + '" data-tag-name="' + U.escapeHtml(tagName) + '"';
        html +=         ' aria-pressed="' + (isIncl ? 'true' : 'false') + '"';
        html +=         ' aria-label="' + U.escapeHtml(incLabel) + '"';
        html +=         ' title="' + U.escapeHtml(incLabel) + '">';
        html +=         '<span aria-hidden="true">+</span>';
        html +=     '</button>';
        html +=     '<button type="button" class="wssf-tag-exclude"';
        html +=         ' data-tag-id="' + tagId + '" data-tag-name="' + U.escapeHtml(tagName) + '"';
        html +=         ' aria-pressed="' + (isExcl ? 'true' : 'false') + '"';
        html +=         ' aria-label="' + U.escapeHtml(excLabel) + '"';
        html +=         ' title="' + U.escapeHtml(excLabel) + '">';
        html +=         '<span aria-hidden="true">−</span>';
        html +=     '</button>';
        html += '</span>';

        return html;
    }

    function section(tags) {
        var count = (tags && tags.length) ? tags.length : 0;
        var html = '<div class="wssf-section">';
        html += '<h2 class="wssf-section-title">' + U.escapeHtml(C.i18n('tags', 'Метки')) +
            ' <span class="wssf-count">' + count + '</span></h2>';
        if (!tags || !tags.length) {
            html += '<div class="wssf-empty">' + U.escapeHtml(C.i18n('noTags', 'Метки не найдены')) + '</div>';
        } else {
            html += '<div class="wssf-tags">';
            tags.forEach(function (t) { html += tagChipHtml(t); });
            html += '</div>';
        }
        html += '</div>';
        return html;
    }

    return { tagChipHtml: tagChipHtml, section: section };
})(jQuery);