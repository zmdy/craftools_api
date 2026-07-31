// calendar-dates.js — mostra/esconde campos do formulário de calendar_dates
// conforme a categoria (e, para feriados, a abrangência) selecionada.
// Fora do HTML porque a CSP do painel é "script-src 'self'" (sem inline).
(function () {
    'use strict';

    function currentScope() {
        var scopeSelect = document.getElementById('cal-holiday-scope');
        return scopeSelect ? scopeSelect.value : '';
    }

    function currentDateType() {
        var dateTypeSelect = document.getElementById('cal-date-type');
        return dateTypeSelect ? dateTypeSelect.value : 'fixed';
    }

    function currentRuleType() {
        var ruleTypeSelect = document.getElementById('cal-rule-type');
        return ruleTypeSelect ? ruleTypeSelect.value : '';
    }

    function applyVisibility() {
        var categorySelect = document.getElementById('cal-category');
        if (!categorySelect) return;
        var category = categorySelect.value;
        var scope = currentScope();
        var dateType = currentDateType();
        var ruleType = currentRuleType();

        document.querySelectorAll('[data-cal-field]').forEach(function (field) {
            var wantedCategory = field.getAttribute('data-cal-field');
            var wantedScopes = field.getAttribute('data-cal-scope');
            var visible = wantedCategory === category;
            if (visible && wantedScopes) {
                visible = wantedScopes.split(',').indexOf(scope) !== -1;
            }
            field.style.display = visible ? '' : 'none';
        });

        // "Tipo de data" (fixa/móvel) -- controla o par Mês/Dia estático vs.
        // o bloco de configuração da regra. Os campos Mês/Dia continuam
        // presentes no DOM mesmo escondidos (só display:none), então o
        // formulário sempre envia valores válidos; calendarEntryRowFromInput()
        // no backend descarta esses valores em favor da regra quando ela
        // está presente.
        document.querySelectorAll('[data-cal-date-type]').forEach(function (field) {
            var wanted = field.getAttribute('data-cal-date-type');
            field.style.display = wanted === dateType ? '' : 'none';
        });

        // Sub-campos específicos de cada tipo de regra (nth_weekday precisa
        // de "ocorrência", os outros dois não; easter_offset não usa mês/dia
        // da semana).
        document.querySelectorAll('[data-cal-rule-type]').forEach(function (field) {
            var wantedTypes = (field.getAttribute('data-cal-rule-type') || '').split(',');
            var visible = dateType === 'rule' && wantedTypes.indexOf(ruleType) !== -1;
            field.style.display = visible ? '' : 'none';
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var categorySelect = document.getElementById('cal-category');
        var scopeSelect = document.getElementById('cal-holiday-scope');
        var dateTypeSelect = document.getElementById('cal-date-type');
        var ruleTypeSelect = document.getElementById('cal-rule-type');
        if (!categorySelect) return;
        applyVisibility();
        categorySelect.addEventListener('change', applyVisibility);
        if (scopeSelect) scopeSelect.addEventListener('change', applyVisibility);
        if (dateTypeSelect) dateTypeSelect.addEventListener('change', applyVisibility);
        if (ruleTypeSelect) ruleTypeSelect.addEventListener('change', applyVisibility);
    });
})();
