(function () {
    const app        = document.getElementById('csv-import-app');
    if (!app) return;

    const fileInput    = document.getElementById('csv-file');
    const defaultTier  = document.getElementById('csv-default-tier');
    const defaultLang  = document.getElementById('csv-default-lang');
    const preview      = document.getElementById('csv-preview');
    const previewCount = document.getElementById('csv-preview-count');
    const previewErrs  = document.getElementById('csv-preview-errors');
    const previewBody  = document.getElementById('csv-preview-body');
    const startBtn     = document.getElementById('csv-start');
    const progressWrap = document.getElementById('csv-progress');
    const progressFill = document.getElementById('csv-progress-fill');
    const progressLbl  = document.getElementById('csv-progress-label');
    const progressCnt  = document.getElementById('csv-progress-counts');
    const doneBox      = document.getElementById('csv-done');
    const doneMsg      = document.getElementById('csv-done-msg');
    const errorLog     = document.getElementById('csv-error-log');

    const CSRF         = app.dataset.csrf;
    const BATCH        = 50;
    const VALID_TIERS  = ['free', 'plus', 'premium'];
    const VALID_LANGS  = ['pt-br', 'en', 'es'];

    let parsedRows = [];

    // ── Parse CSV (delimitador ;) ────────────────────────────────────────────
    function parseCsv(text) {
        const lines  = text.replace(/\r\n/g, '\n').replace(/\r/g, '\n').split('\n').filter(l => l.trim() !== '');
        const rows   = [];
        const errors = [];

        lines.forEach(function (line, idx) {
            const cols   = line.split(';').map(function (c) { return c.trim(); });
            const phrase = cols[0] || '';

            // Detecta cabeçalho na primeira linha
            if (idx === 0 && /^phrase|^frase/i.test(phrase)) return;

            if (!phrase) {
                errors.push('Linha ' + (idx + 1) + ': frase vazia — ignorada.');
                return;
            }

            const author   = cols[1] || '';
            const catRaw   = cols[2] || '';
            const category = catRaw.split(',').map(function (c) { return c.trim(); }).filter(Boolean).join(',');
            const language = VALID_LANGS.indexOf(cols[3]) !== -1 ? cols[3] : defaultLang.value;
            const tier     = VALID_TIERS.indexOf(cols[4]) !== -1 ? cols[4] : defaultTier.value;

            rows.push({ phrase: phrase, author: author, category: category, language: language, tier: tier });
        });

        return { rows: rows, errors: errors };
    }

    function esc(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function renderPreview(rows, errors) {
        previewBody.innerHTML = '';

        rows.slice(0, 200).forEach(function (r, i) {
            var cats = r.category
                ? r.category.split(',').map(function (c) {
                    return '<span class="badge" style="background:rgba(249,115,22,.1);color:#ea580c;margin-right:2px;">' + esc(c) + '</span>';
                  }).join('')
                : '—';

            var tr = document.createElement('tr');
            tr.innerHTML =
                '<td class="text-muted">' + (i + 1) + '</td>' +
                '<td style="max-width:260px;">' + esc(r.phrase.substring(0, 90)) + (r.phrase.length > 90 ? '…' : '') + '</td>' +
                '<td class="text-muted">' + esc(r.author || '—') + '</td>' +
                '<td>' + cats + '</td>' +
                '<td class="text-muted">' + esc(r.language) + '</td>' +
                '<td><span class="badge badge-' + esc(r.tier) + '">' + esc(r.tier) + '</span></td>' +
                '<td><button type="button" class="btn btn-outline btn-sm" data-idx="' + i + '" title="Remover">✕</button></td>';
            previewBody.appendChild(tr);
        });

        if (rows.length > 200) {
            var tr = document.createElement('tr');
            tr.innerHTML = '<td colspan="7" class="text-muted" style="text-align:center; padding:10px;">… e mais ' + (rows.length - 200) + ' frases não exibidas na pré-visualização.</td>';
            previewBody.appendChild(tr);
        }

        previewCount.textContent = rows.length + ' frase(s) válida(s) para importar.';
        previewErrs.textContent  = errors.length ? errors.length + ' linha(s) ignorada(s).' : '';
        preview.hidden           = false;
        startBtn.disabled        = rows.length === 0;
    }

    fileInput.addEventListener('change', function () {
        var file = this.files[0];
        if (!file) return;
        var reader = new FileReader();
        reader.onload = function (e) {
            var result = parseCsv(e.target.result);
            parsedRows = result.rows;
            renderPreview(result.rows, result.errors);
        };
        reader.readAsText(file, 'UTF-8');
    });

    // Remover linha individual do preview
    previewBody.addEventListener('click', function (e) {
        if (!e.target.dataset.idx) return;
        var idx = parseInt(e.target.dataset.idx, 10);
        parsedRows.splice(idx, 1);
        renderPreview(parsedRows, []);
    });

    // ── Importação em lotes ──────────────────────────────────────────────────
    startBtn.addEventListener('click', function () {
        if (!parsedRows.length) return;

        startBtn.disabled   = true;
        fileInput.disabled  = true;
        progressWrap.hidden = false;
        doneBox.hidden      = true;
        errorLog.innerHTML  = '';

        var total    = parsedRows.length;
        var imported = 0;
        var errCount = 0;
        var errLines = [];
        var offset   = 0;

        function processNext() {
            if (offset >= total) {
                // Tudo processado
                progressWrap.hidden = true;
                doneBox.hidden      = false;
                doneMsg.textContent = imported + ' frase(s) importada(s) com sucesso.' +
                    (errCount ? ' ' + errCount + ' erro(s).' : '');

                if (errLines.length) {
                    var ul = document.createElement('ul');
                    ul.style.cssText = 'font-size:12.5px; color:#b91c1c; margin-top:10px; padding-left:18px;';
                    errLines.forEach(function (msg) {
                        var li = document.createElement('li');
                        li.textContent = msg;
                        ul.appendChild(li);
                    });
                    errorLog.appendChild(ul);
                }
                return;
            }

            var batch    = parsedRows.slice(offset, offset + BATCH);
            var batchNum = Math.floor(offset / BATCH) + 1;
            offset      += BATCH;

            var body = new FormData();
            body.append('_csrf', CSRF);
            body.append('items', JSON.stringify(batch));

            fetch('phrases_csv_ajax.php', { method: 'POST', body: body })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data.status !== 'success') throw new Error(data.message || 'Erro desconhecido');

                    data.data.results.forEach(function (r) {
                        if (r.status === 'ok') {
                            imported++;
                        } else {
                            errCount++;
                            errLines.push('"' + (r.phrase || '').substring(0, 50) + '": ' + r.message);
                        }
                    });
                })
                .catch(function (err) {
                    errCount += batch.length;
                    errLines.push('Lote ' + batchNum + ': ' + err.message);
                })
                .finally(function () {
                    var done = Math.min(offset, total);
                    var pct  = Math.round((done / total) * 100);
                    progressFill.style.width = pct + '%';
                    progressLbl.textContent  = pct + '%';
                    progressCnt.textContent  = done + ' de ' + total + ' processadas';
                    processNext();
                });
        }

        processNext();
    });
})();
