window.WSSF_Autocomplete = (function ($) {
    'use strict';

    var S = window.WSSF_State;
    var C = window.WSSF_Config;
    var U = window.WSSF_Utils;

    var $input    = null;
    var $wrap     = null;
    var $list     = null;
    var debounce  = null;
    var current   = [];
    var activeIdx = -1;
    var lastQuery = '';
    var xhr       = null;

    function isEnabled() {
        return !!(C.W && C.W.autocompleteEnabled);
    }

    function getMinLen() {
        var v = parseInt(C.W && C.W.autocompleteMinLen, 10);
        return isNaN(v) || v < 1 ? 2 : v;
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function buildList() {
        if ($list && $list.length) return;
        $list = $('<ul class="wssf-autocomplete" role="listbox" aria-label="Suggestions" hidden></ul>');
        $wrap.append($list);
    }

    function close() {
        if (!$list) return;
        $list.attr('hidden', 'hidden').empty();
        $input.attr('aria-expanded', 'false').removeAttr('aria-activedescendant');
        current = [];
        activeIdx = -1;
    }

    function setActive(idx) {
        if (!current.length) return;
        if (idx < 0) idx = current.length - 1;
        if (idx >= current.length) idx = 0;
        activeIdx = idx;

        $list.find('.wssf-ac-item').removeClass('is-active').attr('aria-selected', 'false');
        var $items = $list.find('.wssf-ac-item');
        var $el = $items.eq(idx);
        $el.addClass('is-active').attr('aria-selected', 'true');

        var elId = $el.attr('id');
        if (elId) $input.attr('aria-activedescendant', elId);

        // Ensure visible
        var el = $el.get(0);
        if (el && el.scrollIntoView) {
            try { el.scrollIntoView({ block: 'nearest' }); } catch (e) {}
        }
    }

    function render() {
        if (!$list) return;
        if (!current.length) { close(); return; }

        var html = '';
        current.forEach(function (item, i) {
            var cls = 'wssf-ac-item wssf-ac-type-' + escapeHtml(item.type || 'word');
            if (i === activeIdx) cls += ' is-active';
            var count = parseInt(item.count, 10) || 0;
            var aria = escapeHtml(item.term);

            html += '<li id="wssf-ac-item-' + i + '" class="' + cls + '" ' +
                'role="option" ' +
                'aria-selected="' + (i === activeIdx ? 'true' : 'false') + '" ' +
                'data-idx="' + i + '" data-value="' + escapeHtml(item.term) + '">' +
                '<span class="wssf-ac-icon" aria-hidden="true"></span>' +
                '<span class="wssf-ac-label">' + aria + '</span>' +
                (count > 0 ? '<span class="wssf-ac-count">' + count + '</span>' : '') +
                '</li>';
        });

        $list.html(html).removeAttr('hidden');
        $input.attr('aria-expanded', 'true');
    }

    function applySuggestion(value) {
        if (!value) return;
        $input.val(value);
        U.syncClearVisibility();
        close();
        if (window.WSSF_Search && typeof window.WSSF_Search.doSearch === 'function') {
            window.WSSF_Search.doSearch(true, value);
        }
        try { $input.trigger('focus'); } catch (e) {}
    }

    function fetch(q) {
        if (!isEnabled()) return;
        if (q.length < getMinLen()) { close(); return; }

        var ajaxUrl   = (C.W && C.W.ajaxUrl) ? C.W.ajaxUrl : '';
        var action    = (C.W && C.W.suggestAction) ? C.W.suggestAction : 'wssf_suggest';
        var nonce     = (C.W && C.W.nonce) ? C.W.nonce : '';
        if (!ajaxUrl) return;

        if (xhr) { try { xhr.abort(); } catch (e) {} }
        lastQuery = q;

        xhr = $.ajax({
            url:      ajaxUrl,
            type:     'POST',
            dataType: 'json',
            timeout:  5000,
            data: {
                action: action,
                nonce:  nonce,
                q:      q
            }
        }).done(function (res) {
            if (lastQuery !== q) return;
            if (!res || !res.success || !res.data || !Array.isArray(res.data.items)) {
                close();
                return;
            }
            current = res.data.items;
            activeIdx = -1;
            render();
        }).fail(function () {
            close();
        });
    }

    function scheduleFetch() {
        var q = $.trim($input.val() || '');
        if (debounce) clearTimeout(debounce);
        if (q.length < getMinLen()) { close(); return; }
        debounce = setTimeout(function () { fetch(q); }, 200);
    }

    function bind() {
        if (!isEnabled()) return;

        $input = $('#wssf-input');
        if (!$input.length) return;
        $wrap = $input.closest('.wssf-input-wrap');
        if (!$wrap.length) return;

        buildList();

        $input.attr({
            'role':              'combobox',
            'aria-autocomplete': 'list',
            'aria-expanded':     'false',
            'aria-controls':     'wssf-autocomplete-list',
            'autocomplete':      'off'
        });

        $input.on('input', scheduleFetch);
        $input.on('focus', function () {
            var q = $.trim($input.val() || '');
            if (q.length >= getMinLen() && current.length) {
                render();
            }
        });

        $input.on('keydown', function (e) {
            if (!$list || $list.attr('hidden') !== undefined) {
                if (e.key === 'ArrowDown' && current.length) { render(); setActive(0); e.preventDefault(); }
                return;
            }

            if (e.key === 'ArrowDown') {
                e.preventDefault();
                setActive(activeIdx + 1);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                setActive(activeIdx - 1);
            } else if (e.key === 'Enter') {
                if (activeIdx >= 0 && current[activeIdx]) {
                    e.preventDefault();
                    applySuggestion(current[activeIdx].term);
                } else {
                    close();
                }
            } else if (e.key === 'Escape') {
                close();
            } else if (e.key === 'Tab') {
                close();
            }
        });

        $input.on('blur', function () {
            setTimeout(close, 150);
        });

        $list.on('mousedown', '.wssf-ac-item', function (e) {
            e.preventDefault();
            var v = $(this).attr('data-value') || '';
            applySuggestion(v);
        });

        $list.on('mouseenter', '.wssf-ac-item', function () {
            var idx = parseInt($(this).attr('data-idx'), 10);
            if (!isNaN(idx)) setActive(idx);
        });

        $(document).on('click', function (e) {
            if (!$(e.target).closest('.wssf-input-wrap').length) close();
        });
    }

    $(function () {
        setTimeout(bind, 80);
    });

    return { bind: bind, close: close };
})(jQuery);