<?php
$tokens = apiTokenList();
$usersForSelect = appUserList();
$revealToken = $_SESSION['reveal_token'] ?? null;
unset($_SESSION['reveal_token']);
?>
<?php if ($revealToken): ?>
<div class="card">
    <div class="card-head"><h2>Novo token criado</h2></div>
    <div class="card-body">
        <p class="help-text" style="margin-bottom:8px;">
            Copie agora: por segurança, apenas o hash deste token é guardado — ele não poderá ser exibido de novo.
        </p>
        <div class="token-reveal d-flex flex-between">
            <span id="new-token-value"><?= e($revealToken) ?></span>
            <button type="button" class="btn btn-sm btn-secondary" data-copy="#new-token-value">Copiar</button>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-head"><h2>Novo token de API</h2></div>
    <div class="card-body">
        <form method="post" action="index.php?page=tokens">
            <?= csrfField() ?>
            <input type="hidden" name="_action" value="create">
            <div class="field-row">
                <div class="field">
                    <label>Rótulo</label>
                    <input type="text" name="label" placeholder="Ex: App Android, Cliente X">
                </div>
                <div class="field">
                    <label>Usuário vinculado (opcional)</label>
                    <select name="user_id">
                        <option value="">— Sem vínculo —</option>
                        <?php foreach ($usersForSelect as $u): ?>
                            <option value="<?= (int) $u['id'] ?>"><?= e($u['name']) ?> (<?= e($u['email']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Tier</label>
                    <select name="tier">
                        <?php foreach (['free', 'plus', 'premium'] as $t): ?>
                            <option value="<?= $t ?>"><?= ucfirst($t) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Expira em (opcional)</label>
                    <input type="date" name="expires_at">
                </div>
            </div>
            <button type="submit" class="btn btn-primary"><span class="material-symbols-outlined">add</span> Gerar token</button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-head"><h2>Tokens emitidos (<?= count($tokens) ?>)</h2></div>
    <div class="card-body flush">
        <table class="data-table">
            <thead><tr><th>Rótulo</th><th>Prefixo</th><th>Usuário</th><th>Tier</th><th>Status</th><th>Último uso</th><th></th></tr></thead>
            <tbody>
            <?php if (!$tokens): ?>
                <tr class="empty-row"><td colspan="7">Nenhum token emitido.</td></tr>
            <?php endif; ?>
            <?php foreach ($tokens as $t): ?>
                <tr>
                    <td><?= e($t['label']) ?></td>
                    <td class="mono"><?= e($t['token_prefix']) ?>…</td>
                    <td class="text-muted"><?= e($t['user_name'] ?? '—') ?></td>
                    <td><span class="badge badge-<?= e($t['tier']) ?>"><?= e($t['tier']) ?></span></td>
                    <td><span class="badge <?= $t['active'] ? 'badge-on' : 'badge-off' ?>"><?= $t['active'] ? 'Ativo' : 'Desativado' ?></span></td>
                    <td class="text-muted" style="font-size:12px;"><?= e($t['last_used_at'] ?? 'Nunca usado') ?></td>
                    <td class="actions">
                        <form method="post" action="index.php?page=tokens" style="display:inline;">
                            <?= csrfField() ?>
                            <input type="hidden" name="_action" value="toggle">
                            <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                            <input type="hidden" name="active" value="<?= $t['active'] ? '0' : '1' ?>">
                            <button type="submit" class="btn btn-outline btn-sm"><?= $t['active'] ? 'Desativar' : 'Ativar' ?></button>
                        </form>
                        <form method="post" action="index.php?page=tokens" style="display:inline;" data-confirm="Excluir este token? Apps que o usam perderão acesso imediatamente.">
                            <?= csrfField() ?>
                            <input type="hidden" name="_action" value="delete">
                            <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-sm">Excluir</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
