<?php
$editId = (int) ($_GET['edit'] ?? 0);
$editing = $editId > 0 ? albumTemplateFind($editId) : null;
$rows = albumTemplateList();
?>
<div class="card">
    <div class="card-head"><h2><?= $editing ? 'Editar template de álbum' : 'Novo template de álbum' ?></h2></div>
    <div class="card-body">
        <form method="post" action="index.php?page=album_templates">
            <?= csrfField() ?>
            <input type="hidden" name="_action" value="save">
            <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
            <div class="field-row">
                <div class="field">
                    <label>Nome</label>
                    <input type="text" name="name" required value="<?= e($editing['name'] ?? '') ?>">
                </div>
                <div class="field">
                    <label>Estilo de capa</label>
                    <input type="text" name="cover_style" value="<?= e($editing['cover_style'] ?? '') ?>" placeholder="Ex: capa-dura, capa-acrilico">
                </div>
                <div class="field">
                    <label>Nº de páginas</label>
                    <input type="number" name="page_count" min="1" value="<?= (int) ($editing['page_count'] ?? 20) ?>">
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
            <div class="field">
                <label>Descrição</label>
                <textarea name="description" rows="2"><?= e($editing['description'] ?? '') ?></textarea>
            </div>
            <div class="field-row">
                <div class="field">
                    <label>URL da miniatura (opcional)</label>
                    <input type="text" name="thumbnail_url" value="<?= e($editing['thumbnail_url'] ?? '') ?>">
                </div>
                <div class="field">
                    <label>Ordem</label>
                    <input type="number" name="sort_order" value="<?= (int) ($editing['sort_order'] ?? 0) ?>">
                </div>
            </div>
            <div class="field">
                <label>Layout (JSON)</label>
                <textarea name="layout_json" rows="4"><?= e($editing['layout_json'] ?? '[]') ?></textarea>
                <div class="help-text">Estrutura livre — definirá a diagramação das páginas do álbum nas próximas versões do editor.</div>
            </div>
            <div class="checkbox-row field">
                <input type="checkbox" name="active" id="at_active" <?= ($editing['active'] ?? 1) ? 'checked' : '' ?>>
                <label class="mb-0" for="at_active">Ativo (visível na API)</label>
            </div>
            <button type="submit" class="btn btn-primary">
                <span class="material-symbols-outlined">save</span> <?= $editing ? 'Salvar alterações' : 'Criar template' ?>
            </button>
            <?php if ($editing): ?><a href="index.php?page=album_templates" class="btn btn-outline">Cancelar</a><?php endif; ?>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-head"><h2>Templates cadastrados (<?= count($rows) ?>)</h2></div>
    <div class="card-body flush">
        <table class="data-table">
            <thead><tr><th>Nome</th><th>Páginas</th><th>Tier</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr class="empty-row"><td colspan="5">Nenhum template cadastrado ainda. Esta é uma funcionalidade nova — o editor CraftTools ainda não consome este catálogo.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= e($r['name']) ?></td>
                    <td><?= (int) $r['page_count'] ?></td>
                    <td><span class="badge badge-<?= e($r['tier']) ?>"><?= e($r['tier']) ?></span></td>
                    <td><span class="badge <?= $r['active'] ? 'badge-on' : 'badge-off' ?>"><?= $r['active'] ? 'Ativo' : 'Inativo' ?></span></td>
                    <td class="actions">
                        <a href="index.php?page=album_templates&edit=<?= (int) $r['id'] ?>" class="btn btn-outline btn-sm">Editar</a>
                        <form method="post" action="index.php?page=album_templates" style="display:inline;" data-confirm="Remover este template?">
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
