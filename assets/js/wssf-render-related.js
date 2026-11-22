window.WSSF_RenderRelated = (function ($) {
    'use strict';

    var C = window.WSSF_Config;
    var U = window.WSSF_Utils;

    var TYPE_LABELS = {
        cve:     'CVE',
        bdu:     'BDU',
        attck:   'ATT&CK',
        asn:     'ASN',
        ipv4:    'IP',
        ipv6:    'IPv6',
        url:     'URL',
        domain:  'Domain',
        sha256:  'SHA256',
        sha512:  'SHA512',
        sha1:    'SHA1',
        md5:     'MD5',
        yara:    'YARA',
        yaratag: 'YARA tag'
    };

    function typeLabel(t) {
        return TYPE_LABELS[t] || 'IOC';
    }

    function chipHtml(item) {
        var value = String(item.value || '');
        var type  = String(item.type || 'word');
        var cnt   = parseInt(item.cnt, 10) || 0;

        var label = typeLabel(type);
        var title = (C.i18n('searchByIoc', 'Искать по этому IOC')) + ': ' + value;

        var html = '<button type="button" class="wssf-related-chip wssf-related-type-' + U.escapeHtml(type) + '"';
        html +=     ' data-value="' + U.escapeHtml(value) + '"';
        html +=     ' title="' + U.escapeHtml(title) + '">';
        html +=     '<span class="wssf-related-type-badge">' + U.escapeHtml(label) + '</span>';
        html +=     '<span class="wssf-related-value">' + U.escapeHtml(value) + '</span>';
        if (cnt > 0) {
            html += '<span class="wssf-related-count">' + cnt + '</span>';
        }
        html += '</button>';
        return html;
    }

    function section(items) {
        if (!items || !items.length) return '';
        if (!(C.W && C.W.relatedEnabled !== false)) return '';

        var title = C.i18n('relatedTitle', 'Также упоминается в этой связи');
        var html = '<div class="wssf-related" role="region" aria-label="' + U.escapeHtml(title) + '">';
        html +=     '<div class="wssf-related-title">' + U.escapeHtml(title) + '</div>';
        html +=     '<div class="wssf-related-list">';
        items.forEach(function (item) { html += chipHtml(item); });
        html +=     '</div>';
        html += '</div>';
        return html;
    }

    return { section: section, chipHtml: chipHtml };
})(jQuery);