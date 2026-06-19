<?php
$editId = (int) ($_GET['edit'] ?? 0);
$editing = $editId > 0 ? phraseFind($editId) : null;
$filterCategory = (string) ($_GET['category'] ?? '');
$rows = phraseList();
if ($filterCategory !== '') {
    $rows = array_values(array_filter($rows, function ($r) use ($filterCategory) {
        return $r['category'] === $filterCategory;
    }));
}
$categories = phraseCategories();
?>
<div class="card">
    <div class="card-head"><h2><?= $editing ? 'Editar frase' : 'Nova frase' ?></h2></div>
    <div class="card-body">
        <form method="post" action="index.php?page=phrases">
            <?= csrfField() ?>
            <input type="hidden" name="_action" value="save">
            <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
            <div class="field">
                <label>Frase</label>
                <textarea name="phrase" rows="2" required><?= e($editing['phrase'] ?? '') ?></textarea>
            </div>
            <div class="field-row">
                <div class="field">
                    <label>Autor</label>
                    <input type="text" name="author" value="<?= e($editing['author'] ?? '') ?>" placeholder="Ex: Clarice Lispector">
                </div>
                <div class="field">
                    <label>Categoria</label>
                    <input type="text" name="category" value="<?= e($editing['category'] ?? '') ?>" placeholder="Ex: motivacional, amor, família" list="category-suggestions">
                    <datalist id="category-suggestions">
                        <?php foreach ($categories as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?>
                    </datalist>
                </div>
                <div class="field">
                    <label>Idioma</label>
                    <select name="language">
                        <?php foreach (['pt-br' => 'Português (BR)', 'en' => 'English', 'es' => 'Español'] as $val => $label): ?>
                            <option value="<?= $val ?>" <?= ($editing['language'] ?? 'pt-br') === $val ? 'selected' : '' ?>><?= $label ?></option>
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
            </div>
            <div class="checkbox-row field">
                <input type="checkbox" name="active" id="ph_active" <?= ($editing['active'] ?? 1) ? 'checked' : '' ?>>
                <label class="mb-0" for="ph_active">Ativa (visível na API)</label>
            </div>
            <button type="submit" class="btn btn-primary">
                <span class="material-symbols-outlined">save</span> <?= $editing ? 'Salvar alterações' : 'Criar frase' ?>
            </button>
            <?php if ($editing): ?><a href="index.php?page=phrases" class="btn btn-outline">Cancelar</a><?php endif; ?>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <h2>Frases cadastradas (<?= count($rows) ?>)</h2>
        <?php if ($categories): ?>
        <form method="get" action="index.php" class="d-flex gap-2">
            <input type="hidden" name="page" value="phrases">
            <select name="category" data-autosubmit>
                <option value="">Todas as categorias</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?= e($c) ?>" <?= $filterCategory === $c ? 'selected' : '' ?>><?= e($c) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php endif; ?>
    </div>
    <div class="card-body flush">
        <table class="data-table">
            <thead><tr><th>Frase</th><th>Autor</th><th>Categoria</th><th>Idioma</th><th>Tier</th><th></th></tr></thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr class="empty-row"><td colspan="6">Nenhuma frase cadastrada.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td style="max-width:340px;"><?= e(mb_strimwidth($r['phrase'], 0, 120, '…')) ?></td>
                    <td class="text-muted"><?= e($r['author'] ?: '—') ?></td>
                    <td class="text-muted"><?= e($r['category'] ?: '—') ?></td>
                    <td class="text-muted"><?= e($r['language']) ?></td>
                    <td><span class="badge badge-<?= e($r['tier']) ?>"><?= e($r['tier']) ?></span></td>
                    <td class="actions">
                        <a href="index.php?page=phrases&edit=<?= (int) $r['id'] ?>" class="btn btn-outline btn-sm">Editar</a>
                        <form method="post" action="index.php?page=phrases" style="display:inline;" data-confirm="Remover esta frase?">
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
