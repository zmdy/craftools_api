(function () {
    const app        = document.getElementById('csv-import-app');
    if (!app) return;

    const fileInput    = document.getElementById('csv-file');
    const sourceInput  = document.getElementById('csv-source');
    const defaultTier  = document.getElementById('csv-default-tier');
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
    const VALID_CATEGORIES = ['holiday', 'commemoration', 'saint', 'event'];
    const VALID_SCOPES = ['national', 'state', 'municipal'];

    let parsedRows = [];

    // ── Parse CSV (delimitador ;) ────────────────────────────────────────────
    // Ordem: category;month;day;title;year;link;holiday_scope;uf;city;description;tier
    function parseCsv(text) {
        const lines  = text.replace(/\r\n/g, '\n').replace(/\r/g, '\n').split('\n').filter(l => l.trim() !== '');
        const rows   = [];
        const errors = [];

        lines.forEach(function (line, idx) {
            const cols     = line.split(';').map(function (c) { return c.trim(); });
            const category = (cols[0] || '').toLowerCase();

            // Detecta cabeçalho na primeira linha
            if (idx === 0 && /^category|^categoria/i.test(category)) return;

            if (VALID_CATEGORIES.indexOf(category) === -1) {
                errors.push('Linha ' + (idx + 1) + ': categoria inválida ("' + category + '") — ignorada.');
                return;
            }
            const month = parseInt(cols[1], 10);
            const day   = parseInt(cols[2], 10);
            const title = cols[3] || '';
            if (!month || month < 1 || month > 12 || !day || day < 1 || day > 31) {
                errors.push('Linha ' + (idx + 1) + ': mês/dia inválidos — ignorada.');
                return;
            }
            if (!title) {
                errors.push('Linha ' + (idx + 1) + ': título vazio — ignorada.');
                return;
            }
            const year = cols[4] || '';
            if (category === 'event' && !year) {
                errors.push('Linha ' + (idx + 1) + ': eventos históricos exigem "year" — ignorada.');
                return;
            }
            const link  = cols[5] || '';
            const scope = VALID_SCOPES.indexOf(cols[6]) !== -1 ? cols[6] : (category === 'holiday' ? 'national' : '');
            const uf    = cols[7] || '';
            const city  = cols[8] || '';
            const description = cols[9] || '';
            const tier  = VALID_TIERS.indexOf(cols[10]) !== -1 ? cols[10] : defaultTier.value;

            rows.push({
                category: category, month: month, day: day, title: title, year: year,
                link: link, holiday_scope: scope, uf: uf, city: city,
                description: description, tier: tier
            });
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

    function detailFor(r) {
        if (r.category === 'event' && r.year) return 'Ano ' + esc(r.year);
        if (r.category === 'holiday') return esc(r.holiday_scope || 'national') + (r.uf ? ' — ' + esc(r.uf) : '');
        if (r.category === 'saint' && r.link) return 'link ↗';
        return '—';
    }

    function renderPreview(rows, errors) {
        previewBody.innerHTML = '';

        rows.slice(0, 200).forEach(function (r, i) {
            var tr = document.createElement('tr');
            tr.innerHTML =
                '<td class="text-muted">' + (i + 1) + '</td>' +
                '<td>' + esc(r.category) + '</td>' +
                '<td class="mono">' + String(r.day).padStart(2, '0') + '/' + String(r.month).padStart(2, '0') + '</td>' +
                '<td style="max-width:220px;">' + esc(r.title.substring(0, 90)) + (r.title.length > 90 ? '…' : '') + '</td>' +
                '<td class="text-muted">' + detailFor(r) + '</td>' +
                '<td><span class="badge badge-' + esc(r.tier) + '">' + esc(r.tier) + '</span></td>' +
                '<td><button type="button" class="btn btn-outline btn-sm" data-idx="' + i + '" title="Remover">✕</button></td>';
            previewBody.appendChild(tr);
        });

        if (rows.length > 200) {
            var tr = document.createElement('tr');
            tr.innerHTML = '<td colspan="7" class="text-muted" style="text-align:center; padding:10px;">… e mais ' + (rows.length - 200) + ' registro(s) não exibidos na pré-visualização.</td>';
            previewBody.appendChild(tr);
        }

        previewCount.textContent = rows.length + ' registro(s) válido(s) para importar.';
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
        if (sourceInput) sourceInput.disabled = true;
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
                progressWrap.hidden = true;
                doneBox.hidden      = false;
                doneMsg.textContent = imported + ' registro(s) importado(s) com sucesso.' +
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
            body.append('source', (sourceInput && sourceInput.value || '').trim());

            fetch('calendar_dates_csv_ajax.php', { method: 'POST', body: body })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data.status !== 'success') throw new Error(data.message || 'Erro desconhecido');

                    data.data.results.forEach(function (r) {
                        if (r.status === 'ok') {
                            imported++;
                        } else {
                            errCount++;
                            errLines.push('"' + (r.title || '').substring(0, 50) + '": ' + r.message);
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
