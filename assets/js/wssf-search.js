window.WSSF_Search = (function ($) {
    'use strict';

    var S   = window.WSSF_State;
    var C   = window.WSSF_Config;
    var U   = window.WSSF_Utils;
    var P   = window.WSSF_Progress;
    var Url = window.WSSF_URL;
    var F   = window.WSSF_Filters;
    var SK  = window.WSSF_Skeleton;
    var RT  = window.WSSF_RenderTags;
    var RP  = window.WSSF_RenderPosts;
    var RR  = window.WSSF_RenderRelated;
    var RS  = window.WSSF_RenderSimilar;
    var Api = window.WSSF_Api;

    function flagEnabled(name) { return !C.W || C.W[name] !== false; }

    function requestSignature(q, cat, type, incIds, excIds) {
        var inc = (incIds && incIds.length) ? incIds.slice().sort(function (a, b) { return a - b; }).join(',') : '';
        var exc = (excIds && excIds.length) ? excIds.slice().sort(function (a, b) { return a - b; }).join(',') : '';
        return String(q) + '|' + String(cat) + '|' + String(type) + '|i:' + inc + '|e:' + exc;
    }

    function hasActiveFilters(cat, type) {
        return S.activeTagIds.length > 0 || S.activeExcludeTagIds.length > 0 || (cat > 0) || (type !== '');
    }

    function clearDym() { if (S.$dym && S.$dym.length) { S.$dym.empty(); } }

    function resetSlots() {
        S.$results.html(
            '<div class="wssf-slot wssf-slot-tags">'  + SK.tags()  + '</div>' +
            '<div class="wssf-slot wssf-slot-posts">' + SK.posts() + '</div>'
        );
        clearDym();
    }

    function setTagsSlot(html) {
        var $slot = S.$results.find('.wssf-slot-tags');
        if ($slot.length) $slot.html(html);
        else S.$results.prepend('<div class="wssf-slot wssf-slot-tags">' + html + '</div>');
    }

    function setPostsSlot(html) {
        var $slot = S.$results.find('.wssf-slot-posts');
        if ($slot.length) $slot.html(html);
        else S.$results.append('<div class="wssf-slot wssf-slot-posts">' + html + '</div>');
    }

    function didYouMeanHtml(suggestion) {
        if (!suggestion || !suggestion.query) return '';
        var label = C.i18n('didYouMean', 'Возможно, вы имели в виду:');
        var aria  = C.i18n('didYouMeanAria', 'Искать «%s»');
        var q     = String(suggestion.query);
        var total = parseInt(suggestion.total, 10) || 0;
        var ariaLabel = String(aria).replace('%s', q);

        var html = '<div class="wssf-did-you-mean" role="note">';
        html +=     '<span class="wssf-did-you-mean-label">' + U.escapeHtml(label) + '</span> ';
        html +=     '<button type="button" class="wssf-did-you-mean-btn"';
        html +=         ' data-query="' + U.escapeHtml(q) + '"';
        html +=         ' aria-label="' + U.escapeHtml(ariaLabel) + '"';
        html +=         ' title="' + U.escapeHtml(ariaLabel) + '">';
        html +=         U.escapeHtml(q);
        html +=     '</button>';
        if (total > 0) { html += ' <span class="wssf-did-you-mean-count">(' + total + ')</span>'; }
        html += '</div>';
        return html;
    }

    function setDym(html) {
        if (!S.$dym || !S.$dym.length) return;
        if (!html) { S.$dym.empty(); return; }
        S.$dym.html(html);
    }

    function doSearch(force, qOverride) {
        var q;
        if (typeof qOverride === 'string') {
            q = $.trim(qOverride);
            if (S.$input.length && S.$input.val() !== q) {
                S.$input.val(q);
                U.syncClearVisibility();
            }
        } else {
            q = $.trim(S.$input.val());
        }

        var cat  = U.getSelectedCat();
        var type = U.getSelectedType();
        var filt = hasActiveFilters(cat, type);
        var sig  = requestSignature(q, cat, type, S.activeTagIds, S.activeExcludeTagIds);

        if (q === '' && !filt) {
            if (S.xhrMain) { try { S.xhrMain.abort(); } catch (e) {} S.xhrMain = null; }
            S.inflightSig = ''; S.pendingCount = 0; S.anyError = false;
            P.reset(); U.setStatus(C.EMPTY_MSG); U.setInputError(false);
            S.$results.empty(); clearDym();
            Url.updateUrl('', '', ''); Url.updateTitle('');
            S.lastQuery = ''; S.lastCat = cat; S.lastType = type; S.lastSig = '';
            return;
        }

        if (q !== '' && q.length < C.MIN_LEN && !filt) {
            if (S.xhrMain) { try { S.xhrMain.abort(); } catch (e) {} S.xhrMain = null; }
            S.inflightSig = ''; S.pendingCount = 0; S.anyError = false;
            P.reset(); U.setStatus(C.MIN_LEN_MSG); U.setInputError(true);
            S.$results.empty(); clearDym();
            S.lastSig = ''; S.lastQuery = '';
            return;
        }

        U.setInputError(false);
        if (!force && sig === S.lastSig) return;
        if (sig === S.inflightSig) return;

        S.lastQuery = q; S.lastCat = cat; S.lastType = type;
        S.lastSig = sig; S.inflightSig = sig;

        Url.updateUrl(q, cat, type);
        Url.updateTitle(q);

        if (S.xhrMain) { try { S.xhrMain.abort(); } catch (e) {} S.xhrMain = null; }

        S.pendingCount = 1; S.anyError = false;

        U.setStatus(C.i18n('searching', 'Поиск…'));
        resetSlots();
        P.start();

        S.xhrMain = Api.start('all', null, function (data) {
            if (data.tags !== undefined) { setTagsSlot(RT.section(data.tags)); }

            var postsTotal = (data.posts && data.posts.total) ? parseInt(data.posts.total, 10) : 0;
            var postsHtml  = (data.posts !== undefined) ? RP.section(data.posts) : '';

            // Related IOC (co-occurrence)
            var relatedHtml = '';
            if (data.posts && data.posts.related_iocs && data.posts.related_iocs.length
                && RR && typeof RR.section === 'function') {
                relatedHtml = RR.section(data.posts.related_iocs);
            }

            // 5.2.0: Similar posts (MinHash near-duplicate)
            var similarHtml = '';
            if (data.posts && data.posts.similar_posts && data.posts.similar_posts.length
                && RS && typeof RS.section === 'function') {
                similarHtml = RS.section(data.posts.similar_posts);
            }

            if (flagEnabled('didYouMeanEnabled')) { setDym(didYouMeanHtml(data.suggestion)); }
            else { setDym(''); }

            if (postsTotal === 0) {
                var sug = flagEnabled('suggestionsEnabled')
                    ? window.WSSF_Suggestions.render(S.lastQuery, S.lastCat, S.lastType, S.activeTagIds, S.activeExcludeTagIds)
                    : '';
                setPostsSlot(postsHtml + sug);
            } else {
                S.suggestions = [];
                setPostsSlot(postsHtml + relatedHtml + similarHtml);
            }

            try {
                if (window.WSSF_History && flagEnabled('historyEnabled')) {
                    window.WSSF_History.add(
                        S.lastQuery, S.lastCat, S.lastType,
                        S.activeTagIds, S.activeExcludeTagIds,
                        S.activeTagNames, S.activeExcludeTagNames
                    );
                }
            } catch (e) {
                if (window.console && console.error) console.error('[WSSF] history add error:', e);
            }
        });
    }

    function loadPostsPage(page) {
        if (!S.lastQuery && !hasActiveFilters(S.lastCat, S.lastType)) return;
        if (S.xhrMain) { try { S.xhrMain.abort(); } catch (e) {} S.xhrMain = null; }
        S.inflightSig = ''; S.pendingCount = 1; S.anyError = false;
        setPostsSlot(SK.posts());
        P.start();
        S.xhrMain = Api.start('posts', page, function (data) {
            setPostsSlot(RP.section(data.posts));
        });
    }

    function runTagSearch(tag) {
        tag = String(tag || '').trim();
        if (!tag) return;
        S.$input.val(tag);
        U.syncClearVisibility();
        if (S.searchDebounceTimer) { clearTimeout(S.searchDebounceTimer); S.searchDebounceTimer = null; }
        doSearch(true);
        var $target = S.$results.length ? S.$results : S.$wrap;
        if ($target.length) { $('html, body').animate({ scrollTop: Math.max(0, $target.offset().top - 40) }, 200); }
    }

    function applyHistory(item) {
        if (!item) return;
        S.$input.val(String(item.query || ''));
        U.syncClearVisibility();
        if (S.$cat.length)  { S.$cat.val(item.cat ? String(item.cat) : ''); }
        if (S.$type.length) { S.$type.val(item.type || ''); }
        S.activeTagIds = []; S.activeTagNames = {};
        S.activeExcludeTagIds = []; S.activeExcludeTagNames = {};
        var tagNames  = (item.tags_names  && typeof item.tags_names  === 'object') ? item.tags_names  : {};
        var xtagNames = (item.xtags_names && typeof item.xtags_names === 'object') ? item.xtags_names : {};
        (item.tags || []).forEach(function (id) {
            var n = parseInt(id, 10); if (n <= 0) return;
            S.activeTagIds.push(n);
            var name = tagNames[n] || tagNames[String(n)] || '';
            if (name) { S.activeTagNames[n] = String(name); }
        });
        (item.xtags || []).forEach(function (id) {
            var n = parseInt(id, 10); if (n <= 0) return;
            S.activeExcludeTagIds.push(n);
            var name = xtagNames[n] || xtagNames[String(n)] || '';
            if (name) { S.activeExcludeTagNames[n] = String(name); }
        });
        window.WSSF_Filters.renderActiveFilters();
        doSearch(true);
    }

    function initSearch() {
        try { Url.syncSelects(); } catch (e) {}
        try { U.syncClearVisibility(); } catch (e) {}
        try { Url.syncInitialTags(); } catch (e) {}
        try { F.renderActiveFilters(); } catch (e) {}

        try {
            if (window.WSSF_History && flagEnabled('historyEnabled') && typeof window.WSSF_History.fetch === 'function') {
                window.WSSF_History.fetch();
            }
        } catch (e) {}

        var initialQ = '';
        try { initialQ = Url.detectInitialQuery(); } catch (e) {}

        if (initialQ && S.$input.length && S.$input.val() !== initialQ) {
            S.$input.val(initialQ);
            U.syncClearVisibility();
        }

        S.lastCat = U.getSelectedCat();
        S.lastType = U.getSelectedType();
        S.lastQuery = initialQ;

        var filt = hasActiveFilters(S.lastCat, S.lastType);
        var minLen = C.MIN_LEN;
        var shouldRun = (initialQ.length >= minLen) || (initialQ === '' && filt) || (initialQ.length > 0 && filt);

        if (shouldRun) {
            U.setInputError(false);
            if (initialQ) Url.updateTitle(initialQ);
            resetSlots(); P.start();
            U.setStatus(C.i18n('searching', 'Поиск…'));
            if (window.requestAnimationFrame) { requestAnimationFrame(function () { doSearch(true, initialQ); }); }
            else { setTimeout(function () { doSearch(true, initialQ); }, 0); }
        } else if (initialQ.length > 0 && initialQ.length < minLen) {
            U.setInputError(true);
            if ($.trim(S.$status.text()) === '') U.setStatus(C.MIN_LEN_MSG);
            S.$results.empty(); clearDym();
            S.lastQuery = '';
        } else {
            U.setInputError(false);
            Url.updateUrl('', '', '');
            Url.updateTitle('');
        }
    }

    return {
        requestSignature: requestSignature,
        doSearch: doSearch,
        loadPostsPage: loadPostsPage,
        runTagSearch: runTagSearch,
        applyHistory: applyHistory,
        initSearch: initSearch
    };
})(jQuery);