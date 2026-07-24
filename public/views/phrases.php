<?php
$editId          = (int) ($_GET['edit'] ?? 0);
$editing         = $editId > 0 ? phraseFind($editId) : null;
$filterCategory  = (string) ($_GET['category'] ?? '');
$filterAuthor    = (string) ($_GET['author'] ?? '');
$filterCollection = (string) ($_GET['collection'] ?? '');

// Pagination -- previously phraseList() had no LIMIT, so this view fetched
// and rendered EVERY matching phrase in one page load.
$listPage       = max(1, (int) ($_GET['lp'] ?? 1));
$listPerPage    = 50;
$listOffset     = ($listPage - 1) * $listPerPage;
$rows            = phraseList(
    $filterCategory !== '' ? $filterCategory : null,
    $filterAuthor !== '' ? $filterAuthor : null,
    $filterCollection !== '' ? $filterCollection : null,
    $listPerPage,
    $listOffset
);
$listTotal      = phraseListCount(
    $filterCategory !== '' ? $filterCategory : null,
    $filterAuthor !== '' ? $filterAuthor : null,
    $filterCollection !== '' ? $filterCollection : null
);
$listTotalPages = max(1, (int) ceil($listTotal / $listPerPage));
$baseQs         = http_build_query(array_filter([
    'page'       => 'phrases',
    'category'   => $filterCategory,
    'author'     => $filterAuthor,
    'collection' => $filterCollection,
], static function ($v) { return $v !== ''; }));

