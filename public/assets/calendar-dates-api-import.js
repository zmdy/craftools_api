// calendar-dates-api-import.js — dispara o importador de dados de exemplo
// (comemoracoes/eventos/santos de apicdata.biduinfo.com.br), 1 data por vez,
// contra calendar_dates_api_import_ajax.php. Ver views/calendar_dates_api_import.php.
(function () {
    const app = document.getElementById('cal-api-import-app');
    if (!app) return;

    const startMonth   = document.getElementById('cai-start-month');
    const startDay      = document.getElementById('cai-start-day');
    const endMonth      = document.getElementById('cai-end-month');
    const endDay         = document.getElementById('cai-end-day');
    const wholeYearBtn  = document.getElementById('cai-whole-year');
    const rangeSummary  = document.getElementById('cai-range-summary');
    const startBtn      = document.getElementById('cai-start');
    const stopBtn        = document.getElementById('cai-stop');
    const progressWrap  = document.getElementById('cai-progress');
    const progressFill  = document.getElementById('cai-progress-fill');
    const progressLbl   = document.getElementById('cai-progress-label');
    const progressCnt   = document.getElementById('cai-progress-counts');
    const doneBox        = document.getElementById('cai-done');
    const doneMsg         = document.getElementById('cai-done-msg');
    const errorLog        = document.getElementById('cai-error-log');

    const CSRF = app.dataset.csrf;
    const MONTH_NAMES = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];

    let stopRequested = false;

    // Gera a sequência de {month, day} entre início e fim (ano de referência
    // não bissexto -- 2023 -- só para poder usar aritmética de Date; o ano em
    // si é descartado, só month/day importam). Se fim < início, entende como
    // um intervalo que atravessa a virada do ano (ex.: 15/12 -> 15/01).
    function buildDateRange(sm, sd, em, ed) {
        var YEAR = 2023;
        var start = new Date(YEAR, sm - 1, sd);
        var end   = new Date(YEAR, em - 1, ed);
        var dates = [];

        function pushRange(from, to) {
            var cursor = new Date(from);
            while (cursor <= to) {
                dates.push({ month: cursor.getMonth() + 1, day: cursor.getDate() });
                cursor.setDate(cursor.getDate() + 1);
            }
        }

        if (end < start) {
            pushRange(start, new Date(YEAR, 11, 31));
            pushRange(new Date(YEAR, 0, 1), end);
        } else {
            pushRange(start, end);
        }
        return dates;
    }

    function currentRange() {
        return buildDateRange(
            parseInt(startMonth.value, 10), parseInt(startDay.value, 10),
            parseInt(endMonth.value, 10), parseInt(endDay.value, 10)
        );
    }

    function updateSummary() {
        var range = currentRange();
        rangeSummary.textContent = range.length + ' data(s) serão consultadas (até ' + (range.length * 3) + ' chamadas à API externa).';
    }

    [startMonth, startDay, endMonth, endDay].forEach(function (el) {
        el.addEventListener('change', updateSummary);
        el.addEventListener('input', updateSummary);
    });
    updateSummary();

    wholeYearBtn.addEventListener('click', function () {
        startMonth.value = '1';
        startDay.value = '1';
        endMonth.value = '12';
        endDay.value = '31';
        updateSummary();
    });

    stopBtn.addEventListener('click', function () {
        stopRequested = true;
        stopBtn.disabled = true;
        stopBtn.textContent = 'Parando…';
    });

    startBtn.addEventListener('click', function () {
        var dates = currentRange();
        if (!dates.length) return;

        stopRequested = false;
        startBtn.disabled = true;
        stopBtn.hidden = false;
        stopBtn.disabled = false;
        stopBtn.textContent = 'Parar';
        progressWrap.hidden = false;
        doneBox.hidden = true;
        errorLog.innerHTML = '';

        var total = dates.length;
        var done = 0;
        var totalsByCategory = { commemoration: 0, event: 0, saint: 0 };
        var errLines = [];
        var idx = 0;

        function processNext() {
            if (stopRequested || idx >= total) {
                progressWrap.hidden = true;
                startBtn.disabled = false;
                stopBtn.hidden = true;
                doneBox.hidden = false;
                var grandTotal = totalsByCategory.commemoration + totalsByCategory.event + totalsByCategory.saint;
                doneMsg.textContent = (stopRequested ? 'Importação interrompida. ' : 'Importação concluída. ') +
                    grandTotal + ' item(ns) gravado(s) — ' +
                    totalsByCategory.commemoration + ' comemoração(ões), ' +
                    totalsByCategory.event + ' evento(s), ' +
                    totalsByCategory.saint + ' santo(s).';

                if (errLines.length) {
                    var ul = document.createElement('ul');
                    ul.style.cssText = 'font-size:12.5px; color:#b91c1c; margin-top:10px; padding-left:18px;';
                    errLines.slice(0, 50).forEach(function (msg) {
                        var li = document.createElement('li');
                        li.textContent = msg;
                        ul.appendChild(li);
                    });
                    errorLog.appendChild(ul);
                }
                return;
            }

            var d = dates[idx];
            idx++;

            var body = new FormData();
            body.append('_csrf', CSRF);
            body.append('op', 'process');
            body.append('month', d.month);
            body.append('day', d.day);

            fetch('calendar_dates_api_import_ajax.php', { method: 'POST', body: body })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data.status !== 'success') throw new Error(data.message || 'Erro desconhecido');
                    var counts = data.data.counts || {};
                    totalsByCategory.commemoration += counts.commemoration || 0;
                    totalsByCategory.event += counts.event || 0;
                    totalsByCategory.saint += counts.saint || 0;
                    (data.data.errors || []).forEach(function (msg) {
                        errLines.push(String(d.day).padStart(2, '0') + '/' + String(d.month).padStart(2, '0') + ': ' + msg);
                    });
                })
                .catch(function (err) {
                    errLines.push(String(d.day).padStart(2, '0') + '/' + String(d.month).padStart(2, '0') + ': ' + err.message);
                })
                .finally(function () {
                    done++;
                    var pct = Math.round((done / total) * 100);
                    progressFill.style.width = pct + '%';
                    progressLbl.textContent = pct + '%';
                    progressCnt.textContent = done + ' de ' + total + ' datas — ' +
                        MONTH_NAMES[d.month - 1] + ' ' + String(d.day).padStart(2, '0');
                    processNext();
                });
        }

        processNext();
    });
})();
