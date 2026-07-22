// calendar-dates.js — mostra/esconde campos do formulário de calendar_dates
// conforme a categoria (e, para feriados, a abrangência) selecionada.
// Fora do HTML porque a CSP do painel é "script-src 'self'" (sem inline).
(function () {
    'use strict';

    function currentScope() {
        var scopeSelect = document.getElementById('cal-holiday-scope');
        return scopeSelect ? scopeSelect.value : '';
    }

    function applyVisibility() {
        var categorySelect = document.getElementById('cal-category');
        if (!categorySelect) return;
        var category = categorySelect.value;
        var scope = currentScope();

        document.querySelectorAll('[data-cal-field]').forEach(function (field) {
            var wantedCategory = field.getAttribute('data-cal-field');
            var wantedScopes = field.getAttribute('data-cal-scope');
            var visible = wantedCategory === category;
            if (visible && wantedScopes) {
                visible = wantedScopes.split(',').indexOf(scope) !== -1;
            }
            field.style.display = visible ? '' : 'none';
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var categorySelect = document.getElementById('cal-category');
        var scopeSelect = document.getElementById('cal-holiday-scope');
        if (!categorySelect) return;
        applyVisibility();
        categorySelect.addEventListener('change', applyVisibility);
        if (scopeSelect) scopeSelect.addEventListener('change', applyVisibility);
    });
})();
