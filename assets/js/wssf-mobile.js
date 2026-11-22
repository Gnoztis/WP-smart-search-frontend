/**
 * Мобильный UX:
 *  - сброс зума после ввода (iOS Safari оставляет scale, если тапнули в input)
 *  - скролл к input при фокусе, чтобы клавиатура не перекрывала результаты
 *  - swipe-down для закрытия off-canvas facets
 *  - обновление sticky-тени у формы
 */
window.WSSF_Mobile = (function ($) {
    'use strict';

    var S = window.WSSF_State;
    var C = window.WSSF_Config;

    var $form   = null;
    var $toggle = null;
    var $panel  = null;

    function isMobile() {
        return window.matchMedia && window.matchMedia('(max-width: 767.98px)').matches;
    }

    /* ---------- iOS: отключить residual-zoom ---------- */
    function preventZoom() {
        if (!isMobile()) return;
        var lastTouch = 0;
        $form.on('touchend', function (e) {
            var now = Date.now();
            if (now - lastTouch <= 300) { e.preventDefault(); }
            lastTouch = now;
        });
    }

    /* ---------- Скролл к input при фокусе ---------- */
    function focusScroll() {
        if (!isMobile()) return;
        S.$input.on('focus', function () {
            setTimeout(function () {
                var rect = S.$input[0].getBoundingClientRect();
                var vh   = window.innerHeight || document.documentElement.clientHeight;
                if (rect.top < 80 || rect.top > vh / 2) {
                    try {
                        S.$input[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
                    } catch (e) {
                        S.$input[0].scrollIntoView();
                    }
                }
            }, 300);
        });
    }

    /* ---------- Sticky-тень у формы при скролле ---------- */
    function stickyShadow() {
        if (!isMobile() || !$form || !$form.length) return;
        var ticking = false;
        function update() {
            var y = window.scrollY || 0;
            if (y > 8) { $form.addClass('wssf-form--shadow'); }
            else       { $form.removeClass('wssf-form--shadow'); }
            ticking = false;
        }
        $(window).on('scroll', function () {
            if (!ticking) {
                window.requestAnimationFrame(update);
                ticking = true;
            }
        });
        update();
    }

    /* ---------- Swipe-down для закрытия off-canvas ---------- */
    function swipeClosePanel() {
        if (!isMobile() || !$panel || !$panel.length) return;

        var startY = 0;
        var deltaY = 0;
        var tracking = false;
        var THRESHOLD = 80;

        $panel.on('touchstart', function (e) {
            if (e.originalEvent && e.originalEvent.touches && e.originalEvent.touches.length === 1) {
                startY = e.originalEvent.touches[0].clientY;
                deltaY = 0;
                tracking = true;
            }
        });

        $panel.on('touchmove', function (e) {
            if (!tracking || !e.originalEvent || !e.originalEvent.touches) return;
            deltaY = e.originalEvent.touches[0].clientY - startY;
            if (deltaY > 0) {
                $panel.css('transform', 'translateY(' + deltaY + 'px)');
            }
        });

        $panel.on('touchend', function () {
            tracking = false;
            if (deltaY > THRESHOLD) {
                if (window.WSSF_Facets_Panel && typeof window.WSSF_Facets_Panel.close === 'function') {
                    window.WSSF_Facets_Panel.close();
                }
            }
            $panel.css('transform', '');
            deltaY = 0;
        });
    }

    /* ---------- Корректный viewport при открытии клавиатуры ---------- */
    function viewportResize() {
        if (!isMobile()) return;
        var lastH = window.innerHeight;
        window.addEventListener('resize', function () {
            var h = window.innerHeight;
            if (Math.abs(h - lastH) < 150) {
                document.documentElement.style.setProperty('--wssf-vh', h + 'px');
            }
            lastH = h;
        });
    }

    function bind() {
        if (!isMobile()) return;

        $form   = $('.wssf-search-form');
        $toggle = $('#wssf-filters-toggle');
        $panel  = $('#wssf-facets-panel');

        if (!$form.length) return;

        preventZoom();
        focusScroll();
        stickyShadow();
        swipeClosePanel();
        viewportResize();
    }

    $(function () {
        if (!S.$input || !S.$input.length) return;
        setTimeout(bind, 100);
    });

    return { bind: bind };
})(jQuery);