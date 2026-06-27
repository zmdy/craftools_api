<?php
// Não precisa de checagem adicional: index.php já garante sessão admin
?>

<div class="card">
    <div class="card-head"><h2>Importar Frases via CSV</h2></div>
    <div class="card-body">
        <p class="text-muted" style="margin-bottom:16px; line-height:1.6;">
            Importe frases em massa a partir de um arquivo <strong>.csv</strong>.
            Os campos devem ser separados por <strong>ponto-e-vírgula (<code>;</code>)</strong>.
            Múltiplas categorias devem ser separadas por <strong>vírgula (<code>,</code>)</strong> dentro do campo categoria.<br>
            A primeira linha pode ser um cabeçalho — ela é detectada automaticamente e ignorada.
        </p>

        <div class="card" style="background:var(--bg-input); border:1px solid var(--border); margin-bottom:20px;">
            <div class="card-body">
                <strong style="font-size:13px;">Formato esperado das colunas (ordem obrigatória):</strong>
                <div style="margin-top:10px; overflow-x:auto;">
                    <table class="data-table" style="min-width:560px;">
                        <thead>
                            <tr>
                                <th>#</th><th>Coluna</th><th>Obrigatório</th><th>Exemplo</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td>1</td><td><code>phrase</code></td><td>✔</td><td>A vida é bela</td></tr>
                            <tr><td>2</td><td><code>author</code></td><td>—</td><td>Roberto Benigni</td></tr>
                            <tr><td>3</td><td><code>category</code></td><td>—</td><td>motivacional,vida</td></tr>
                            <tr><td>4</td><td><code>language</code></td><td>—</td><td>pt-br</td></tr>
                            <tr><td>5</td><td><code>tier</code></td><td>—</td><td>free</td></tr>
                        </tbody>
                    </table>
                </div>
                <div class="help-text" style="margin-top:10px;">
                    Exemplo de linha: <code>A vida é bela;Roberto Benigni;motivacional,vida;pt-br;free</code>
                </div>
            </div>
        </div>

        <div id="csv-import-app" data-csrf="<?= e(csrfToken()) ?>">
            <div class="field-row" style="align-items:flex-end;">
                <div class="field" style="flex:1;">
                    <label for="csv-file">Arquivo CSV</label>
                    <input type="file" id="csv-file" accept=".csv,text/csv" style="display:block; padding:6px 0;">
                </div>
                <div class="field">
                    <label for="csv-default-tier">Tier padrão</label>
                    <select id="csv-default-tier">
                        <option value="free">Free</option>
                        <option value="plus">Plus</option>
                        <option value="premium">Premium</option>
                    </select>
                </div>
                <div class="field">
                    <label for="csv-default-lang">Idioma padrão</label>
                    <select id="csv-default-lang">
                        <option value="pt-br">Português (BR)</option>
                        <option value="en">English</option>
                        <option value="es">Español</option>
                    </select>
                </div>
            </div>

            <div id="csv-preview" hidden style="margin-bottom:18px;">
                <div class="d-flex gap-2" style="margin-bottom:10px; align-items:center;">
                    <strong id="csv-preview-count" style="font-size:13px;"></strong>
                    <span id="csv-preview-errors" class="text-muted" style="font-size:12.5px; color:#ef4444;"></span>
                </div>
                <div style="overflow-x:auto; max-height:260px; overflow-y:auto; border:1px solid var(--border); border-radius:8px;">
                    <table class="data-table" id="csv-preview-table">
                        <thead>
                            <tr><th>#</th><th>Frase</th><th>Autor</th><th>Categorias</th><th>Idioma</th><th>Tier</th><th></th></tr>
                        </thead>
                        <tbody id="csv-preview-body"></tbody>
                    </table>
                </div>
            </div>

            <div style="margin-top:6px;">
                <button type="button" class="btn btn-primary" id="csv-start" disabled style="padding:12px 24px; font-size:15px;">
                    <span class="material-symbols-outlined">upload_file</span>
                    Importar frases
                </button>
                <a href="index.php?page=phrases" class="btn btn-outline" style="margin-left:8px;">Cancelar</a>
            </div>

            <div id="csv-progress" hidden style="margin-top:20px;">
                <div class="progress-bar"><div class="progress-bar-fill" id="csv-progress-fill" style="width:0%"></div></div>
                <div class="progress-label" id="csv-progress-label">0%</div>
                <div class="flex-between" style="margin-top:4px;">
                    <span class="text-muted" id="csv-progress-counts" style="font-size:12.5px;"></span>
                </div>
            </div>

            <div id="csv-done" class="flash flash-success" hidden style="margin-top:18px;">
                <span class="material-symbols-outlined">check_circle</span>
                <span id="csv-done-msg"></span>
                <a href="index.php?page=phrases" style="margin-left:8px;">Ver frases</a>
            </div>

            <div id="csv-error-log" style="margin-top:12px;"></div>
        </div>
    </div>
</div>

