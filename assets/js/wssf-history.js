window.WSSF_History = (function ($) {
    'use strict';

    var S = window.WSSF_State;
    var C = window.WSSF_Config;
    var U = window.WSSF_Utils;

    var MAX = 10;
    var $dropdown = null;
    var isOpen = false;

    function getStorageKey() {
        var uid = (C.W && C.W.userId) ? C.W.userId : 'guest';
        return 'wssf_history_v1_' + uid;
    }

    function readLocal() {
        try {
            var raw = localStorage.getItem(getStorageKey());
            var arr = raw ? JSON.parse(raw) : [];
            return Array.isArray(arr) ? arr : [];
        } catch (e) { return []; }
    }

    function writeLocal(arr) {
        try { localStorage.setItem(getStorageKey(), JSON.stringify(arr.slice(0, MAX))); } catch (e) {}
    }

    function itemKey(item) {
        return [
            String(item.query || ''),
            String(item.cat || 0),
            String(item.type || ''),
            (item.tags || []).slice().sort(function (a, b) { return a - b; }).join(','),
            (item.xtags || []).slice().sort(function (a, b) { return a - b; }).join(',')
        ].join('|');
    }

    function normalizeNamesMap(map) {
        var out = {};
        if ( map && typeof map === 'object' ) {
            Object.keys(map).forEach(function (k) {
                var id = parseInt(k, 10);
                if ( isNaN(id) || id <= 0 ) return;
                var name = String(map[k] || '');
                if ( name ) { out[id] = name; }
            });
        }
        return out;
    }

    function normalize(item) {
        return {
            query:       String(item.query || '').trim(),
            cat:         parseInt(item.cat, 10) || 0,
            type:        String(item.type || ''),
            tags:        (item.tags || []).map(Number).filter(function (x) { return x > 0; }),
            xtags:       (item.xtags || []).map(Number).filter(function (x) { return x > 0; }),
            tags_names:  normalizeNamesMap(item.tags_names),
            xtags_names: normalizeNamesMap(item.xtags_names),
            time:        parseInt(item.time, 10) || Date.now()
        };
    }

    function pluralizeMarks(n) {
        var mod10  = n % 10;
        var mod100 = n % 100;
        if (mod10 === 1 && mod100 !== 11) { return 'метка'; }
        if (mod10 >= 2 && mod10 <= 4 && (mod100 < 10 || mod100 >= 20)) { return 'метки'; }
        return 'меток';
    }

    function saveRemote(arr) {
        if (!S.isLoggedIn || !C.W || !C.W.ajaxUrl) return;
        $.ajax({
            url: C.W.ajaxUrl, type: 'POST', dataType: 'json', timeout: 10000,
            data: {
                action: 'wssf_history',
                nonce:  C.W.nonce,
                op:     'save',
                items:  JSON.stringify(arr.slice(0, MAX))
            }
        });
    }

    function add(query, cat, type, tags, xtags, tagsNames, xtagsNames) {
        if (!C.W || !C.W.historyEnabled) return;

        var entry = normalize({
            query:       query,
            cat:         cat,
            type:        type,
            tags:        tags,
            xtags:       xtags,
            tags_names:  tagsNames,
            xtags_names: xtagsNames
        });

        if (!entry.query && !entry.tags.length && !entry.xtags.length && !entry.cat && !entry.type) {
            return;
        }

        var arr = readLocal();
        var k = itemKey(entry);
        arr = arr.filter(function (it) { return itemKey(it) !== k; });
        arr.unshift(entry);
        arr = arr.slice(0, MAX);
        writeLocal(arr);

        if (S.isLoggedIn) saveRemote(arr);
        render();
    }

    function fetchRemote(callback) {
        if (!C.W || !C.W.historyEnabled) {
            if (typeof callback === 'function') callback([]);
            return;
        }
        var cb = (typeof callback === 'function') ? callback : null;

        if (!S.isLoggedIn || !C.W || !C.W.ajaxUrl) {
            if (cb) { cb(readLocal()); }
            return;
        }

        $.ajax({
            url: C.W.ajaxUrl, type: 'POST', dataType: 'json', timeout: 10000,
            data: { action: 'wssf_history', nonce: C.W.nonce, op: 'fetch' }
        }).done(function (res) {
            var remote = [];
            if (res && res.success && res.data && Array.isArray(res.data.items)) {
                remote = res.data.items.map(normalize);
            }
            var local = readLocal();
            var seen = {};
            var out = [];
            remote.concat(local).forEach(function (it) {
                var k = itemKey(it);
                if (seen[k]) {
                    var existing = out.find(function (x) { return itemKey(x) === k; });
                    if (existing) {
                        if (!Object.keys(existing.tags_names).length && Object.keys(it.tags_names).length) {
                            existing.tags_names = it.tags_names;
                        }
                        if (!Object.keys(existing.xtags_names).length && Object.keys(it.xtags_names).length) {
                            existing.xtags_names = it.xtags_names;
                        }
                    }
                    return;
                }
                seen[k] = true;
                out.push(it);
            });
            out = out.slice(0, MAX);
            writeLocal(out);
            render();
            if (cb) { cb(out); }
        }).fail(function () {
            if (cb) { cb(readLocal()); }
        });
    }

    function clearAll() {
        try { localStorage.removeItem(getStorageKey()); } catch (e) {}
        if (S.isLoggedIn && C.W && C.W.ajaxUrl) {
            $.ajax({
                url: C.W.ajaxUrl, type: 'POST', dataType: 'json', timeout: 10000,
                data: { action: 'wssf_history', nonce: C.W.nonce, op: 'clear' }
            });
        }
        close();
    }

    function removeAt(idx) {
        var items = readLocal();
        if (idx < 0 || idx >= items.length) return;
        items.splice(idx, 1);
        writeLocal(items);
        if (S.isLoggedIn) saveRemote(items);
        render();
        if (!items.length) close();
    }

    function ensureDropdown() {
        if ($dropdown) return $dropdown;
        $dropdown = $('<div class="wssf-history" role="listbox" aria-label="История поиска" hidden></div>');
        var $anchor = S.$input.closest('.wssf-input-wrap');
        if ($anchor.length) { $anchor.append($dropdown); }
        else { S.$input.after($dropdown); }
        return $dropdown;
    }

    function render() {
        if (!C.W || !C.W.historyEnabled) {
            if ($dropdown) $dropdown.attr('hidden', 'hidden');
            return;
        }

        var $dd = ensureDropdown();
        var items = readLocal();
        if (!items.length) { $dd.empty().attr('hidden', 'hidden'); return $dd; }

        var title = C.i18n('historyTitle', 'История поиска');
        var clear = C.i18n('historyClear', 'Очистить');
        var html = '';
        html += '<div class="wssf-history-head">';
        html +=     '<span class="wssf-history-title">' + U.escapeHtml(title) + '</span>';
        html +=     '<button type="button" class="wssf-history-clear">' + U.escapeHtml(clear) + '</button>';
        html += '</div>';
        html += '<ul class="wssf-history-list">';

        items.forEach(function (it, i) {
            var q = it.query || '';
            var meta = [];

            var tagNames = [];
            (it.tags || []).forEach(function (id) {
                if (it.tags_names && it.tags_names[id]) tagNames.push(String(it.tags_names[id]));
            });
            if (tagNames.length) {
                var shown = tagNames.slice(0, 3);
                var more = tagNames.length - shown.length;
                meta.push('+' + shown.join(', +') + (more > 0 ? ' +' + more : ''));
            } else if ((it.tags || []).length) {
                meta.push('+' + (it.tags.length) + ' ' + pluralizeMarks(it.tags.length));
            }

            var xNames = [];
            (it.xtags || []).forEach(function (id) {
                if (it.xtags_names && it.xtags_names[id]) xNames.push(String(it.xtags_names[id]));
            });
            if (xNames.length) {
                meta.push('−' + xNames.slice(0, 3).join(', −') + (xNames.length > 3 ? ' −' + (xNames.length - 3) : ''));
            } else if ((it.xtags || []).length) {
                meta.push('−' + (it.xtags.length));
            }

            if (it.cat)  { meta.push('cat:' + it.cat); }
            if (it.type) { meta.push('type:' + it.type); }

            html += '<li class="wssf-history-item" data-idx="' + i + '" tabindex="0" role="option">';
            html +=     '<span class="wssf-history-q">' + (q ? U.escapeHtml(q) : '<i>фильтры</i>') + '</span>';
            if (meta.length) html += '<span class="wssf-history-meta">' + U.escapeHtml(meta.join(' · ')) + '</span>';
            html +=     '<button type="button" class="wssf-history-remove" data-idx="' + i + '" aria-label="Убрать">×</button>';
            html += '</li>';
        });

        html += '</ul>';
        $dd.removeAttr('hidden').html(html);
        return $dd;
    }

    function isInputEmpty() {
        return $.trim(S.$input.val() || '') === '';
    }

    function open() {
        if (!C.W || !C.W.historyEnabled) return;
        if (isOpen) return;
        if (!isInputEmpty()) return;
        render();
        if (!$dropdown || $dropdown.attr('hidden') !== undefined) return;
        var items = readLocal();
        if (!items.length) return;
        $dropdown.addClass('is-open').removeAttr('hidden');
        isOpen = true;
    }

    function close() {
        if (!isOpen) return;
        if ($dropdown) $dropdown.removeClass('is-open').attr('hidden', 'hidden');
        isOpen = false;
    }

    function pick(idx) {
        var items = readLocal();
        var it = items[idx];
        if (!it) return;
        close();
        if (window.WSSF_Search && typeof window.WSSF_Search.applyHistory === 'function') {
            window.WSSF_Search.applyHistory(it);
        }
    }

    function clearForLogout() {
        // Аудит №9: очищаем локальную историю после выхода.
        try { localStorage.removeItem(getStorageKey()); } catch (e) {}
        close();
    }

    function bind() {
        // Аудит №9: сервер ставит cookie wssf_logout=1 при wp_logout. Если она есть
        // — очищаем localStorage и саму cookie, чтобы не засорять.
        try {
            if (document.cookie.indexOf('wssf_logout=1') !== -1) {
                clearForLogout();
                document.cookie = 'wssf_logout=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
            }
        } catch (e) { /* ignore */ }

        if (!C.W || !C.W.historyEnabled) return;

        S.$input.on('focus', function () {
            if (isInputEmpty()) { open(); }
        });
        S.$input.on('click', function () {
            if (isInputEmpty()) { open(); }
        });

        S.$input.on('input', function () {
            if (isInputEmpty()) { open(); }
            else { close(); }
        });

        S.$input.on('keydown', function (e) {
            if (e.key === 'Escape' && isOpen) { close(); }
        });

        $(document).on('click', function (e) {
            if (!isOpen) return;
            var $t = $(e.target);
            if ($t.closest('.wssf-history').length) return;
            if ($t.is(S.$input) || $t.closest('.wssf-input-wrap').length) return;
            close();
        });

        $(document).on('click', '.wssf-history-item', function (e) {
            if ($(e.target).hasClass('wssf-history-remove')) return;
            pick(parseInt($(this).attr('data-idx'), 10));
        });
        $(document).on('keydown', '.wssf-history-item', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); pick(parseInt($(this).attr('data-idx'), 10)); }
        });
        $(document).on('click', '.wssf-history-remove', function (e) {
            e.stopPropagation();
            removeAt(parseInt($(this).attr('data-idx'), 10));
        });
        $(document).on('click', '.wssf-history-clear', function (e) {
            e.preventDefault();
            clearAll();
        });
    }

    return {
        init: bind,
        add: add,
        fetch: fetchRemote,
        open: open,
        close: close
    };
})(jQuery);