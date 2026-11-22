window.WSSF_Utils = (function ($) {
    'use strict';

    var S = window.WSSF_State;
    var C = window.WSSF_Config;

    function debug() {
        if (!C.W || !C.W.debug) return;
        if (!window.console || !console.log) return;
        var args = ['[WSSF]'].concat(Array.prototype.slice.call(arguments));
        console.log.apply(console, args);
    }

    function setStatus(msg) {
        if (!S.$status.length) return;
        var text = String(msg || '');
        if (S.$status.text() !== text) { S.$status.text(text); }
    }

    function setInputError(isError) {
        if (!S.$input.length) return;
        if (isError) {
            S.$input.addClass('wssf-input--error').attr('aria-invalid', 'true');
        } else {
            S.$input.removeClass('wssf-input--error').removeAttr('aria-invalid');
        }
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function getSelectedType() {
        if (!S.$type.length) return '';
        var v = S.$type.val();
        if (v === null || v === undefined) return '';
        v = String(v);
        if (v === '') return '';
        
        var allowed = (C.W && C.W.allowedPostTypes) ? C.W.allowedPostTypes : ['post', 'page'];
        return allowed.indexOf(v) !== -1 ? v : '';
    }

    function getSelectedCat() {
        if (!S.$cat.length) return 0;
        var v = parseInt(S.$cat.val(), 10);
        return (isNaN(v) || v < 0) ? 0 : v;
    }

    function syncClearVisibility() {
        if (!S.$clear.length) return;
        var hasText = $.trim(S.$input.val() || '') !== '';
        if (hasText) { S.$clear.removeAttr('hidden'); }
        else { S.$clear.attr('hidden', 'hidden'); }
    }

    function isMobileViewport() {
        return window.matchMedia && window.matchMedia('(max-width: 767.98px)').matches;
    }

    return {
        debug: debug,
        setStatus: setStatus,
        setInputError: setInputError,
        escapeHtml: escapeHtml,
        getSelectedType: getSelectedType,
        getSelectedCat: getSelectedCat,
        syncClearVisibility: syncClearVisibility,
        isMobileViewport: isMobileViewport
    };
})(jQuery);