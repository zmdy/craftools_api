<?php
$editId = (int) ($_GET['edit'] ?? 0);
$editing = $editId > 0 ? appUserFind($editId) : null;
$users = appUserList();
?>
<div class="card">
    <div class="card-head"><h2><?= $editing ? 'Editar usuário' : 'Novo usuário' ?></h2></div>
    <div class="card-body">
        <form method="post" action="index.php?page=users">
            <?= csrfField() ?>
            <input type="hidden" name="_action" value="save">
            <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
            <div class="field-row">
                <div class="field">
                    <label>Nome</label>
                    <input type="text" name="name" required value="<?= e($editing['name'] ?? '') ?>">
                </div>
                <div class="field">
                    <label>E-mail</label>
                    <input type="email" name="email" required value="<?= e($editing['email'] ?? '') ?>">
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
                    <label>Status</label>
                    <select name="status">
                        <?php foreach (['active' => 'Ativo', 'suspended' => 'Suspenso'] as $val => $label): ?>
                            <option value="<?= $val ?>" <?= ($editing['status'] ?? 'active') === $val ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="field">
                <label>Notas</label>
                <textarea name="notes" rows="2"><?= e($editing['notes'] ?? '') ?></textarea>
            </div>
            <button type="submit" class="btn btn-primary">
                <span class="material-symbols-outlined">save</span> <?= $editing ? 'Salvar alterações' : 'Criar usuário' ?>
            </button>
            <?php if ($editing): ?>
                <a href="index.php?page=users" class="btn btn-outline">Cancelar</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-head"><h2>Usuários cadastrados (<?= count($users) ?>)</h2></div>
    <div class="card-body flush">
        <table class="data-table">
            <thead><tr><th>Nome</th><th>E-mail</th><th>Tier</th><th>Status</th><th>Criado em</th><th></th></tr></thead>
            <tbody>
            <?php if (!$users): ?>
                <tr class="empty-row"><td colspan="6">Nenhum usuário cadastrado.</td></tr>
            <?php endif; ?>
            <?php foreach ($users as $u): ?>
                <tr>
                    <td><?= e($u['name']) ?></td>
                    <td><?= e($u['email']) ?></td>
                    <td><span class="badge badge-<?= e($u['tier']) ?>"><?= e($u['tier']) ?></span></td>
                    <td><span class="badge <?= $u['status'] === 'active' ? 'badge-on' : 'badge-off' ?>"><?= $u['status'] === 'active' ? 'Ativo' : 'Suspenso' ?></span></td>
                    <td class="text-muted" style="font-size:12px;"><?= e($u['created_at']) ?></td>
                    <td class="actions">
                        <a href="index.php?page=users&edit=<?= (int) $u['id'] ?>" class="btn btn-outline btn-sm">Editar</a>
                        <form method="post" action="index.php?page=users" style="display:inline;" data-confirm="Remover este usuário? Os tokens vinculados ficarão sem dono.">
                            <?= csrfField() ?>
                            <input type="hidden" name="_action" value="delete">
                            <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm">Excluir</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
