<?php
$editId = (int) ($_GET['edit'] ?? 0);
$editing = $editId > 0 ? gridSizeFind($editId) : null;
$sizesLines = '';
if ($editing && !empty($editing['sizes_json'])) {
    $decoded = json_decode($editing['sizes_json'], true);
    if (is_array($decoded)) {
        $sizesLines = implode("\n", $decoded);
    }
}
$rows = gridSizeList();
?>
<div class="card">
    <div class="card-head"><h2><?= $editing ? 'Editar tamanho de grid' : 'Novo tamanho de grid' ?></h2></div>
    <div class="card-body">
        <form method="post" action="index.php?page=grid_sizes">
            <?= csrfField() ?>
            <input type="hidden" name="_action" value="save">
            <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
            <div class="field-row">
                <div class="field">
                    <label>Nome</label>
                    <input type="text" name="name" required value="<?= e($editing['name'] ?? '') ?>" placeholder="Ex: Grid 3x3">
                </div>
                <div class="field">
                    <label>Tipo</label>
                    <input type="text" name="type" value="<?= e($editing['type'] ?? '') ?>" placeholder="Ex: grid, freeform">
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
            <div class="field-row">
                <div class="field"><label>Largura célula</label><input type="text" name="cell_width" value="<?= e($editing['cell_width'] ?? '') ?>"></div>
                <div class="field"><label>Altura célula</label><input type="text" name="cell_height" value="<?= e($editing['cell_height'] ?? '') ?>"></div>
                <div class="field"><label>Padding célula</label><input type="text" name="cell_padding" value="<?= e($editing['cell_padding'] ?? '') ?>"></div>
                <div class="field"><label>Margem da página</label><input type="text" name="page_margin" value="<?= e($editing['page_margin'] ?? '') ?>"></div>
            </div>
            <div class="field-row">
                <div class="field"><label>Espaço entre células (gap)</label><input type="text" name="cell_gap" value="<?= e($editing['cell_gap'] ?? '') ?>"></div>
                <div class="field"><label>Linhas</label><input type="text" name="cell_lines" value="<?= e($editing['cell_lines'] ?? '') ?>"></div>
                <div class="field"><label>Colunas</label><input type="text" name="cell_columns" value="<?= e($editing['cell_columns'] ?? '') ?>"></div>
                <div class="field"><label>Espaçamento</label><input type="text" name="cell_spacing" value="<?= e($editing['cell_spacing'] ?? '') ?>"></div>
            </div>
            <div class="field">
                <label>Tamanhos (um por linha — ex: "20x20", "30x30")</label>
                <textarea name="sizes_lines" rows="3"><?= e($sizesLines) ?></textarea>
            </div>
            <div class="field">
                <label>Slots de célula (JSON avançado, opcional)</label>
                <textarea name="cell_slots_json" rows="3"><?= e($editing['cell_slots_json'] ?? '') ?></textarea>
                <div class="help-text">Usado por layouts customizados de GridSizes.js. Deixe vazio se não souber.</div>
            </div>
            <div class="checkbox-row field">
                <input type="checkbox" name="active" id="gs_active" <?= ($editing['active'] ?? 1) ? 'checked' : '' ?>>
                <label class="mb-0" for="gs_active">Ativo (visível na API)</label>
            </div>
            <button type="submit" class="btn btn-primary">
                <span class="material-symbols-outlined">save</span> <?= $editing ? 'Salvar alterações' : 'Criar tamanho' ?>
            </button>
            <?php if ($editing): ?><a href="index.php?page=grid_sizes" class="btn btn-outline">Cancelar</a><?php endif; ?>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-head"><h2>Tamanhos cadastrados (<?= count($rows) ?>)</h2></div>
    <div class="card-body flush">
        <table class="data-table">
            <thead><tr><th>Nome</th><th>Tipo</th><th>Tier</th><th>Ordem</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr class="empty-row"><td colspan="6">Nenhum tamanho cadastrado.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= e($r['name']) ?></td>
                    <td class="text-muted"><?= e($r['type'] ?? '—') ?></td>
                    <td><span class="badge badge-<?= e($r['tier']) ?>"><?= e($r['tier']) ?></span></td>
                    <td><?= (int) $r['sort_order'] ?></td>
                    <td><span class="badge <?= $r['active'] ? 'badge-on' : 'badge-off' ?>"><?= $r['active'] ? 'Ativo' : 'Inativo' ?></span></td>
                    <td class="actions">
                        <a href="index.php?page=grid_sizes&edit=<?= (int) $r['id'] ?>" class="btn btn-outline btn-sm">Editar</a>
                        <form method="post" action="index.php?page=grid_sizes" style="display:inline;" data-confirm="Remover este tamanho de grid?">
                            <?= csrfField() ?>
                            <input type="hidden" name="_action" value="delete">
                            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm">Excluir</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
