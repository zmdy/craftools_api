(function () {
    const app        = document.getElementById('ek-import-app');
    if (!app) return;

    const fileInput    = document.getElementById('ek-file');
    const preview      = document.getElementById('ek-preview');
    const previewCount = document.getElementById('ek-preview-count');
    const startBtn     = document.getElementById('ek-start');
    const progressWrap = document.getElementById('ek-progress');
    const progressFill = document.getElementById('ek-progress-fill');
    const progressLbl  = document.getElementById('ek-progress-label');
    const progressCnt  = document.getElementById('ek-progress-counts');
    const doneBox      = document.getElementById('ek-done');
    const doneMsg      = document.getElementById('ek-done-msg');
    const errorLog     = document.getElementById('ek-error-log');
    const currentCount = document.getElementById('ek-current-count');

    const CSRF  = app.dataset.csrf;
    const BATCH = 300;

    let combos = [];

    // ── Achata o metadata.json original (xsalazar/emoji-kitchen-backend) num
    // array simples de combos: cada `data[codepoint].combinations[otherCodepoint]`
    // é uma lista de objetos {leftEmoji, rightEmoji, leftEmojiCodepoint,
    // rightEmojiCodepoint, gStaticUrl, isLatest, ...}. ──────────────────────
    function flattenMetadata(json) {
        const out = [];
        const data = json && json.data;
        if (!data || typeof data !== 'object') return out;

        Object.keys(data).forEach(function (codepoint) {
            const entry = data[codepoint];
            const combinations = entry && entry.combinations;
            if (!combinations || typeof combinations !== 'object') return;

            Object.keys(combinations).forEach(function (otherCodepoint) {
                const list = combinations[otherCodepoint];
                if (!Array.isArray(list)) return;
                list.forEach(function (combo) {
                    if (combo && combo.leftEmoji && combo.rightEmoji && combo.gStaticUrl) {
                        out.push(combo);
                    }
                });
            });
        });

        return out;
    }

    fileInput.addEventListener('change', function () {
        const file = this.files[0];
        if (!file) return;

        previewCount.textContent = 'Lendo arquivo…';
        preview.hidden = false;
        startBtn.disabled = true;

        const reader = new FileReader();
        reader.onload = function (e) {
            let json;
            try {
                json = JSON.parse(e.target.result);
            } catch (err) {
                previewCount.textContent = 'Erro: arquivo não é um JSON válido (' + err.message + ').';
                return;
            }

            combos = flattenMetadata(json);
            if (!combos.length) {
                previewCount.textContent = 'Nenhum combo encontrado neste arquivo (formato inesperado).';
                startBtn.disabled = true;
                return;
            }

            previewCount.textContent = combos.length.toLocaleString('pt-BR') + ' combo(s) encontrado(s), prontos para importar.';
            startBtn.disabled = false;
        };
        reader.onerror = function () {
            previewCount.textContent = 'Erro ao ler o arquivo.';
        };
        reader.readAsText(file, 'UTF-8');
    });

    // ── Importação em lotes ──────────────────────────────────────────────────
    startBtn.addEventListener('click', function () {
        if (!combos.length) return;

        startBtn.disabled   = true;
        fileInput.disabled  = true;
        progressWrap.hidden = false;
        doneBox.hidden      = true;
        errorLog.innerHTML  = '';

        const total  = combos.length;
        let processed = 0;
        let errCount  = 0;
        const errMsgs = [];
        let offset    = 0;
        let lastTotalInDb = null;

        function processNext() {
            if (offset >= total) {
                progressWrap.hidden = true;
                doneBox.hidden      = false;
                doneMsg.textContent = processed.toLocaleString('pt-BR') + ' combo(s) processado(s) com sucesso.' +
                    (errCount ? ' ' + errCount + ' lote(s) com erro.' : '') +
                    (lastTotalInDb !== null ? ' Total agora no banco: ' + lastTotalInDb.toLocaleString('pt-BR') + '.' : '');
                if (currentCount && lastTotalInDb !== null) {
                    currentCount.textContent = lastTotalInDb.toLocaleString('pt-BR');
                }

                if (errMsgs.length) {
                    const ul = document.createElement('ul');
                    ul.style.cssText = 'font-size:12.5px; color:#b91c1c; margin-top:10px; padding-left:18px;';
                    errMsgs.forEach(function (msg) {
                        const li = document.createElement('li');
                        li.textContent = msg;
                        ul.appendChild(li);
                    });
                    errorLog.appendChild(ul);
                }
                return;
            }

            const batch    = combos.slice(offset, offset + BATCH);
            const batchNum = Math.floor(offset / BATCH) + 1;
            offset        += BATCH;

            const body = new FormData();
            body.append('_csrf', CSRF);
            body.append('items', JSON.stringify(batch));

            fetch('emoji_kitchen_import_ajax.php', { method: 'POST', body: body })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data.status !== 'success') throw new Error(data.message || 'Erro desconhecido');
                    processed += data.data.processed;
                    lastTotalInDb = data.data.total;
                })
                .catch(function (err) {
                    errCount++;
                    errMsgs.push('Lote ' + batchNum + ': ' + err.message);
                })
                .finally(function () {
                    const done = Math.min(offset, total);
                    const pct  = Math.round((done / total) * 100);
                    progressFill.style.width = pct + '%';
                    progressLbl.textContent  = pct + '%';
                    progressCnt.textContent  = done.toLocaleString('pt-BR') + ' de ' + total.toLocaleString('pt-BR') + ' processados';
                    processNext();
                });
        }

        processNext();
    });
})();
