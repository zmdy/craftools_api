<?php
$editId          = (int) ($_GET['edit'] ?? 0);
$editing         = $editId > 0 ? phraseFind($editId) : null;
$filterCategory  = (string) ($_GET['category'] ?? '');
$filterAuthor    = (string) ($_GET['author'] ?? '');
$rows            = phraseList($filterCategory !== '' ? $filterCategory : null, $filterAuthor !== '' ? $filterAuthor : null);
$categories      = phraseCategories();
$authors         = phraseAuthors();

// Converte o campo category (CSV) em string legível para o formulário de edição
$editingCategoryDisplay = '';
if ($editing) {
    $editingCategoryDisplay = $editing['category'] ?? '';
}
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
                    <input type="text" name="author" value="<?= e($editing['author'] ?? '') ?>" placeholder="Ex: Clarice Lispector" list="author-suggestions">
                    <datalist id="author-suggestions">
                        <?php foreach ($authors as $a): ?><option value="<?= e($a) ?>"><?php endforeach; ?>
                    </datalist>
                </div>
                <div class="field">
                    <label>Categorias <small class="text-muted">(separe por vírgula)</small></label>
                    <input type="text" name="category" value="<?= e($editingCategoryDisplay) ?>" placeholder="Ex: motivacional, amor, família">
                    <div class="help-text">Múltiplas categorias separadas por vírgula.</div>
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
        <div class="d-flex gap-2" style="flex-wrap:wrap; align-items:center;">
            <form method="get" action="index.php" class="d-flex gap-2" style="flex-wrap:wrap;">
                <input type="hidden" name="page" value="phrases">
                <?php if ($categories): ?>
                <select name="category" data-autosubmit>
                    <option value="">Todas as categorias</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= e($c) ?>" <?= $filterCategory === $c ? 'selected' : '' ?>><?= e($c) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
                <?php if ($authors): ?>
                <select name="author" data-autosubmit>
                    <option value="">Todos os autores</option>
                    <?php foreach ($authors as $a): ?>
                        <option value="<?= e($a) ?>" <?= $filterAuthor === $a ? 'selected' : '' ?>><?= e($a) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
                <?php if ($filterCategory !== '' || $filterAuthor !== ''): ?>
                    <a href="index.php?page=phrases" class="btn btn-outline btn-sm">Limpar filtros</a>
                <?php endif; ?>
            </form>
            <a href="index.php?page=phrases_csv_import" class="btn btn-outline btn-sm" style="margin-left:auto;">
                <span class="material-symbols-outlined" style="font-size:15px;">upload_file</span> Importar CSV
            </a>
        </div>
    </div>
    <div class="card-body flush">
        <table class="data-table">
            <thead><tr><th>Frase</th><th>Autor</th><th>Categorias</th><th>Idioma</th><th>Tier</th><th></th></tr></thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr class="empty-row"><td colspan="6">Nenhuma frase cadastrada.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r): ?>
                <?php
                    $cats = array_values(array_filter(array_map('trim', explode(',', $r['category'] ?? ''))));
                ?>
                <tr>
                    <td style="max-width:340px;"><?= e(mb_strimwidth($r['phrase'], 0, 120, '…')) ?></td>
                    <td class="text-muted"><?= e($r['author'] ?: '—') ?></td>
                    <td class="text-muted">
                        <?php if ($cats): ?>
                            <?php foreach ($cats as $cat): ?>
                                <span class="badge" style="background:rgba(249,115,22,.1);color:#ea580c;margin-right:3px;"><?= e($cat) ?></span>
                            <?php endforeach; ?>
                        <?php else: ?>—<?php endif; ?>
                    </td>
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
