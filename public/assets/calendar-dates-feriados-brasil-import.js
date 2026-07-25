(function () {
    const app = document.getElementById('cfb-import-app');
    if (!app) return;

    const yearInput  = document.getElementById('cfb-year');
    const startBtn   = document.getElementById('cfb-start');
    const loading    = document.getElementById('cfb-loading');
    const doneBox    = document.getElementById('cfb-done');
    const doneMsg    = document.getElementById('cfb-done-msg');
    const errorLog   = document.getElementById('cfb-error-log');

    const CSRF = app.dataset.csrf;

    const categoryLabels = { holiday: 'feriado(s)', commemoration_main: 'data(s) comemorativa(s) principal(is)' };

    startBtn.addEventListener('click', function () {
        const year = parseInt(yearInput.value, 10);
        if (!year || year < 2000 || year > 2100) {
            yearInput.focus();
            return;
        }

        startBtn.disabled  = true;
        yearInput.disabled = true;
        loading.hidden     = false;
        doneBox.hidden     = true;
        errorLog.innerHTML = '';

        const body = new FormData();
        body.append('op', 'process');
        body.append('_csrf', CSRF);
        body.append('year', String(year));

        fetch('calendar_dates_feriados_brasil_import_ajax.php', { method: 'POST', body: body })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.status !== 'success') throw new Error(data.message || 'Erro desconhecido');

                const counts = data.data.counts || {};
                const parts = Object.keys(counts)
                    .filter(function (k) { return counts[k] > 0; })
                    .map(function (k) { return counts[k].toLocaleString('pt-BR') + ' ' + (categoryLabels[k] || k); });

                const anbimaNote = data.data.nacionalSource === 'anbima'
                    ? ' Feriados nacionais via ANBIMA (ainda não disponíveis no GitHub para ' + year + ').'
                    : '';

                doneMsg.textContent = data.data.total.toLocaleString('pt-BR') + ' registro(s) gravado(s) para ' + year +
                    (parts.length ? ' (' + parts.join(', ') + ')' : '') + '.' + anbimaNote;
                loading.hidden = true;
                doneBox.hidden = false;

                if (data.data.errors && data.data.errors.length) {
                    const ul = document.createElement('ul');
                    ul.style.cssText = 'font-size:12.5px; color:#b91c1c; margin-top:10px; padding-left:18px;';
                    data.data.errors.forEach(function (msg) {
                        const li = document.createElement('li');
                        li.textContent = msg;
                        ul.appendChild(li);
                    });
                    errorLog.appendChild(ul);
                }
            })
            .catch(function (err) {
                loading.hidden = true;
                const div = document.createElement('div');
                div.className = 'flash flash-error';
                div.style.marginTop = '12px';
                div.textContent = 'Erro: ' + err.message;
                errorLog.appendChild(div);
            })
            .finally(function () {
                startBtn.disabled  = false;
                yearInput.disabled = false;
            });
    });
})();
