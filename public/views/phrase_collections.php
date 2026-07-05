<?php
$editId  = (int) ($_GET['edit'] ?? 0);
$editing = $editId > 0 ? phraseCollectionFind($editId) : null;
$rows    = phraseCollectionList();
?>
<div class="card">
    <div class="card-head"><h2><?= $editing ? 'Editar coleção' : 'Nova coleção' ?></h2></div>
    <div class="card-body">
        <form method="post" action="index.php?page=phrase_collections">
            <?= csrfField() ?>
            <input type="hidden" name="_action" value="save">
            <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
            <div class="field-row">
                <div class="field" style="flex:1;">
                    <label>Nome</label>
                    <input type="text" name="name" value="<?= e($editing['name'] ?? '') ?>" placeholder="Ex: Ano Novo 2026" required>
                </div>
                <div class="field" style="flex:2;">
                    <label>Descrição <small class="text-muted">(opcional)</small></label>
                    <input type="text" name="description" value="<?= e($editing['description'] ?? '') ?>" placeholder="Ex: Frases usadas na agenda de fim de ano">
                </div>
            </div>
            <?php if ($editing): ?>
            <div class="checkbox-row field">
                <input type="checkbox" name="active" id="pc_active" <?= ($editing['active'] ?? 1) ? 'checked' : '' ?>>
                <label class="mb-0" for="pc_active">Ativa (aparece nos filtros e na API)</label>
            </div>
            <?php endif; ?>
            <button type="submit" class="btn btn-primary">
                <span class="material-symbols-outlined">save</span> <?= $editing ? 'Salvar alterações' : 'Criar coleção' ?>
            </button>
            <?php if ($editing): ?><a href="index.php?page=phrase_collections" class="btn btn-outline">Cancelar</a><?php endif; ?>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <h2>Coleções cadastradas (<?= count($rows) ?>)</h2>
        <a href="index.php?page=phrases" class="btn btn-outline btn-sm" style="margin-left:auto;">
            <span class="material-symbols-outlined" style="font-size:15px;">format_quote</span> Ver frases
        </a>
    </div>
    <div class="card-body flush">
        <table class="data-table">
            <thead><tr><th>Nome</th><th>Descrição</th><th>Frases</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr class="empty-row"><td colspan="5">Nenhuma coleção cadastrada.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= e($r['name']) ?></td>
                    <td class="text-muted"><?= e($r['description'] ?: '—') ?></td>
                    <td class="text-muted"><?= (int) $r['phrase_count'] ?></td>
                    <td><span class="badge <?= $r['active'] ? 'badge-free' : '' ?>"><?= $r['active'] ? 'Ativa' : 'Inativa' ?></span></td>
                    <td class="actions">
                        <a href="index.php?page=phrase_collections&edit=<?= (int) $r['id'] ?>" class="btn btn-outline btn-sm">Editar</a>
                        <form method="post" action="index.php?page=phrase_collections" style="display:inline;" data-confirm="Remover esta coleção? As frases não serão excluídas, só desvinculadas dela.">
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
