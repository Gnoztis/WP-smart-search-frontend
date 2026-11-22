(function ($) {
    'use strict';

    $(function () {
        var $btns = $('.wssf-reindex-btn');
        if (!$btns.length) { return; }

        var $wrap   = $('#wssf-reindex-progress');
        var $bar    = $('#wssf-reindex-bar');
        var $status = $('#wssf-reindex-status');

        var total         = 0;
        var completed     = 0;
        var running       = false;
        var aborted       = false;
        var currentMode   = 'all';
        var currentNonce  = '';
        var currentAjax   = '';
        var batchSize     = 200;
        var workersCount  = 3;
        var nextOffset    = 0;
        var activeWorkers = 0;
        var finishing     = false;

        function setBar(pct) {
            pct = Math.max(0, Math.min(100, pct));
            $bar.css('width', pct + '%');
        }

        function setStatus(text) {
            $status.text(text);
        }

        function disable(on) {
            $btns.prop('disabled', on);
            $btns.toggleClass('updating-message', on);
        }

        function finishSuccess() {
            if (finishing) { return; }
            finishing = true;
            running = false;
            disable(false);
            setBar(100);
            setStatus('Готово. Проиндексировано: ' + completed + '.');
            setTimeout(function () { window.location.reload(); }, 1200);
        }

        function finishError(msg) {
            if (finishing) { return; }
            finishing = true;
            running = false;
            aborted = true;
            disable(false);
            setStatus('Ошибка: ' + msg);
        }

        function tickProgress() {
            var pct = total > 0 ? Math.round(completed / total * 100) : 100;
            setBar(pct);
            setStatus('Обработано ' + completed + ' из ' + total + ' (' + pct + '%)');
        }

        function scheduleWorker() {
            if (aborted || finishing) { return; }
            if (nextOffset >= total) { return; }

            var thisOffset = nextOffset;
            nextOffset += batchSize;
            activeWorkers++;

            $.post(currentAjax, {
                action: 'wssf_reindex_batch',
                nonce:  currentNonce,
                mode:   currentMode,
                offset: thisOffset,
                limit:  batchSize
            }).done(function (res) {
                activeWorkers--;
                if (!res || !res.success) {
                    var msg = (res && res.data && res.data.message) ? res.data.message : 'неизвестная ошибка';
                    finishError(msg);
                    return;
                }
                var processed = parseInt(res.data.processed, 10) || 0;
                completed += processed;
                tickProgress();

                if (res.data.done) {
                    finishSuccess();
                    return;
                }

                // Ещё есть работа? — запускаем следующий батч.
                if (nextOffset < total) {
                    scheduleWorker();
                }

                // Все воркеры отработали и работы больше нет → успех.
                if (activeWorkers === 0 && nextOffset >= total) {
                    finishSuccess();
                }
            }).fail(function () {
                activeWorkers--;
                finishError('сеть недоступна на offset ' + thisOffset);
            });
        }

        function start(mode, nonce, ajaxUrl) {
            if (running) { return; }

            running    = true;
            aborted    = false;
            finishing  = false;
            currentMode  = mode;
            currentNonce = nonce;
            currentAjax  = ajaxUrl;
            completed    = 0;
            nextOffset   = 0;
            activeWorkers = 0;
            total        = 0;

            // mode=missing не поддерживает offset — только 1 воркер.
            workersCount = (mode === 'missing') ? 1 : 3;

            disable(true);
            $wrap.removeAttr('hidden');
            setBar(0);
            setStatus('Подготовка…');

            $.post(ajaxUrl, {
                action: 'wssf_reindex_start',
                nonce:  nonce,
                mode:   mode
            }).done(function (res) {
                if (!res || !res.success) {
                    finishError('не удалось получить total');
                    return;
                }
                total     = parseInt(res.data.total, 10) || 0;
                batchSize = parseInt(res.data.batch_size, 10) || 200;

                if (total === 0) {
                    running = false;
                    disable(false);
                    setBar(100);
                    setStatus(mode === 'missing'
                        ? 'Все записи уже проиндексированы.'
                        : 'Нечего индексировать.');
                    setTimeout(function () { window.location.reload(); }, 1200);
                    return;
                }

                setStatus('Всего: ' + total + ' · воркеров: ' + workersCount + ' · батч: ' + batchSize);
                for (var i = 0; i < workersCount; i++) {
                    scheduleWorker();
                }
            }).fail(function () {
                finishError('сеть недоступна при запуске');
            });
        }

        $btns.on('click', function () {
            var $btn = $(this);
            var mode = String($btn.data('mode') || 'all');
            var nonce = String($btn.data('nonce') || '');
            var ajaxUrl = String($btn.data('ajax-url') || '');
            if (!nonce || !ajaxUrl) { return; }
            start(mode, nonce, ajaxUrl);
        });
    });
})(jQuery);