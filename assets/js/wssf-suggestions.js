window.WSSF_Suggestions = (function ($) {
    'use strict';

    var S = window.WSSF_State;
    var C = window.WSSF_Config;
    var U = window.WSSF_Utils;

    function build(query, cat, type, tags, xtags) {
        var out = [];
        var q = String(query || '').trim();
        var words = q ? q.split(/\s+/).filter(Boolean) : [];

        if (xtags && xtags.length) {
            out.push({
                label: C.i18n('suggestionRemoveExclude', 'Убрать исключения по меткам') + ' (' + xtags.length + ')',
                apply: function () {
                    S.activeExcludeTagIds = [];
                    S.activeExcludeTagNames = {};
                    window.WSSF_Filters.renderActiveFilters();
                    window.WSSF_Search.doSearch(true);
                }
            });
        }

        if (tags && tags.length) {
            out.push({
                label: C.i18n('suggestionRemoveInclude', 'Убрать фильтры по меткам') + ' (' + tags.length + ')',
                apply: function () {
                    S.activeTagIds = [];
                    S.activeTagNames = {};
                    window.WSSF_Filters.renderActiveFilters();
                    window.WSSF_Search.doSearch(true);
                }
            });
        }

        if (cat) {
            out.push({
                label: C.i18n('suggestionRemoveCat', 'Искать во всех категориях'),
                apply: function () {
                    if (S.$cat.length) S.$cat.val('');
                    window.WSSF_Search.doSearch(true);
                }
            });
        }

        if (type) {
            out.push({
                label: C.i18n('suggestionRemoveType', 'Искать все типы'),
                apply: function () {
                    if (S.$type.length) S.$type.val('');
                    window.WSSF_Search.doSearch(true);
                }
            });
        }

        if (words.length >= 2) {
            var longest = words.slice().sort(function (a, b) { return b.length - a.length; })[0];
            var without = words.filter(function (w) { return w !== longest; }).join(' ');
            if (without) {
                out.push({
                    label: C.i18n('suggestionRemoveLongest', 'Убрать самое длинное слово') + ' «' + longest + '»',
                    apply: function () {
                        S.$input.val(without);
                        window.WSSF_Search.doSearch(true);
                    }
                });
            }
            // Аудит №10: кнопка OR формирует запрос с OR между ВСЕМИ словами,
            // а не только по первому. Парсер поймёт "слово1 OR слово2 OR …".
            out.push({
                label: C.i18n('suggestionOrMode', 'Искать любое из слов (OR)'),
                apply: function () {
                    var orQuery = words.join(' OR ');
                    S.$input.val(orQuery);
                    window.WSSF_Search.doSearch(true);
                }
            });
        }

        return out;
    }

    function render(query, cat, type, tags, xtags) {
        var items = build(query, cat, type, tags, xtags);
        if (!items.length) {
            S.suggestions = [];
            return '';
        }
        S.suggestions = items;

        var title = C.i18n('suggestionsTitle', 'Ничего не найдено. Попробуйте:');
        var html = '<div class="wssf-suggestions" role="region">';
        html += '<div class="wssf-suggestions-title">' + U.escapeHtml(title) + '</div>';
        html += '<ul class="wssf-suggestions-list">';
        items.forEach(function (it, i) {
            html += '<li><button type="button" class="wssf-suggestion" data-idx="' + i + '">' +
                U.escapeHtml(it.label) + '</button></li>';
        });
        html += '</ul></div>';
        return html;
    }

    return { render: render, build: build };
})(jQuery);