<?php
$collectionId = (int) ($_GET['collection'] ?? 0);
$activeCollection = $collectionId > 0 ? shapeCollectionFind($collectionId) : null;

if ($activeCollection) {
    // -------------------------------------------------------------- shapes
    $shapes = shapeAssetsByCollection($activeCollection['id']);
    ?>
    <a href="index.php?page=shapes" class="btn btn-outline btn-sm" style="margin-bottom:14px;">
        <span class="material-symbols-outlined">arrow_back</span> Voltar para coleções
    </a>

    <div class="card">
        <div class="card-head">
            <h2>Pack: <?= e($activeCollection['name'] ?: $activeCollection['comment'] ?: $activeCollection['uuid']) ?></h2>
            <span class="badge badge-<?= e($activeCollection['tier']) ?>"><?= e($activeCollection['tier']) ?></span>
        </div>
        <div class="card-body">
            <form method="post" action="index.php?page=shapes" enctype="multipart/form-data">
                <?= csrfField() ?>
                <input type="hidden" name="_action" value="shape_upload">
                <input type="hidden" name="collection_id" value="<?= (int) $activeCollection['id'] ?>">
                <div class="field-row">
                    <div class="field">
                        <label>Arquivo SVG (até 2MB)</label>
                        <input type="file" name="shape" accept=".svg,image/svg+xml" required>
                    </div>
                    <div class="field">
                        <label>Comentário</label>
                        <input type="text" name="comment" placeholder="Opcional">
                    </div>
                    <div class="field">
                        <label>Tier do shape</label>
                        <select name="tier">
                            <?php foreach (['free', 'plus', 'premium'] as $t): ?>
                                <option value="<?= $t ?>" <?= $activeCollection['tier'] === $t ? 'selected' : '' ?>><?= ucfirst($t) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary"><span class="material-symbols-outlined">upload</span> Enviar shape</button>
                <div class="help-text">O SVG é sanitizado automaticamente no envio (remove &lt;script&gt;, handlers de evento e referências externas) — o vetor original é mantido, sem conversão para raster.</div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><h2>Shapes (<?= count($shapes) ?>)</h2></div>
        <div class="card-body">
            <?php if (!$shapes): ?>
                <p class="text-muted" style="font-size:13.5px;">Nenhum shape nesta coleção ainda.</p>
            <?php else: ?>
            <div class="img-grid">
                <?php foreach ($shapes as $shape): ?>
                    <div class="img-thumb">
                        <img src="<?= e($shape['file_path']) ?>" alt="<?= e($shape['comment']) ?>" loading="lazy" style="object-fit:contain; background:#f4f4f5;">
                        <div class="img-thumb-actions">
                            <form method="post" action="index.php?page=shapes&collection=<?= (int) $activeCollection['id'] ?>" data-confirm="Remover este shape?">
                                <?= csrfField() ?>
                                <input type="hidden" name="_action" value="shape_delete">
                                <input type="hidden" name="collection_id" value="<?= (int) $activeCollection['id'] ?>">
                                <input type="hidden" name="id" value="<?= (int) $shape['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm btn-icon" title="Excluir">
                                    <span class="material-symbols-outlined" style="font-size:16px;">delete</span>
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <table class="data-table" style="margin-top:16px;">
                <thead><tr><th>Arquivo</th><th>Tamanho</th><th>Tier</th><th>Comentário</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($shapes as $shape): ?>
                    <tr>
                        <td class="text-muted mono" style="font-size:12px;"><?= e($shape['uuid']) ?>.svg</td>
                        <td class="text-muted"><?= $shape['size_bytes'] !== null ? number_format((float) $shape['size_bytes'] / 1024, 1, ',', '.') . ' KB' : '—' ?></td>
                        <td><span class="badge badge-<?= e($shape['tier']) ?>"><?= e($shape['tier']) ?></span></td>
                        <td>
                            <form method="post" action="index.php?page=shapes&collection=<?= (int) $activeCollection['id'] ?>" class="d-flex gap-2">
                                <?= csrfField() ?>
                                <input type="hidden" name="_action" value="shape_update">
                                <input type="hidden" name="collection_id" value="<?= (int) $activeCollection['id'] ?>">
                                <input type="hidden" name="id" value="<?= (int) $shape['id'] ?>">
                                <input type="text" name="comment" value="<?= e($shape['comment']) ?>" style="max-width:160px;">
                                <select name="tier">
                                    <?php foreach (['free', 'plus', 'premium'] as $t): ?>
                                        <option value="<?= $t ?>" <?= $shape['tier'] === $t ? 'selected' : '' ?>><?= ucfirst($t) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn btn-secondary btn-sm">Salvar</button>
                            </form>
                        </td>
                        <td class="actions">
                            <form method="post" action="index.php?page=shapes&collection=<?= (int) $activeCollection['id'] ?>" data-confirm="Remover este shape?">
                                <?= csrfField() ?>
                                <input type="hidden" name="_action" value="shape_delete">
                                <input type="hidden" name="collection_id" value="<?= (int) $activeCollection['id'] ?>">
                                <input type="hidden" name="id" value="<?= (int) $shape['id'] ?>">
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
    // ----------------------------------------------------------- coleções
    $editId = (int) ($_GET['edit'] ?? 0);
    $editing = $editId > 0 ? shapeCollectionFind($editId) : null;
    $collections = shapeCollectionList();
    $importBaseDir = CRAFTOOLS_API_ROOT . '/assets/original/shapes';
    ?>

    <div class="card">
        <div class="card-head"><h2>Importar pack do disco</h2></div>
        <div class="card-body">
            <p class="text-muted" style="margin-bottom:12px; font-size:13.5px; line-height:1.5;">
                Escaneia <code>assets/original/shapes/</code>: cada subpasta vira uma <strong>Coleção</strong> (plano
                Gratuito) e cada arquivo <code>.svg</code> dentro dela é importado (sanitizado, sem conversão para
                raster). Reimportar reaproveita a coleção já criada e ignora arquivos já importados.
            </p>
            <?php if (!is_dir($importBaseDir)): ?>
                <div class="flash flash-error">
                    <span class="material-symbols-outlined">error</span>
                    A pasta <code>assets/original/shapes/</code> não existe no servidor ainda.
                </div>
            <?php else: ?>
                <form method="post" action="index.php?page=shapes">
                    <?= csrfField() ?>
                    <input type="hidden" name="_action" value="shapes_bulk_import">
                    <button type="submit" class="btn btn-primary">
                        <span class="material-symbols-outlined">drive_folder_upload</span> Importar tudo
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><h2><?= $editing ? 'Editar coleção' : 'Nova coleção' ?></h2></div>
        <div class="card-body">
            <form method="post" action="index.php?page=shapes">
                <?= csrfField() ?>
                <input type="hidden" name="_action" value="collection_save">
                <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
                <div class="field-row">
                    <div class="field">
                        <label>Nome</label>
                        <input type="text" name="name" value="<?= e($editing['name'] ?? '') ?>" placeholder="Ex.: pack_01">
                    </div>
                    <div class="field">
                        <label>Tier da coleção</label>
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
                <div class="field">
                    <label>Comentário / Nome de exibição</label>
                    <input type="text" name="comment" value="<?= e($editing['comment'] ?? '') ?>">
                </div>
                <div class="checkbox-row field">
                    <input type="checkbox" name="active" id="col_active" <?= ($editing['active'] ?? 1) ? 'checked' : '' ?>>
                    <label class="mb-0" for="col_active">Ativa (visível na API)</label>
                </div>
                <button type="submit" class="btn btn-primary">
                    <span class="material-symbols-outlined">save</span> <?= $editing ? 'Salvar alterações' : 'Criar coleção' ?>
                </button>
                <?php if ($editing): ?><a href="index.php?page=shapes" class="btn btn-outline">Cancelar</a><?php endif; ?>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><h2>Coleções (<?= count($collections) ?>)</h2></div>
        <div class="card-body flush">
            <table class="data-table">
                <thead><tr><th>Coleção</th><th>Tier</th><th>Shapes</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php if (!$collections): ?>
                    <tr class="empty-row"><td colspan="5">Nenhuma coleção cadastrada.</td></tr>
                <?php endif; ?>
                <?php foreach ($collections as $c): ?>
                    <?php $shapeCount = count(shapeAssetsByCollection($c['id'])); ?>
                    <tr>
                        <td><?= e($c['name'] ?: $c['comment'] ?: $c['uuid']) ?></td>
                        <td><span class="badge badge-<?= e($c['tier']) ?>"><?= e($c['tier']) ?></span></td>
                        <td><?= $shapeCount ?></td>
                        <td><span class="badge <?= $c['active'] ? 'badge-on' : 'badge-off' ?>"><?= $c['active'] ? 'Ativa' : 'Inativa' ?></span></td>
                        <td class="actions">
                            <a href="index.php?page=shapes&collection=<?= (int) $c['id'] ?>" class="btn btn-secondary btn-sm">
                                <span class="material-symbols-outlined">category</span> Shapes
                            </a>
                            <a href="index.php?page=shapes&edit=<?= (int) $c['id'] ?>" class="btn btn-outline btn-sm">Editar</a>
                            <form method="post" action="index.php?page=shapes" style="display:inline;" data-confirm="Remover esta coleção e TODOS os seus shapes? Esta ação não pode ser desfeita.">
                                <?= csrfField() ?>
                                <input type="hidden" name="_action" value="collection_delete">
                                <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
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
