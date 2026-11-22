window.WSSF_RenderSimilar = (function ($) {
    'use strict';

    var C = window.WSSF_Config;
    var U = window.WSSF_Utils;

    function section(items) {
        if (!items || !items.length) return '';
        if (!(C.W && C.W.minhashEnabled !== false)) return '';

        var title = C.i18n('similarTitle', 'Похожие записи');
        var html = '<div class="wssf-similar" role="region">';
        html +=     '<div class="wssf-similar-title">' + U.escapeHtml(title) + '</div>';
        html +=     '<ul class="wssf-similar-list">';
        items.forEach(function (it) {
            var sim = Math.round((parseFloat(it.similarity) || 0) * 100);
            html += '<li>';
            html +=     '<a class="wssf-similar-item" href="' + U.escapeHtml(it.url) + '">';
            html +=         '<span class="wssf-similar-name">' + U.escapeHtml(it.title) + '</span>';
            html +=         '<span class="wssf-similar-badge">' + sim + '%</span>';
            html +=     '</a>';
            html += '</li>';
        });
        html +=     '</ul>';
        html += '</div>';
        return html;
    }

    return { section: section };
})(jQuery);