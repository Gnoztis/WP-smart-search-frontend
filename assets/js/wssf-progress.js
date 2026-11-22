window.WSSF_Progress = (function ($) {
    'use strict';

    var S = window.WSSF_State;

    function start() {
        clearTimeout(S.progressHideTid);
        S.progressHideTid = null;
        clearInterval(S.progressTimer);
        S.progressValue = 0;
        S.$progress.addClass('active');
        S.$bar.css('width', '0%');
        S.progressTimer = setInterval(function () {
            var step;
            if (S.progressValue < 30)      step = 8;
            else if (S.progressValue < 60) step = 3;
            else if (S.progressValue < 85) step = 1;
            else if (S.progressValue < 95) step = 0.4;
            else                           step = 0;
            S.progressValue = Math.min(95, S.progressValue + step);
            S.$bar.css('width', S.progressValue + '%');
        }, 180);
        S.$submit.prop('disabled', true);
    }

    function finish() {
        clearInterval(S.progressTimer);
        S.progressTimer = null;
        S.progressValue = 100;
        S.$bar.css('width', '100%');
        S.progressHideTid = setTimeout(function () {
            S.$progress.removeClass('active');
            S.$bar.css('width', '0%');
            S.progressValue = 0;
            S.progressHideTid = null;
        }, 450);
        S.$submit.prop('disabled', false);
    }

    function reset() {
        clearTimeout(S.progressHideTid);
        S.progressHideTid = null;
        clearInterval(S.progressTimer);
        S.progressTimer = null;
        S.progressValue = 0;
        S.$progress.removeClass('active');
        S.$bar.css('width', '0%');
        S.$submit.prop('disabled', false);
    }

    return { start: start, finish: finish, reset: reset };
})(jQuery);