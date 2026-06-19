// admin.js — interações do painel (sem dependências externas)
(function () {
    'use strict';

    var THEME_KEY = 'craftools_api_theme';

    function applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        var icon = document.getElementById('theme-toggle-icon');
        if (icon) {
            icon.textContent = theme === 'dark' ? 'light_mode' : 'dark_mode';
        }
    }

    function initTheme() {
        var saved = localStorage.getItem(THEME_KEY) || 'light';
        applyTheme(saved);
    }

    function toggleTheme() {
        var current = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
        var next = current === 'dark' ? 'light' : 'dark';
        localStorage.setItem(THEME_KEY, next);
        applyTheme(next);
    }

    function initSidenavToggle() {
        var btn = document.getElementById('sidenav-toggle');
        var panel = document.getElementById('sidenav-panel');
        if (!btn || !panel) return;
        btn.addEventListener('click', function () {
            panel.classList.toggle('panel-open');
        });
        document.addEventListener('click', function (ev) {
            if (window.innerWidth > 880) return;
            if (panel.classList.contains('panel-open') && !panel.contains(ev.target) && ev.target !== btn && !btn.contains(ev.target)) {
                panel.classList.remove('panel-open');
            }
        });
    }

    function initConfirmForms() {
        document.querySelectorAll('form[data-confirm]').forEach(function (form) {
            form.addEventListener('submit', function (ev) {
                var msg = form.getAttribute('data-confirm') || 'Confirma esta ação?';
                if (!window.confirm(msg)) {
                    ev.preventDefault();
                }
            });
        });
    }

    function initFlashAutoHide() {
        document.querySelectorAll('.flash[data-autohide]').forEach(function (el) {
            setTimeout(function () {
                el.style.transition = 'opacity .4s';
                el.style.opacity = '0';
                setTimeout(function () { el.remove(); }, 400);
            }, 5000);
        });
    }

    function initCopyButtons() {
        document.querySelectorAll('[data-copy]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var target = document.querySelector(btn.getAttribute('data-copy'));
                if (!target) return;
                var text = target.innerText || target.value || '';
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(text);
                } else {
                    var ta = document.createElement('textarea');
                    ta.value = text;
                    document.body.appendChild(ta);
                    ta.select();
                    document.execCommand('copy');
                    ta.remove();
                }
                var original = btn.textContent;
                btn.textContent = 'Copiado!';
                setTimeout(function () { btn.textContent = original; }, 1500);
            });
        });
    }

    // Selects com data-autosubmit enviam o formulário ao mudar — usado nos
    // filtros (ex.: categoria de frases). Mantido fora do HTML (em vez de um
    // atributo onchange inline) para funcionar sob a CSP "script-src 'self'".
    function initAutoSubmitSelects() {
        document.querySelectorAll('select[data-autosubmit]').forEach(function (el) {
            el.addEventListener('change', function () {
                el.form && el.form.submit();
            });
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initTheme();
        initSidenavToggle();
        initConfirmForms();
        initFlashAutoHide();
        initCopyButtons();
        initAutoSubmitSelects();
        var themeBtn = document.getElementById('theme-toggle');
        if (themeBtn) themeBtn.addEventListener('click', toggleTheme);
    });
})();