$categories      = phraseCategories();
$authors         = phraseAuthors();
$collectionNames = phraseCollectionNames();
$editingCollection = $editing ? phraseCollectionForPhrase((int) $editing['id']) : null;

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
                    <label>Coleção <small class="text-muted">(tema/conjunto, opcional)</small></label>
                    <input type="text" name="collection" value="<?= e($editingCollection['name'] ?? '') ?>" placeholder="Ex: Ano Novo 2026" list="collection-suggestions">
                    <datalist id="collection-suggestions">
                        <?php foreach ($collectionNames as $c): ?><option value="<?= e($c) ?>"><?php endforeach; ?>
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
        <h2>Frases cadastradas (<?= number_format($listTotal, 0, ',', '.') ?>)</h2>
        <div class="d-flex gap-2" style="flex-wrap:wrap; align-items:center;">
            <a href="index.php?page=phrase_collections" class="btn btn-outline btn-sm">
                <span class="material-symbols-outlined" style="font-size:15px;">sell</span> Coleções
            </a>
            <a href="index.php?page=phrases_csv_import" class="btn btn-outline btn-sm">
                <span class="material-symbols-outlined" style="font-size:15px;">upload_file</span> Importar CSV
            </a>
        </div>
    </div>

    <div class="card-body" style="border-bottom:1px solid var(--border);">
        <form method="get" action="index.php" class="d-flex gap-2" style="flex-wrap:wrap; align-items:flex-end;">
            <input type="hidden" name="page" value="phrases">
            <?php if ($collectionNames): ?>
            <div class="field">
                <label>Coleção</label>
                <select name="collection" data-autosubmit>
                    <option value="">Todas as coleções</option>
                    <?php foreach ($collectionNames as $c): ?>
                        <option value="<?= e($c) ?>" <?= $filterCollection === $c ? 'selected' : '' ?>><?= e($c) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <?php if ($categories): ?>
            <div class="field">
                <label>Categoria</label>
                <select name="category" data-autosubmit>
                    <option value="">Todas as categorias</option>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= e($c) ?>" <?= $filterCategory === $c ? 'selected' : '' ?>><?= e($c) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <?php if ($authors): ?>
            <div class="field">
                <label>Autor</label>
                <select name="author" data-autosubmit>
                    <option value="">Todos os autores</option>
                    <?php foreach ($authors as $a): ?>
                        <option value="<?= e($a) ?>" <?= $filterAuthor === $a ? 'selected' : '' ?>><?= e($a) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <button type="submit" class="btn btn-outline btn-sm">Filtrar</button>
            <?php if ($filterCategory !== '' || $filterAuthor !== '' || $filterCollection !== ''): ?>
                <a href="index.php?page=phrases" class="btn btn-outline btn-sm">Limpar filtros</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="card-body" style="background:var(--bg-input); border-bottom:1px solid var(--border);">
        <form id="bulk-edit-form" method="post" action="index.php?page=phrases">
            <?= csrfField() ?>
            <input type="hidden" name="_action" value="bulk_update">
            <strong style="font-size:13px; display:block; margin-bottom:10px;">Alterar em massa</strong>
            <div class="field-row" style="align-items:flex-end; flex-wrap:wrap;">
                <div class="field">
                    <label class="d-flex" style="align-items:center; gap:6px; font-weight:normal;">
                        <input type="checkbox" name="apply_tier" value="1"> Tier
                    </label>
                    <select name="tier">
                        <option value="free">Free</option>
                        <option value="plus">Plus</option>
                        <option value="premium">Premium</option>
                    </select>
                </div>
                <div class="field">
                    <label class="d-flex" style="align-items:center; gap:6px; font-weight:normal;">
                        <input type="checkbox" name="apply_language" value="1"> Idioma
                    </label>
                    <select name="language">
                        <option value="pt-br">Português (BR)</option>
                        <option value="en">English</option>
                        <option value="es">Español</option>
                    </select>
                </div>
                <div class="field">
                    <label class="d-flex" style="align-items:center; gap:6px; font-weight:normal;">
                        <input type="checkbox" name="apply_category" value="1"> Categorias
                    </label>
                    <input type="text" name="category" placeholder="Ex: motivacional, amor">
                </div>
                <div class="field">
                    <label class="d-flex" style="align-items:center; gap:6px; font-weight:normal;">
                        <input type="checkbox" name="apply_collection" value="1"> Coleção
                    </label>
                    <input type="text" name="collection" placeholder="Vazio remove a coleção" list="collection-suggestions">
                </div>
                <div class="field">
                    <button type="submit" class="btn btn-primary" data-confirm="Aplicar as alterações a todas as frases marcadas na tabela abaixo?">
                        <span class="material-symbols-outlined">done_all</span> Aplicar às selecionadas
                    </button>
                </div>
            </div>
            <div class="help-text">Marque a caixa ao lado de cada campo que deseja alterar e selecione as frases na tabela abaixo.</div>
        </form>
    </div>

    <div class="card-body flush">
        <table class="data-table">
            <thead><tr><th><input type="checkbox" id="ph-select-all"></th><th>Frase</th><th>Autor</th><th>Coleção</th><th>Categorias</th><th>Idioma</th><th>Tier</th><th></th></tr></thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr class="empty-row"><td colspan="8">Nenhuma frase cadastrada.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r): ?>
                <?php
                    $cats = array_values(array_filter(array_map('trim', explode(',', $r['category'] ?? ''))));
                ?>
                <tr>
                    <td><input type="checkbox" name="ids[]" value="<?= (int) $r['id'] ?>" form="bulk-edit-form" class="ph-row-check"></td>
                    <td style="max-width:340px;"><?= e(mb_strimwidth($r['phrase'], 0, 120, '…')) ?></td>
                    <td class="text-muted"><?= e($r['author'] ?: '—') ?></td>
                    <td class="text-muted">
                        <?php if (!empty($r['collection_name'])): ?>
                            <span class="badge" style="background:rgba(99,102,241,.1);color:#6366f1;"><?= e($r['collection_name']) ?></span>
                        <?php else: ?>—<?php endif; ?>
                    </td>
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
    <?php if ($listTotalPages > 1): ?>
    <div class="card-body d-flex" style="justify-content:center; gap:6px; align-items:center; border-top:1px solid var(--border);">
        <a href="index.php?<?= $baseQs ?>&lp=<?= max(1, $listPage - 1) ?>" class="btn btn-outline btn-sm" <?= $listPage <= 1 ? 'style="pointer-events:none;opacity:.4;"' : '' ?>>Anterior</a>
        <span class="text-muted" style="font-size:12.5px;">Página <?= $listPage ?> de <?= $listTotalPages ?></span>
        <a href="index.php?<?= $baseQs ?>&lp=<?= min($listTotalPages, $listPage + 1) ?>" class="btn btn-outline btn-sm" <?= $listPage >= $listTotalPages ? 'style="pointer-events:none;opacity:.4;"' : '' ?>>Próxima</a>
    </div>
    <?php endif; ?>
</div>

<script src="assets/phrases-bulk.js"></script>
