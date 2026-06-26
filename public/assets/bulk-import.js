// bulk-import.js — UI da Importação em Massa: lista os arquivos disponíveis
// em assets/original/, permite escolher quais sobem (com resolução/qualidade
// configuráveis) e processa em lotes pequenos via AJAX, atualizando uma barra
// de progresso real a cada lote em vez de bloquear a tela numa única
// requisição síncrona (comportamento antigo).
(function () {
    'use strict';

    var BATCH_SIZE = 6;
    var TYPE_LABELS = { backgrounds: 'Fundo', overlays: 'Overlay' };

    var root = document.getElementById('bulk-import-app');
    if (!root) return;

    var csrfToken = root.getAttribute('data-csrf') || '';
    var elLoading = document.getElementById('bi-loading');
    var elEmpty = document.getElementById('bi-empty');
    var elContent = document.getElementById('bi-content');
    var elCollections = document.getElementById('bi-collections');
    var elSelectedCount = document.getElementById('bi-selected-count');
    var elStart = document.getElementById('bi-start');
    var elMaxWidth = document.getElementById('bi-max-width');
    var elQuality = document.getElementById('bi-quality');
    var elProgress = document.getElementById('bi-progress');
    var elProgressFill = document.getElementById('bi-progress-fill');
    var elProgressLabel = document.getElementById('bi-progress-label');
    var elProgressCounts = document.getElementById('bi-progress-counts');
    var elProgressErrors = document.getElementById('bi-progress-errors');
    var elDone = document.getElementById('bi-done');
    var elDoneMsg = document.getElementById('bi-done-msg');
    var elCancel = document.getElementById('bi-cancel');

    var currentCollections = null;
    var cancelRequested = null; // setado por startImport(); chamado pelo botão Cancelar

    function escapeHtml(s) {
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function formatBytes(n) {
        if (n < 1024) return n + ' B';
        if (n < 1024 * 1024) return (n / 1024).toFixed(1) + ' KB';
        return (n / (1024 * 1024)).toFixed(1) + ' MB';
    }

    function clampInt(val, min, max, fallback) {
        var n = parseInt(val, 10);
        if (isNaN(n)) return fallback;
        return Math.max(min, Math.min(max, n));
    }

    function chunk(arr, size) {
        var out = [];
        for (var i = 0; i < arr.length; i += size) out.push(arr.slice(i, i + size));
        return out;
    }

    function updateSelectedCount() {
        var checked = elCollections.querySelectorAll('input[type=checkbox][data-file]:checked').length;
        var total = elCollections.querySelectorAll('input[type=checkbox][data-file]').length;
        elSelectedCount.textContent = checked + ' de ' + total + ' imagens selecionadas';
        elStart.disabled = checked === 0;
    }

    function setAllChecked(checked) {
        elCollections.querySelectorAll('input[type=checkbox]').forEach(function (cb) {
            cb.checked = checked;
            cb.indeterminate = false;
        });
        updateSelectedCount();
    }

    function renderCollections(collections) {
        elCollections.innerHTML = '';

        collections.forEach(function (col, idx) {
            var wrap = document.createElement('div');
            wrap.className = 'bi-collection';

            var head = document.createElement('div');
            head.className = 'bi-collection-head';
            head.innerHTML =
                '<span class="material-symbols-outlined bi-chevron">expand_more</span>' +
                '<input type="checkbox" class="bi-collection-toggle" checked data-col="' + idx + '">' +
                '<strong>' + escapeHtml(col.name) + '</strong>' +
                '<span class="badge badge-free">' + (TYPE_LABELS[col.type] || col.type) + '</span>' +
                '<span class="bi-count">' + col.files.length + ' imagens</span>';
            wrap.appendChild(head);

            var filesWrap = document.createElement('div');
            filesWrap.className = 'bi-files';
            col.files.forEach(function (f, fIdx) {
                var row = document.createElement('label');
                row.className = 'bi-file-row';
                row.innerHTML =
                    '<input type="checkbox" data-file checked data-col="' + idx + '" data-idx="' + fIdx + '">' +
                    '<span class="bi-file-name" title="' + escapeHtml(f.name) + '">' + escapeHtml(f.name) + '</span>' +
                    '<span class="bi-file-size">' + formatBytes(f.size) + '</span>';
                filesWrap.appendChild(row);
            });
            wrap.appendChild(filesWrap);
            elCollections.appendChild(wrap);

            // Expandir/colapsar ao clicar no título, sem disparar o checkbox.
            head.addEventListener('click', function (ev) {
                if (ev.target.tagName === 'INPUT') return;
                wrap.classList.toggle('collapsed');
            });

            var toggle = head.querySelector('.bi-collection-toggle');
            toggle.addEventListener('click', function (ev) { ev.stopPropagation(); });
            toggle.addEventListener('change', function () {
                filesWrap.querySelectorAll('input[type=checkbox]').forEach(function (cb) {
                    cb.checked = toggle.checked;
                });
                updateSelectedCount();
            });
        });

        // Estado "parcial" (indeterminate) no checkbox da coleção quando nem
        // todos os arquivos dela estão marcados.
        elCollections.querySelectorAll('input[type=checkbox][data-file]').forEach(function (cb) {
            cb.addEventListener('change', function () {
                var colIdx = cb.getAttribute('data-col');
                var siblings = elCollections.querySelectorAll('input[type=checkbox][data-file][data-col="' + colIdx + '"]');
                var allChecked = true, noneChecked = true;
                siblings.forEach(function (s) {
                    if (s.checked) noneChecked = false; else allChecked = false;
                });
                var toggles = elCollections.querySelectorAll('.bi-collection-toggle');
                var toggle = toggles[colIdx];
                if (toggle) {
                    toggle.checked = allChecked;
                    toggle.indeterminate = !allChecked && !noneChecked;
                }
                updateSelectedCount();
            });
        });

        updateSelectedCount();
    }

    function collectSelectedItems(collections) {
        var items = [];
        elCollections.querySelectorAll('input[type=checkbox][data-file]:checked').forEach(function (cb) {
            var colIdx = parseInt(cb.getAttribute('data-col'), 10);
            var fIdx = parseInt(cb.getAttribute('data-idx'), 10);
            var col = collections[colIdx];
            var file = col.files[fIdx];
            items.push({ type: col.type, collection: col.name, file: file.name });
        });
        return items;
    }

    function loadScan() {
        fetch('bulk_import_ajax.php?op=scan', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                elLoading.hidden = true;
                var data = resp && resp.data;
                if (!resp || resp.status !== 'success' || !data || !data.collections || data.collections.length === 0) {
                    elEmpty.hidden = false;
                    return;
                }
                currentCollections = data.collections;
                elContent.hidden = false;
                renderCollections(currentCollections);
            })
            .catch(function () {
                elLoading.hidden = true;
                elEmpty.hidden = false;
                elEmpty.textContent = 'Não foi possível carregar a lista de imagens. Atualize a página e tente novamente.';
            });
    }

    function startImport() {
        if (!currentCollections) return;
        var items = collectSelectedItems(currentCollections);
        if (items.length === 0) return;

        if (!window.confirm('Importar ' + items.length + ' imagem(ns) agora? As imagens originais serão convertidas para WebP e cadastradas nas coleções correspondentes.')) {
            return;
        }

        var maxWidth = clampInt(elMaxWidth.value, 200, 6000, 2000);
        var quality = clampInt(elQuality.value, 1, 100, 82);
        var batches = chunk(items, BATCH_SIZE);

        elContent.hidden = true;
        elProgress.hidden = false;
        elProgressErrors.innerHTML = '';
        elCancel.hidden = false;

        var processed = 0, okCount = 0, errCount = 0;
        var total = items.length;
        var errorList = [];
        var aborted = false;

        cancelRequested = function () {
            if (aborted) return;
            aborted = true;
            finish('Importação cancelada: ' + okCount + ' imagens importadas antes de parar' +
                (errCount > 0 ? ', ' + errCount + ' com erro.' : '.'));
        };

        function updateProgress() {
            var pct = total === 0 ? 100 : Math.round((processed / total) * 100);
            elProgressFill.style.width = pct + '%';
            elProgressLabel.textContent = pct + '% (' + processed + ' de ' + total + ')';
            elProgressCounts.textContent = okCount + ' importadas com sucesso, ' + errCount + ' com erro.';
        }

        function renderErrors() {
            elProgressErrors.innerHTML = '';
            if (errorList.length === 0) return;
            var list = document.createElement('div');
            list.className = 'bi-error-list';
            errorList.slice(0, 30).forEach(function (msg) {
                var d = document.createElement('div');
                d.textContent = msg;
                list.appendChild(d);
            });
            elProgressErrors.appendChild(list);
        }

        function finish(message) {
            renderErrors();
            elCancel.hidden = true;
            cancelRequested = null;
            elDone.hidden = false;
            elDoneMsg.textContent = message || ('Importação concluída: ' + okCount + ' imagens importadas' +
                (errCount > 0 ? ', ' + errCount + ' com erro (veja a lista acima).' : '.'));
        }

        function runNext(i) {
            if (aborted) return;
            if (i >= batches.length) {
                finish();
                return;
            }
            var fd = new FormData();
            fd.append('op', 'process');
            fd.append('_csrf', csrfToken);
            fd.append('max_width', String(maxWidth));
            fd.append('quality', String(quality));
            fd.append('items', JSON.stringify(batches[i]));

            fetch('bulk_import_ajax.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) {
                    if (r.status === 419 || r.status === 401) {
                        aborted = true;
                        finish('Sessão expirada durante a importação. Atualize a página e faça login novamente para continuar (' + processed + ' de ' + total + ' já processadas).');
                        return null;
                    }
                    return r.json().catch(function () { return null; });
                })
                .then(function (resp) {
                    if (aborted) return;
                    if (resp && resp.status === 'success' && resp.data && resp.data.results) {
                        resp.data.results.forEach(function (r) {
                            processed++;
                            if (r.status === 'ok') {
                                okCount++;
                            } else {
                                errCount++;
                                errorList.push(r.file + ': ' + (r.message || 'erro desconhecido'));
                            }
                        });
                    } else {
                        // Lote inteiro falhou (ex.: erro de servidor) — conta como
                        // erro para cada item, sem travar a barra de progresso.
                        processed += batches[i].length;
                        errCount += batches[i].length;
                        var msg = (resp && resp.message) || 'falha na requisição';
                        errorList.push('Lote ' + (i + 1) + ': ' + msg);
                    }
                    updateProgress();
                    renderErrors();
                    runNext(i + 1);
                })
                .catch(function () {
                    if (aborted) return;
                    processed += batches[i].length;
                    errCount += batches[i].length;
                    errorList.push('Lote ' + (i + 1) + ': falha de conexão.');
                    updateProgress();
                    renderErrors();
                    runNext(i + 1);
                });
        }

        updateProgress();
        runNext(0);
    }

    document.getElementById('bi-select-all').addEventListener('click', function () { setAllChecked(true); });
    document.getElementById('bi-select-none').addEventListener('click', function () { setAllChecked(false); });
    elStart.addEventListener('click', startImport);
    elCancel.addEventListener('click', function () {
        if (cancelRequested) cancelRequested();
    });

    loadScan();
})();
