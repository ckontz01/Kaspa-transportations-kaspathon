// assets/js/main.js
(function () {
    'use strict';

    function initNavbar() {
        var toggle = document.querySelector('.navbar-toggle');
        var linksWrapper = document.querySelector('.navbar .navbar-links');

        if (!toggle || !linksWrapper) {
            return;
        }

        toggle.addEventListener('click', function () {
            var isOpen = document.body.classList.toggle('nav-open');
            toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });

        // Close mobile nav when clicking a link
        linksWrapper.addEventListener('click', function (e) {
            var target = e.target;
            if (target && target.tagName === 'A' && document.body.classList.contains('nav-open')) {
                document.body.classList.remove('nav-open');
                toggle.setAttribute('aria-expanded', 'false');
            }
        });
    }

    function setActiveNavLink() {
        var links = document.querySelectorAll('.navbar-menu a[href]');
        if (!links.length) return;

        var currentPath = window.location.pathname;
        var bestMatch = null;
        var bestLen = 0;

        links.forEach(function (link) {
            var url;
            try {
                url = new URL(link.href, window.location.origin);
            } catch (e) {
                return;
            }
            var path = url.pathname;

            // Match exact or suffix (so /passenger/dashboard.php matches ./passenger/dashboard.php)
            if (currentPath === path || currentPath.endsWith(path)) {
                if (path.length > bestLen) {
                    bestLen = path.length;
                    bestMatch = link;
                }
            }
        });

        if (bestMatch) {
            bestMatch.classList.add('is-active');
        }
    }

    function initFlashes() {
        var flashes = document.querySelectorAll('.flash');
        if (!flashes.length) return;

        // Click to close
        document.body.addEventListener('click', function (e) {
            if (e.target.classList.contains('flash-close')) {
                var parent = e.target.closest('.flash');
                if (parent) {
                    parent.parentNode && parent.parentNode.removeChild(parent);
                }
            }
        });

        // Auto-hide after a delay
        flashes.forEach(function (flash) {
            setTimeout(function () {
                if (!document.body.contains(flash)) return;
                flash.classList.add('flash-hide');
                flash.addEventListener('transitionend', function () {
                    if (flash.parentNode) {
                        flash.parentNode.removeChild(flash);
                    }
                }, { once: true });
            }, 7000);
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initNavbar();
        setActiveNavLink();
        initFlashes();
    });
})();
