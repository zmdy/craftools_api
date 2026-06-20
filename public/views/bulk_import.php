<?php
// Não precisa de checagem aqui porque o index.php já garante que é admin

$baseDir = CRAFTOOLS_API_ROOT . '/assets/original';
$dirExists = is_dir($baseDir);

$backgroundsDir = $baseDir . '/backgrounds';
$overlaysDir = $baseDir . '/overlays';

$bgCount = $dirExists && is_dir($backgroundsDir) ? count(array_diff(scandir($backgroundsDir), ['.', '..'])) : 0;
$ovCount = $dirExists && is_dir($overlaysDir) ? count(array_diff(scandir($overlaysDir), ['.', '..'])) : 0;
?>

<div class="card">
    <div class="card-head"><h2>Processar Arquivos Originais</h2></div>
    <div class="card-body">
        <?php if (!$dirExists): ?>
            <div class="flash flash-error" style="margin-bottom: 16px;">
                <span class="material-symbols-outlined">error</span>
                A pasta <code>assets/original/</code> não foi encontrada no servidor. Crie-a e adicione subpastas dentro de <code>backgrounds/</code> ou <code>overlays/</code>.
            </div>
        <?php else: ?>
            <div style="display:flex; gap:20px; margin-bottom: 24px;">
                <div class="stat-box" style="flex:1; padding: 16px; border: 1px solid var(--border-color); border-radius: 8px;">
                    <h3 style="margin-top:0; color: var(--text-muted); font-size: 14px;">Coleções de Fundos</h3>
                    <div style="font-size: 24px; font-weight: 500;"><?= $bgCount ?> encontradas</div>
                </div>
                <div class="stat-box" style="flex:1; padding: 16px; border: 1px solid var(--border-color); border-radius: 8px;">
                    <h3 style="margin-top:0; color: var(--text-muted); font-size: 14px;">Coleções de Overlays</h3>
                    <div style="font-size: 24px; font-weight: 500;"><?= $ovCount ?> encontradas</div>
                </div>
            </div>
        <?php endif; ?>

        <p class="text-muted" style="margin-bottom: 16px; line-height: 1.5;">
            Esta ferramenta escaneia automaticamente os diretórios na pasta <code>assets/original/</code>. 
            Cada subpasta encontrada será cadastrada como uma nova <strong>Coleção</strong> (no plano Gratuito), e todas as imagens válidas (JPEG, PNG, GIF, WebP) dentro delas serão processadas uma a uma.<br><br>
            Durante o processamento, as imagens serão <strong>redimensionadas para um máximo de 2000px</strong> e convertidas nativamente para o formato <strong>WebP (qualidade 82)</strong>. Isso remove qualquer metadado inseguro (EXIF) e garante que as fotos carreguem rapidamente no editor do cliente. As fotos processadas serão movidas para o storage final.
        </p>

        <form method="post" action="index.php?page=bulk_import" data-confirm="Tem certeza? Este processo pode demorar alguns minutos dependendo da quantidade e tamanho das imagens. Por favor, NÃO feche esta janela ou recarregue a página até que o processo termine.">
            <?= csrfField() ?>
            <input type="hidden" name="_action" value="bulk_import_original">
            <button type="submit" class="btn btn-primary" <?= (!$dirExists || ($bgCount === 0 && $ovCount === 0)) ? 'disabled' : '' ?> style="padding: 12px 24px; font-size: 15px;">
                <span class="material-symbols-outlined">drive_folder_upload</span>
                Iniciar Importação Completa
            </button>
        </form>
    </div>
</div>
