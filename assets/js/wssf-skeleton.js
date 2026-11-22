window.WSSF_Skeleton = (function ($) {
    'use strict';

    function tags() {
        return '<div class="wssf-skeleton">' +
            '<div class="wssf-skeleton-title"></div>' +
            '<div class="wssf-skeleton-line lg"></div>' +
            '<div class="wssf-skeleton-line md"></div>' +
            '</div>';
    }

    function posts() {
        var html = '<div class="wssf-skeleton">';
        html += '<div class="wssf-skeleton-title"></div>';
        for (var i = 0; i < 3; i++) {
            html += '<div class="wssf-skeleton-card">' +
                '<div class="wssf-skeleton-line md"></div>' +
                '<div class="wssf-skeleton-line sm"></div>' +
                '</div>';
        }
        html += '</div>';
        return html;
    }

    return { tags: tags, posts: posts };
})(jQuery);