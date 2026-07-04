<?php
// Não precisa de checagem adicional: index.php já garante sessão admin
$currentCount = emojiKitchenCount();
?>

<div class="card">
    <div class="card-head"><h2>Emoji Kitchen</h2></div>
    <div class="card-body">
        <p class="text-muted" style="margin-bottom:16px; line-height:1.6;">
            Catálogo de combinações de emojis (<a href="https://github.com/xsalazar/emoji-kitchen" target="_blank" rel="noopener">Emoji Kitchen</a>),
            usado pela ferramenta "Emoji Kitchen" e pela variável "Emoji Kitchen" no editor CrafTools.
            As imagens não são baixadas -- apenas a URL pública do Google (gstatic) de cada combo é guardada.
        </p>

        <div class="card" style="background:var(--bg-input); border:1px solid var(--border); margin-bottom:20px;">
            <div class="card-body d-flex" style="align-items:center; gap:10px;">
                <span class="material-symbols-outlined" style="font-size:28px;">emoji_emotions</span>
                <div>
                    <strong id="ek-current-count" style="font-size:18px;"><?= number_format($currentCount, 0, ',', '.') ?></strong>
                    <div class="text-muted" style="font-size:12.5px;">combo(s) cadastrado(s) atualmente</div>
                </div>
            </div>
        </div>

        <div class="card" style="background:var(--bg-input); border:1px solid var(--border); margin-bottom:20px;">
            <div class="card-body">
                <strong style="font-size:13px;">Como importar</strong>
                <ol style="margin:10px 0 0 18px; font-size:13px; line-height:1.7; color:var(--text-muted);">
                    <li>Baixe o arquivo <code>metadata.json</code> do repositório
                        <a href="https://github.com/xsalazar/emoji-kitchen-backend" target="_blank" rel="noopener">emoji-kitchen-backend</a> (pasta <code>app/</code>).</li>
                    <li>Selecione o arquivo abaixo e clique em "Iniciar importação".</li>
                    <li>O arquivo é lido inteiramente no navegador e enviado em lotes -- combos já cadastrados
                        (mesmo par de emojis) são atualizados, não duplicados.</li>
                </ol>
            </div>
        </div>

        <div id="ek-import-app" data-csrf="<?= e(csrfToken()) ?>">
            <div class="field-row" style="align-items:flex-end;">
                <div class="field" style="flex:1;">
                    <label for="ek-file">Arquivo metadata.json</label>
                    <input type="file" id="ek-file" accept=".json,application/json" style="display:block; padding:6px 0;">
                </div>
            </div>

            <div id="ek-preview" hidden style="margin-bottom:18px;">
                <strong id="ek-preview-count" style="font-size:13px;"></strong>
            </div>

            <div style="margin-top:6px;">
                <button type="button" class="btn btn-primary" id="ek-start" disabled style="padding:12px 24px; font-size:15px;">
                    <span class="material-symbols-outlined">upload_file</span>
                    Iniciar importação
                </button>
            </div>

            <div id="ek-progress" hidden style="margin-top:20px;">
                <div class="progress-bar"><div class="progress-bar-fill" id="ek-progress-fill" style="width:0%"></div></div>
                <div class="progress-label" id="ek-progress-label">0%</div>
                <div class="flex-between" style="margin-top:4px;">
                    <span class="text-muted" id="ek-progress-counts" style="font-size:12.5px;"></span>
                </div>
            </div>

            <div id="ek-done" class="flash flash-success" hidden style="margin-top:18px;">
                <span class="material-symbols-outlined">check_circle</span>
                <span id="ek-done-msg"></span>
            </div>

            <div id="ek-error-log" style="margin-top:12px;"></div>
        </div>
    </div>
</div>

<script src="assets/emoji-kitchen-import.js"></script>
