window.WSSF_Api = (function ($) {
    'use strict';

    var S = window.WSSF_State;
    var C = window.WSSF_Config;
    var U = window.WSSF_Utils;
    var P = window.WSSF_Progress;

    function done() {
        S.pendingCount = Math.max(0, S.pendingCount - 1);
        if (S.pendingCount > 0) return;
        S.inflightSig = '';
        if (S.anyError) {
            if (!S.$status.text()) U.setStatus(C.i18n('error', 'Ошибка поиска'));
            P.finish();
        } else {
            U.setStatus('');
            P.finish();
        }
    }

    /**
     * Ключ CleanTalk.
     *
     * Берём сразу из window.ctPublic (inline-скрипт CleanTalk в head),
     * не дожидаясь setTimeout(1000), который заменяет <input value="0">.
     * Иначе на медленных устройствах и в кешированных страницах AJAX
     * уходит с ключом "0" → CleanTalk блокирует как бота.
     */
    function getCleanTalkKey() {
        if (window.ctPublic && window.ctPublic.ct_checkjs_key) {
            return String(window.ctPublic.ct_checkjs_key);
        }
        var val = $('input[name="ct_checkjs_search_default"]').first().val() || '';
        if (val === '0' || val === '') return '';
        return String(val);
    }

    function start(ajaxType, page, onSuccess) {
        var ctCheckjs = getCleanTalkKey();

        var data = {
            action:    (C.W && C.W.action) ? C.W.action : 'wssf_search',
            nonce:     (C.W && C.W.nonce)  ? C.W.nonce  : '',
            query:     S.lastQuery,
            type:      ajaxType,
            cat:       S.lastCat,
            post_type: S.lastType
        };
        if (ctCheckjs !== '') {
            data.ct_checkjs_search_default = ctCheckjs;
        }
        if (S.activeTagIds.length) data.tag_ids = S.activeTagIds.slice();
        if (S.activeExcludeTagIds.length) data.exclude_tag_ids = S.activeExcludeTagIds.slice();
        if (ajaxType === 'posts' && page) data.page = page;

        U.debug('request', data);

        return $.ajax({
            url:      (C.W && C.W.ajaxUrl) ? C.W.ajaxUrl : '',
            type:     'POST',
            dataType: 'json',
            data:     data,
            timeout:  30000
        }).done(function (res) {
            if (res && res.success && res.data) {
                try { onSuccess(res.data); }
                catch (e) {
                    S.anyError = true;
                    if (window.console && console.error) console.error('[WSSF] render error:', e);
                }
            } else {
                S.anyError = true;
                var msg = (res && res.data && res.data.message) || C.i18n('error', 'Ошибка поиска');
                U.setStatus(msg);
            }
        }).fail(function (jqXHR, textStatus, errorThrown) {
            if (textStatus === 'abort') return;
            S.anyError = true;
            var code = jqXHR ? jqXHR.status : 0;
            var msg  = C.i18n('error', 'Ошибка поиска');
            if (code === 0)        msg = C.i18n('errorNetwork', msg);
            else if (code === 400) msg = C.i18n('error400',     msg);
            else if (code === 403) msg = C.i18n('error403',     msg);
            else if (code === 429) msg = C.i18n('error429',     msg);
            else if (code >= 500)  msg = C.i18n('error500',     msg);
            U.setStatus(msg);
            try {
                if (jqXHR && jqXHR.responseText) {
                    var parsed = JSON.parse(jqXHR.responseText);
                    if (parsed && parsed.data && parsed.data.message) U.setStatus(parsed.data.message);
                }
            } catch (e) { /* not JSON */ }
        }).always(function (jqXHR, textStatus) {
            if (textStatus === 'abort') { S.inflightSig = ''; return; }
            done();
        });
    }

    return { start: start };
})(jQuery);