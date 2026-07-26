<?php
$familyId = (int) ($_GET['family'] ?? 0);
$activeFamily = $familyId > 0 ? fontFamilyFind($familyId) : null;

if ($activeFamily) {
    // -------------------------------------------------------------- arquivos de fonte
    $fontFiles = fontFilesByFamily($activeFamily['id']);
    ?>
    <a href="index.php?page=fonts" class="btn btn-outline btn-sm" style="margin-bottom:14px;">
        <span class="material-symbols-outlined">arrow_back</span> Voltar para famílias
    </a>

    <div class="card">
        <div class="card-head">
            <h2>Família: <?= e($activeFamily['name']) ?></h2>
            <span class="badge badge-<?= e($activeFamily['tier']) ?>"><?= e($activeFamily['tier']) ?></span>
            <span class="badge badge-outline"><?= e($activeFamily['category']) ?></span>
        </div>
        <div class="card-body">
            <form method="post" action="index.php?page=fonts&family=<?= (int) $activeFamily['id'] ?>" enctype="multipart/form-data">
                <?= csrfField() ?>
                <input type="hidden" name="_action" value="font_file_upload">
                <input type="hidden" name="family_id" value="<?= (int) $activeFamily['id'] ?>">
                <div class="field-row">
                    <div class="field">
                        <label>Arquivo de fonte (.ttf, .otf, .woff, .woff2 - até 10MB)</label>
                        <input type="file" name="font_file" accept=".ttf,.otf,.woff,.woff2,font/ttf,font/otf,font/woff,font/woff2" required>
                    </div>
                    <div class="field">
                        <label>Peso (Weight)</label>
                        <select name="weight">
                            <option value="100">100 - Thin</option>
                            <option value="200">200 - Extra Light</option>
                            <option value="300">300 - Light</option>
                            <option value="400" selected>400 - Regular</option>
                            <option value="500">500 - Medium</option>
                            <option value="600">600 - Semi Bold</option>
                            <option value="700">700 - Bold</option>
                            <option value="800">800 - Extra Bold</option>
                            <option value="900">900 - Black</option>
                        </select>
                    </div>
                    <div class="field">
                        <label>Estilo (Style)</label>
                        <select name="style">
                            <option value="normal" selected>Normal</option>
                            <option value="italic">Italic</option>
                        </select>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary"><span class="material-symbols-outlined">upload</span> Enviar arquivo de fonte</button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><h2>Arquivos cadastrados (<?= count($fontFiles) ?>)</h2></div>
        <div class="card-body">
            <?php if (!$fontFiles): ?>
                <p class="text-muted" style="font-size:13.5px;">Nenhum arquivo de fonte cadastrado nesta família ainda.</p>
            <?php else: ?>
            <table class="data-table">
                <thead><tr><th>Formato</th><th>Peso</th><th>Estilo</th><th>Tamanho</th><th>Caminho</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($fontFiles as $file): ?>
                    <tr>
                        <td><span class="badge badge-outline" style="text-transform:uppercase;"><?= e($file['format']) ?></span></td>
                        <td><?= (int) $file['weight'] ?></td>
                        <td><?= e($file['style']) ?></td>
                        <td class="text-muted"><?= $file['size_bytes'] !== null ? number_format((float) $file['size_bytes'] / 1024, 1, ',', '.') . ' KB' : '—' ?></td>
                        <td class="text-muted mono" style="font-size:12px;"><?= e($file['file_path']) ?></td>
                        <td class="actions">
                            <form method="post" action="index.php?page=fonts&family=<?= (int) $activeFamily['id'] ?>" data-confirm="Remover este arquivo de fonte?">
                                <?= csrfField() ?>
                                <input type="hidden" name="_action" value="font_file_delete">
                                <input type="hidden" name="id" value="<?= (int) $file['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm">Excluir</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
    <?php
} else {
    // ----------------------------------------------------------- famílias
    $editId = (int) ($_GET['edit'] ?? 0);
    $editing = $editId > 0 ? fontFamilyFind($editId) : null;
    $families = fontFamilyList();
    ?>

    <div class="card">
        <div class="card-head"><h2><?= $editing ? 'Editar família' : 'Nova família de fontes' ?></h2></div>
        <div class="card-body">
            <form method="post" action="index.php?page=fonts">
                <?= csrfField() ?>
                <input type="hidden" name="_action" value="font_family_save">
                <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
                <div class="field-row">
                    <div class="field">
                        <label>Nome da fonte</label>
                        <input type="text" name="name" value="<?= e($editing['name'] ?? '') ?>" placeholder="Ex.: DM Sans" required>
                    </div>
                    <div class="field">
                        <label>Categoria</label>
                        <select name="category">
                            <?php foreach (['sans' => 'Sans-Serif', 'serif' => 'Serif', 'mono' => 'Monospace', 'display' => 'Display', 'script' => 'Script / Cursiva'] as $catVal => $catLabel): ?>
                                <option value="<?= $catVal ?>" <?= ($editing['category'] ?? 'sans') === $catVal ? 'selected' : '' ?>><?= $catLabel ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>Tier</label>
                        <select name="tier">
                            <?php foreach (['free', 'plus', 'premium'] as $t): ?>
                                <option value="<?= $t ?>" <?= ($editing['tier'] ?? 'free') === $t ? 'selected' : '' ?>><?= ucfirst($t) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>Ordem</label>
                        <input type="number" name="sort_order" value="<?= (int) ($editing['sort_order'] ?? 0) ?>">
                    </div>
                </div>
                <div class="checkbox-row field">
                    <input type="checkbox" name="active" id="family_active" <?= ($editing['active'] ?? 1) ? 'checked' : '' ?>>
                    <label class="mb-0" for="family_active">Ativa (visível na API)</label>
                </div>
                <button type="submit" class="btn btn-primary">
                    <span class="material-symbols-outlined">save</span> <?= $editing ? 'Salvar alterações' : 'Criar família' ?>
                </button>
                <?php if ($editing): ?><a href="index.php?page=fonts" class="btn btn-outline">Cancelar</a><?php endif; ?>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><h2>Famílias de fontes (<?= count($families) ?>)</h2></div>
        <div class="card-body flush">
            <table class="data-table">
                <thead><tr><th>Nome</th><th>Categoria</th><th>Tier</th><th>Arquivos</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php if (!$families): ?>
                    <tr class="empty-row"><td colspan="6">Nenhuma família de fonte cadastrada.</td></tr>
                <?php endif; ?>
                <?php foreach ($families as $f): ?>
                    <?php $filesCount = count(fontFilesByFamily($f['id'])); ?>
                    <tr>
                        <td><strong><?= e($f['name']) ?></strong></td>
                        <td><?= e($f['category']) ?></td>
                        <td><span class="badge badge-<?= e($f['tier']) ?>"><?= e($f['tier']) ?></span></td>
                        <td><?= $filesCount ?> arquivo(s)</td>
                        <td><span class="badge <?= $f['active'] ? 'badge-on' : 'badge-off' ?>"><?= $f['active'] ? 'Ativa' : 'Inativa' ?></span></td>
                        <td class="actions">
                            <a href="index.php?page=fonts&family=<?= (int) $f['id'] ?>" class="btn btn-secondary btn-sm">
                                <span class="material-symbols-outlined">font_download</span> Arquivos (<?= $filesCount ?>)
                            </a>
                            <a href="index.php?page=fonts&edit=<?= (int) $f['id'] ?>" class="btn btn-outline btn-sm">Editar</a>
                            <form method="post" action="index.php?page=fonts" style="display:inline;" data-confirm="Remover esta família e TODOS os seus arquivos de fonte?">
                                <?= csrfField() ?>
                                <input type="hidden" name="_action" value="font_family_delete">
                                <input type="hidden" name="id" value="<?= (int) $f['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm">Excluir</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}
