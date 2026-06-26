<?php
// Não precisa de checagem aqui porque o index.php já garante que é admin

$baseDir = CRAFTOOLS_API_ROOT . '/assets/original';
$dirExists = is_dir($baseDir);
?>

<div class="card">
    <div class="card-head"><h2>Processar Arquivos Originais</h2></div>
    <div class="card-body">
        <?php if (!$dirExists): ?>
            <div class="flash flash-error" style="margin-bottom: 16px;">
                <span class="material-symbols-outlined">error</span>
                A pasta <code>assets/original/</code> não foi encontrada no servidor. Ela já deveria existir por padrão (com <code>backgrounds/</code> e <code>overlays/</code>) — verifique se o repositório foi clonado corretamente.
            </div>
        <?php else: ?>

        <p class="text-muted" style="margin-bottom:16px; line-height:1.5;">
            Escaneia <code>assets/original/backgrounds/</code> e <code>assets/original/overlays/</code>: cada
            subpasta se torna uma <strong>Coleção</strong> (plano Gratuito) e cada imagem dentro dela (JPEG, PNG,
            GIF ou WebP) pode ser selecionada abaixo para conversão em <strong>WebP</strong>. Isso remove
            metadados inseguros (EXIF) e garante carregamento rápido no editor do cliente. Importações repetidas
            reaproveitam a coleção já criada em vez de duplicá-la.
        </p>

        <div id="bulk-import-app" data-csrf="<?= e(csrfToken()) ?>">
            <div id="bi-loading" class="text-muted">Carregando arquivos disponíveis…</div>
            <div id="bi-empty" class="text-muted" hidden>
                Nenhuma imagem encontrada em <code>assets/original/</code>. Adicione subpastas dentro de
                <code>backgrounds/</code> ou <code>overlays/</code>.
            </div>

            <div id="bi-content" hidden>
                <div class="field-row" style="margin-bottom:16px;">
                    <div class="field">
                        <label for="bi-max-width">Resolução máxima (px, lado maior)</label>
                        <input type="number" id="bi-max-width" min="200" max="6000" step="50" value="2000">
                        <div class="help-text">Imagens maiores são redimensionadas preservando a proporção.</div>
                    </div>
                    <div class="field">
                        <label for="bi-quality">Qualidade WebP (1–100)</label>
                        <input type="number" id="bi-quality" min="1" max="100" step="1" value="82">
                        <div class="help-text">Valores maiores geram arquivos mais pesados e com mais detalhe.</div>
                    </div>
                </div>

                <div class="d-flex gap-2" style="margin-bottom:14px; align-items:center;">
                    <button type="button" class="btn btn-outline btn-sm" id="bi-select-all">Selecionar todas</button>
                    <button type="button" class="btn btn-outline btn-sm" id="bi-select-none">Desmarcar todas</button>
                    <span class="text-muted" id="bi-selected-count" style="font-size:12.5px;"></span>
                </div>

                <div id="bi-collections"></div>

                <div style="margin-top:18px;">
                    <button type="button" class="btn btn-primary" id="bi-start" style="padding:12px 24px; font-size:15px;">
                        <span class="material-symbols-outlined">drive_folder_upload</span>
                        Iniciar Importação
                    </button>
                </div>
            </div>

            <div id="bi-progress" hidden style="margin-top:20px;">
                <div class="progress-bar"><div class="progress-bar-fill" id="bi-progress-fill"></div></div>
                <div class="progress-label" id="bi-progress-label">0%</div>
                <div class="flex-between" style="margin-top:4px;">
                    <span class="text-muted" id="bi-progress-counts" style="font-size:12.5px;"></span>
                    <button type="button" class="btn btn-outline btn-sm" id="bi-cancel">Cancelar</button>
                </div>
                <div id="bi-progress-errors"></div>
            </div>

            <div id="bi-done" class="flash flash-success" hidden style="margin-top:18px;">
                <span class="material-symbols-outlined">check_circle</span>
                <span id="bi-done-msg"></span>
            </div>
        </div>

        <?php endif; ?>
    </div>
</div>
