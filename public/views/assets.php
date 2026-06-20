<?php
$collectionId = (int) ($_GET['collection'] ?? 0);
$activeCollection = $collectionId > 0 ? assetCollectionFind($collectionId) : null;

if ($activeCollection) {
    // ------------------------------------------------------------- imagens
    $images = assetImagesByCollection($activeCollection['id']);
    ?>
    <a href="index.php?page=assets" class="btn btn-outline btn-sm" style="margin-bottom:14px;">
        <span class="material-symbols-outlined">arrow_back</span> Voltar para coleções
    </a>

    <div class="card">
        <div class="card-head">
            <h2>
                <?= $activeCollection['type'] === 'background' ? 'Fundo' : 'Overlay' ?>:
                <?= e($activeCollection['comment'] ?: $activeCollection['uuid']) ?>
            </h2>
            <span class="badge badge-<?= e($activeCollection['tier']) ?>"><?= e($activeCollection['tier']) ?></span>
        </div>
        <div class="card-body">
            <form method="post" action="index.php?page=assets" enctype="multipart/form-data">
                <?= csrfField() ?>
                <input type="hidden" name="_action" value="image_upload">
                <input type="hidden" name="collection_id" value="<?= (int) $activeCollection['id'] ?>">
                <div class="field-row">
                    <div class="field">
                        <label>Arquivo de imagem (JPEG/PNG/WebP, até 15MB)</label>
                        <input type="file" name="image" accept="image/jpeg,image/png,image/webp" required>
                    </div>
                    <div class="field">
                        <label>Comentário</label>
                        <input type="text" name="comment" placeholder="Opcional">
                    </div>
                    <div class="field">
                        <label>Tier da imagem</label>
                        <select name="tier">
                            <?php foreach (['free', 'plus', 'premium'] as $t): ?>
                                <option value="<?= $t ?>" <?= $activeCollection['tier'] === $t ? 'selected' : '' ?>><?= ucfirst($t) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary"><span class="material-symbols-outlined">upload</span> Enviar imagem</button>
                <div class="help-text">A imagem é convertida automaticamente para WebP (qualidade 82, largura máx. 2000px) — isso também remove qualquer metadado/EXIF.</div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><h2>Imagens (<?= count($images) ?>)</h2></div>
        <div class="card-body">
            <?php if (!$images): ?>
                <p class="text-muted" style="font-size:13.5px;">Nenhuma imagem nesta coleção ainda.</p>
            <?php else: ?>
            <div class="img-grid">
                <?php foreach ($images as $img): ?>
                    <div class="img-thumb">
                        <img src="<?= e($img['file_path']) ?>" alt="<?= e($img['comment']) ?>" loading="lazy">
                        <div class="img-thumb-actions">
                            <form method="post" action="index.php?page=assets&collection=<?= (int) $activeCollection['id'] ?>" data-confirm="Remover esta imagem?">
                                <?= csrfField() ?>
                                <input type="hidden" name="_action" value="image_delete">
                                <input type="hidden" name="collection_id" value="<?= (int) $activeCollection['id'] ?>">
                                <input type="hidden" name="id" value="<?= (int) $img['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm btn-icon" title="Excluir">
                                    <span class="material-symbols-outlined" style="font-size:16px;">delete</span>
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <table class="data-table" style="margin-top:16px;">
                <thead><tr><th>Arquivo</th><th>Dimensões</th><th>Tier</th><th>Comentário</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($images as $img): ?>
                    <tr>
                        <td class="text-muted mono" style="font-size:12px;"><?= e($img['uuid']) ?>.webp</td>
                        <td class="text-muted"><?= (int) $img['width'] ?>×<?= (int) $img['height'] ?></td>
                        <td><span class="badge badge-<?= e($img['tier']) ?>"><?= e($img['tier']) ?></span></td>
                        <td>
                            <form method="post" action="index.php?page=assets&collection=<?= (int) $activeCollection['id'] ?>" class="d-flex gap-2">
                                <?= csrfField() ?>
                                <input type="hidden" name="_action" value="image_update">
                                <input type="hidden" name="collection_id" value="<?= (int) $activeCollection['id'] ?>">
                                <input type="hidden" name="id" value="<?= (int) $img['id'] ?>">
                                <input type="text" name="comment" value="<?= e($img['comment']) ?>" style="max-width:160px;">
                                <select name="tier">
                                    <?php foreach (['free', 'plus', 'premium'] as $t): ?>
                                        <option value="<?= $t ?>" <?= $img['tier'] === $t ? 'selected' : '' ?>><?= ucfirst($t) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn btn-secondary btn-sm">Salvar</button>
                            </form>
                        </td>
                        <td class="actions">
                            <form method="post" action="index.php?page=assets&collection=<?= (int) $activeCollection['id'] ?>" data-confirm="Remover esta imagem?">
                                <?= csrfField() ?>
                                <input type="hidden" name="_action" value="image_delete">
                                <input type="hidden" name="collection_id" value="<?= (int) $activeCollection['id'] ?>">
                                <input type="hidden" name="id" value="<?= (int) $img['id'] ?>">
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
    // ---------------------------------------------------------- coleções
    $editId = (int) ($_GET['edit'] ?? 0);
    $editing = $editId > 0 ? assetCollectionFind($editId) : null;
    $collections = assetCollectionList();
    ?>

    <div class="card">
        <div class="card-head"><h2><?= $editing ? 'Editar coleção' : 'Nova coleção' ?></h2></div>
        <div class="card-body">
            <form method="post" action="index.php?page=assets">
                <?= csrfField() ?>
                <input type="hidden" name="_action" value="collection_save">
                <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
                <div class="field-row">
                    <div class="field">
                        <label>Tipo</label>
                        <select name="type">
                            <option value="background" <?= ($editing['type'] ?? '') === 'background' ? 'selected' : '' ?>>Fundo (background)</option>
                            <option value="overlay" <?= ($editing['type'] ?? '') === 'overlay' ? 'selected' : '' ?>>Overlay</option>
                        </select>
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
                <div class="field">
                    <label>Caminho original (referência interna, opcional)</label>
                    <input type="text" name="original_path" value="<?= e($editing['original_path'] ?? '') ?>">
                </div>
                <div class="checkbox-row field">
                    <input type="checkbox" name="active" id="col_active" <?= ($editing['active'] ?? 1) ? 'checked' : '' ?>>
                    <label class="mb-0" for="col_active">Ativa (visível na API)</label>
                </div>
                <button type="submit" class="btn btn-primary">
                    <span class="material-symbols-outlined">save</span> <?= $editing ? 'Salvar alterações' : 'Criar coleção' ?>
                </button>
                <?php if ($editing): ?><a href="index.php?page=assets" class="btn btn-outline">Cancelar</a><?php endif; ?>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><h2>Coleções (<?= count($collections) ?>)</h2></div>
        <div class="card-body flush">
            <table class="data-table">
                <thead><tr><th>Coleção</th><th>Tipo</th><th>Tier</th><th>Imagens</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php if (!$collections): ?>
                    <tr class="empty-row"><td colspan="6">Nenhuma coleção cadastrada.</td></tr>
                <?php endif; ?>
                <?php foreach ($collections as $c): ?>
                    <?php $imgCount = count(assetImagesByCollection($c['id'])); ?>
                    <tr>
                        <td><?= e($c['comment'] ?: $c['uuid']) ?></td>
                        <td class="text-muted"><?= $c['type'] === 'background' ? 'Fundo' : 'Overlay' ?></td>
                        <td><span class="badge badge-<?= e($c['tier']) ?>"><?= e($c['tier']) ?></span></td>
                        <td><?= $imgCount ?></td>
                        <td><span class="badge <?= $c['active'] ? 'badge-on' : 'badge-off' ?>"><?= $c['active'] ? 'Ativa' : 'Inativa' ?></span></td>
                        <td class="actions">
                            <a href="index.php?page=assets&collection=<?= (int) $c['id'] ?>" class="btn btn-secondary btn-sm">
                                <span class="material-symbols-outlined">image</span> Imagens
                            </a>
                            <a href="index.php?page=assets&edit=<?= (int) $c['id'] ?>" class="btn btn-outline btn-sm">Editar</a>
                            <form method="post" action="index.php?page=assets" style="display:inline;" data-confirm="Remover esta coleção e TODAS as suas imagens? Esta ação não pode ser desfeita.">
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
