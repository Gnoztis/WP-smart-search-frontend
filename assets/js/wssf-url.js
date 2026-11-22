window.WSSF_URL = (function ($) {
    'use strict';

    var S = window.WSSF_State;
    var C = window.WSSF_Config;
    var U = window.WSSF_Utils;

    function updateUrl(query, cat, type) {
        if (!window.history || !window.history.replaceState) return;
        if (!C.W || !C.W.pageUrl) return;

        var url = C.W.pageUrl;
        var sep = url.indexOf('?') === -1 ? '?' : '&';
        var parts = [];

        if (query) parts.push('s=' + encodeURIComponent(query));
        if (cat) parts.push('cat=' + encodeURIComponent(cat));
        if (type) parts.push('pt=' + encodeURIComponent(type));
        if (S.activeTagIds.length) parts.push('tags=' + S.activeTagIds.join(','));
        if (S.activeExcludeTagIds.length) parts.push('xtags=' + S.activeExcludeTagIds.join(','));

        if (parts.length) url += sep + parts.join('&');

        try { window.history.replaceState({}, '', url); }
        catch (e) { U.debug('updateUrl error', e); }
    }

    function updateTitle(query) {
        if (!S.$titleQ.length) return;
        S.$titleQ.text(query || '');
        try {
            var tpl = C.i18n('titleTpl', 'Результаты поиска: %s');
            if (query) document.title = String(tpl).replace('%s', query);
        } catch (e) { /* ignore */ }
    }

    function detectInitialQuery() {
        if (C.W && C.W.initialQuery) {
            var fromServer = String(C.W.initialQuery).trim();
            if (fromServer) return fromServer;
        }
        try {
            if (window.URLSearchParams) {
                var params = new URLSearchParams(window.location.search);
                var fromUrl = (params.get('s') || params.get('q') || '').trim();
                if (fromUrl) return fromUrl;
            }
        } catch (e) { /* old browsers */ }
        return $.trim(S.$input.val() || '');
    }

    function syncSelects() {
        var initialCat  = (C.W && C.W.initialCat)  ? parseInt(C.W.initialCat, 10) : 0;
        var initialType = (C.W && C.W.initialType) ? String(C.W.initialType)     : '';

        if (S.$cat.length) {
            var currentCat = String(S.$cat.val() || '');
            var desiredCat = initialCat > 0 ? String(initialCat) : '';
            if (currentCat !== desiredCat) S.$cat.val(desiredCat);
        }
        if (S.$type.length) {
            var currentType = String(S.$type.val() || '');
            if (currentType !== initialType) S.$type.val(initialType);
        }
    }

    function syncInitialTags() {
        if (C.W && C.W.initialTags && C.W.initialTags.length) {
            C.W.initialTags.forEach(function (t) {
                var id = parseInt(t && t.id, 10);
                if (isNaN(id) || id <= 0) return;
                if (S.activeTagIds.indexOf(id) !== -1) return;
                S.activeTagIds.push(id);
                S.activeTagNames[id] = String((t && t.name) || '');
            });
        }
        if (C.W && C.W.initialExcludeTags && C.W.initialExcludeTags.length) {
            C.W.initialExcludeTags.forEach(function (t) {
                var id = parseInt(t && t.id, 10);
                if (isNaN(id) || id <= 0) return;
                if (S.activeExcludeTagIds.indexOf(id) !== -1) return;
                S.activeExcludeTagIds.push(id);
                S.activeExcludeTagNames[id] = String((t && t.name) || '');
            });
        }
    }

    return {
        updateUrl: updateUrl,
        updateTitle: updateTitle,
        detectInitialQuery: detectInitialQuery,
        syncSelects: syncSelects,
        syncInitialTags: syncInitialTags
    };
})(jQuery);