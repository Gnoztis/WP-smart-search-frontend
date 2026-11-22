window.WSSF_RenderPosts = (function ($) {
    'use strict';

    var C = window.WSSF_Config;
    var U = window.WSSF_Utils;
    var F = window.WSSF_Filters;

    function categoriesHtml(cats) {
        if (!cats || !cats.length) return '';
        var html = '<div class="wssf-post-cats">';
        cats.forEach(function (c) {
            html += '<a class="wssf-post-cat" href="' + U.escapeHtml(c.url) + '">' +
                '<span class="wssf-post-cat-icon" aria-hidden="true">◆</span>' +
                U.escapeHtml(c.name) +
                '</a>';
        });
        html += '</div>';
        return html;
    }

    function postTagChipHtml(t) {
        var tagId    = parseInt(t && t.id, 10) || 0;
        var tagName  = (t && t.name) ? String(t.name) : '';
        var isIncl = F.isTagIncluded(tagId);
        var isExcl = F.isTagExcluded(tagId);

        var cls = 'wssf-post-tag';
        if (isIncl) cls += ' wssf-post-tag--included';
        if (isExcl) cls += ' wssf-post-tag--excluded';

        var incBase = isIncl ? C.i18n('unincludeTag', 'Убрать фильтр по метке «%s»')
                             : C.i18n('includeTag',   'Включить фильтр по метке «%s»');
        var excBase = isExcl ? C.i18n('unexcludeTag', 'Убрать исключение метки «%s»')
                             : C.i18n('excludeTag',   'Исключить записи с меткой «%s»');
        var incLabel = String(incBase).replace('%s', tagName);
        var excLabel = String(excBase).replace('%s', tagName);

        var html = '<span class="' + cls + '">';
        html +=     '<span class="wssf-post-tag-name">' + U.escapeHtml(tagName) + '</span>';
        html +=     '<button type="button" class="wssf-post-tag-include"';
        html +=         ' data-tag-id="' + tagId + '" data-tag-name="' + U.escapeHtml(tagName) + '"';
        html +=         ' aria-pressed="' + (isIncl ? 'true' : 'false') + '"';
        html +=         ' aria-label="' + U.escapeHtml(incLabel) + '"';
        html +=         ' title="' + U.escapeHtml(incLabel) + '">';
        html +=         '<span aria-hidden="true">+</span>';
        html +=     '</button>';
        html +=     '<button type="button" class="wssf-post-tag-exclude"';
        html +=         ' data-tag-id="' + tagId + '" data-tag-name="' + U.escapeHtml(tagName) + '"';
        html +=         ' aria-pressed="' + (isExcl ? 'true' : 'false') + '"';
        html +=         ' aria-label="' + U.escapeHtml(excLabel) + '"';
        html +=         ' title="' + U.escapeHtml(excLabel) + '">';
        html +=         '<span aria-hidden="true">−</span>';
        html +=     '</button>';
        html += '</span>';
        return html;
    }

    function postTagsHtml(tags) {
        if (!tags || !tags.length) return '';
        var limit = Math.min(tags.length, C.POST_TAGS_LIMIT);
        var html = '<div class="wssf-post-tags">';
        for (var i = 0; i < limit; i++) {
            html += postTagChipHtml(tags[i]);
        }
        if (tags.length > limit) {
            html += '<span class="wssf-post-tags-more" title="' +
                U.escapeHtml((tags.length - limit) + ' ещё') + '">+' +
                (tags.length - limit) + '</span>';
        }
        html += '</div>';
        return html;
    }

    function typeBadgeHtml(p) {
        var type      = (p && p.type) ? String(p.type) : '';
        var typeLabel = (p && p.type_label) ? String(p.type_label) : '';
        if (!typeLabel) return '';
        return '<span class="wssf-post-type wssf-post-type-' +
            U.escapeHtml(type) + '">' + U.escapeHtml(typeLabel) + '</span>';
    }

    function buildPageRange(current, total) {
        var out = [];
        var mobile = U.isMobileViewport();
        var maxAll = mobile ? 5 : 7;
        var win    = mobile ? 1 : 2;
        if (total <= maxAll) {
            for (var i = 1; i <= total; i++) out.push(i);
            return out;
        }
        out.push(1);
        var start = Math.max(2, current - win);
        var end   = Math.min(total - 1, current + win);
        if (start > 2) out.push(-1);
        for (var j = start; j <= end; j++) out.push(j);
        if (end < total - 1) out.push(-1);
        out.push(total);
        return out;
    }

    function pagination(current, total) {
        if (total <= 1) return '';
        var html = '<nav class="wssf-pagination" aria-label="' +
            U.escapeHtml(C.i18n('posts', 'Записи')) + '">';
        html += '<button type="button" class="wssf-page' + (current <= 1 ? ' disabled' : '') +
            '" data-page="' + (current - 1) + '" aria-label="' +
            U.escapeHtml(C.i18n('prevPage', 'Предыдущая страница')) + '">&larr;</button>';
        var range = buildPageRange(current, total);
        range.forEach(function (p) {
            if (p === -1) {
                html += '<span class="wssf-page-gap">…</span>';
            } else if (p === current) {
                html += '<span class="wssf-page active" aria-current="page">' + p + '</span>';
            } else {
                var lblTpl = C.i18n('pageLabel', 'Страница %d');
                html += '<button type="button" class="wssf-page" data-page="' + p +
                    '" aria-label="' + U.escapeHtml(String(lblTpl).replace('%d', p)) + '">' + p + '</button>';
            }
        });
        html += '<button type="button" class="wssf-page' + (current >= total ? ' disabled' : '') +
            '" data-page="' + (current + 1) + '" aria-label="' +
            U.escapeHtml(C.i18n('nextPage', 'Следующая страница')) + '">&rarr;</button>';
        html += '</nav>';
        return html;
    }

    function section(data) {
        var items = (data && data.items) || [];
        var total = (data && data.total) || 0;
        var page  = (data && data.page) || 1;
        var totalPages = (data && data.total_pages) || 1;

        var sectionTitle = C.i18n('postsAndPages', C.i18n('posts', 'Записи'));

        var html = '<div class="wssf-section">';
        html += '<h2 class="wssf-section-title">' + U.escapeHtml(sectionTitle) +
            ' <span class="wssf-count">' + U.escapeHtml(total) + '</span></h2>';

        if (!items.length) {
            html += '<div class="wssf-empty">' +
                U.escapeHtml(C.i18n('noPosts', 'Ничего не найдено')) + '</div>';
        } else {
            html += '<div class="wssf-posts">';
            items.forEach(function (p) {
                html += '<div class="wssf-post">';
                html += '<div class="wssf-post-title"><a href="' + U.escapeHtml(p.url) + '">' +
                    U.escapeHtml(p.title) + '</a></div>';
                if (p.excerpt) {
                    html += '<div class="wssf-post-excerpt">' + U.escapeHtml(p.excerpt) + '</div>';
                }
                html += '<div class="wssf-post-meta">';
                html += typeBadgeHtml(p);
                html += '<span>' + U.escapeHtml(p.date) + '</span>';
                html += '</div>';
                html += '<div class="wssf-post-taxonomy">';
                html += categoriesHtml(p.categories);
                html += postTagsHtml(p.tags);
                html += '</div>';
                html += '</div>';
            });
            html += '</div>';
            html += pagination(page, totalPages);
        }
        html += '</div>';
        return html;
    }

    return {
        section: section,
        pagination: pagination,
        postTagChipHtml: postTagChipHtml,
        categoriesHtml: categoriesHtml,
        postTagsHtml: postTagsHtml,
        typeBadgeHtml: typeBadgeHtml,
        buildPageRange: buildPageRange
    };
})(jQuery);