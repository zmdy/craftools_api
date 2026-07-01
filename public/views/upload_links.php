<?php
/**
 * upload_links.php (view) — cria e gerencia links de upload de fotos para
 * clientes. O valor em texto puro do link fica salvo em `token` (diferente de
 * api_tokens) justamente para poder ser copiado a qualquer momento, não só na
 * criação — ver comentário em database/schema.sql.
 */
$viewUuid = (string) ($_GET['view'] ?? '');
$viewLink = $viewUuid !== '' ? uploadLinkFindByUuid($viewUuid) : null;

if ($viewLink) {
    // --------------------------------------------------------- detalhe do link
    $submission = $viewLink['submission_json'] ? json_decode($viewLink['submission_json'], true) : null;
    $photoFiles = $submission['photos'] ?? [];
    ?>
    <a href="index.php?page=upload_links" class="btn btn-outline btn-sm" style="margin-bottom:14px;">
        <span class="material-symbols-outlined">arrow_back</span> Voltar para links
    </a>

    <div class="card">
        <div class="card-head">
            <h2><?= e($viewLink['client_name']) ?></h2>
            <span class="badge <?= $viewLink['status'] === 'submitted' ? 'badge-on' : 'badge-off' ?>">
                <?= $viewLink['status'] === 'submitted' ? 'Enviado' : 'Pendente' ?>
            </span>
        </div>
        <div class="card-body">
            <div class="field-row">
                <div class="field"><label>Kit</label><div><?= e($viewLink['grid_size_name'] ?? '—') ?></div></div>
                <div class="field"><label>Qtd. fotos solicitada</label><div><?= (int) $viewLink['photo_count'] ?></div></div>
                <div class="field"><label>Fotos enviadas</label><div><?= count($photoFiles) ?></div></div>
                <div class="field"><label>Enviado em</label><div><?= e($viewLink['submitted_at'] ?? '—') ?></div></div>
            </div>
            <?php if (!empty($viewLink['token'])): ?>
                <div class="field">
                    <label>Link</label>
                    <div class="token-reveal d-flex flex-between">
                        <span id="view-link-url" class="mono" style="word-break:break-all;"><?= e(uploadLinkFullUrl($viewLink['token'])) ?></span>
                        <button type="button" class="btn btn-sm btn-secondary" data-copy="#view-link-url">Copiar</button>
                    </div>
                </div>
            <?php endif; ?>
            <?php if ($viewLink['notes']): ?>
                <div class="field"><label>Observações</label><div><?= nl2br(e($viewLink['notes'])) ?></div></div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($viewLink['status'] === 'submitted' && $photoFiles): ?>
    <div class="card">
        <div class="card-head"><h2>Fotos enviadas (<?= count($photoFiles) ?>)</h2></div>
        <div class="card-body">
            <div class="img-grid">
                <?php foreach ($photoFiles as $p): ?>
                    <?php $capIdx = (string) ($p['index'] ?? ''); $cap = $submission['captions'][$capIdx] ?? null; ?>
                    <div class="img-thumb">
                        <img src="upload_link_photo.php?uuid=<?= e($viewLink['uuid']) ?>&file=<?= e($p['filename']) ?>"
                             alt="<?= e($p['originalName'] ?? '') ?>" loading="lazy">
                        <div class="img-thumb-actions">
                            <a href="upload_link_photo.php?uuid=<?= e($viewLink['uuid']) ?>&file=<?= e($p['filename']) ?>&download=1"
                               class="btn btn-secondary btn-sm btn-icon" title="Baixar" download>
                                <span class="material-symbols-outlined" style="font-size:16px;">download</span>
                            </a>
                        </div>
                        <?php if ($cap && $cap['text']): ?>
                            <div class="text-muted" style="font-size:11px;padding:4px 2px;">"<?= e($cap['text']) ?>"</div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php elseif ($viewLink['status'] !== 'submitted'): ?>
    <div class="card"><div class="card-body">
        <p class="text-muted" style="font-size:13.5px;">O cliente ainda não enviou as fotos por este link.</p>
    </div></div>
    <?php endif; ?>
    <?php
} else {
    // ------------------------------------------------------------- lista/criação
    $reveal = $_SESSION['reveal_upload_link'] ?? null;
    unset($_SESSION['reveal_upload_link']);
    $links = uploadLinkList();
    $kits = gridSizeList();
    ?>

    <?php if ($reveal): ?>
    <div class="card">
        <div class="card-head"><h2>Link criado</h2></div>
        <div class="card-body">
            <p class="help-text" style="margin-bottom:8px;">
                Copie e envie para o cliente (o link também pode ser copiado depois, a qualquer momento,
                pela lista abaixo).
            </p>
            <div class="token-reveal d-flex flex-between">
                <span id="new-link-value" class="mono" style="word-break:break-all;"><?= e($reveal) ?></span>
                <button type="button" class="btn btn-sm btn-secondary" data-copy="#new-link-value">Copiar</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-head"><h2>Novo link de upload</h2></div>
        <div class="card-body">
            <?php if (!$kits): ?>
                <p class="text-muted" style="font-size:13.5px;">
                    Cadastre pelo menos um <a href="index.php?page=grid_sizes">tamanho de grid (kit)</a> antes de criar links.
                </p>
            <?php else: ?>
            <form method="post" action="index.php?page=upload_links">
                <?= csrfField() ?>
                <input type="hidden" name="_action" value="create">
                <div class="field-row">
                    <div class="field">
                        <label>Nome do cliente</label>
                        <input type="text" name="client_name" placeholder="Ex: Maria Silva" required>
                    </div>
                    <div class="field">
                        <label>Kit</label>
                        <select name="grid_size_id" required>
                            <option value="">— Selecione —</option>
                            <?php foreach ($kits as $k): ?>
                                <option value="<?= (int) $k['id'] ?>"><?= e($k['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>Quantidade de fotos</label>
                        <input type="number" name="photo_count" min="0" max="500" value="0">
                    </div>
                </div>
                <div class="field">
                    <label>Observações (opcional)</label>
                    <textarea name="notes" rows="2" placeholder="Anotações internas sobre este pedido"></textarea>
                </div>
                <button type="submit" class="btn btn-primary"><span class="material-symbols-outlined">add_link</span> Gerar link</button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-head"><h2>Links criados (<?= count($links) ?>)</h2></div>
        <div class="card-body flush">
            <table class="data-table">
                <thead><tr><th>Cliente</th><th>Kit</th><th>Fotos</th><th>Prefixo</th><th>Status</th><th>Criado em</th><th></th></tr></thead>
                <tbody>
                <?php if (!$links): ?>
                    <tr class="empty-row"><td colspan="7">Nenhum link criado ainda.</td></tr>
                <?php endif; ?>
                <?php foreach ($links as $l): ?>
                    <tr>
                        <td><?= e($l['client_name']) ?></td>
                        <td class="text-muted"><?= e($l['grid_size_name'] ?? '—') ?></td>
                        <td class="text-muted"><?= (int) $l['photo_count'] ?></td>
                        <td class="mono"><?= e($l['token_prefix']) ?>…</td>
                        <td><span class="badge <?= $l['status'] === 'submitted' ? 'badge-on' : 'badge-off' ?>">
                            <?= $l['status'] === 'submitted' ? 'Enviado' : 'Pendente' ?>
                        </span></td>
                        <td class="text-muted" style="font-size:12px;"><?= e($l['created_at']) ?></td>
                        <td class="actions">
                            <a href="index.php?page=upload_links&view=<?= e($l['uuid']) ?>" class="btn btn-secondary btn-sm">Ver</a>
                            <?php if (!empty($l['token'])): ?>
                                <input type="text" id="link-url-<?= (int) $l['id'] ?>" value="<?= e(uploadLinkFullUrl($l['token'])) ?>" hidden>
                                <button type="button" class="btn btn-outline btn-sm" data-copy="#link-url-<?= (int) $l['id'] ?>">
                                    <span class="material-symbols-outlined" style="font-size:15px;">content_copy</span> Copiar link
                                </button>
                            <?php else: ?>
                                <!-- Link criado antes desta coluna existir -- o valor em texto puro nunca
                                     foi salvo (só o hash), então não dá para reconstruir; só regenerar. -->
                                <form method="post" action="index.php?page=upload_links" style="display:inline;" data-confirm="Gerar um novo link para este cliente? O link antigo para de funcionar.">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="_action" value="regenerate">
                                    <input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
                                    <button type="submit" class="btn btn-outline btn-sm">Gerar novo link</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($l['status'] === 'submitted'): ?>
                            <form method="post" action="index.php?page=upload_links" style="display:inline;" data-confirm="Reabrir este link para o cliente enviar de novo?">
                                <?= csrfField() ?>
                                <input type="hidden" name="_action" value="reopen">
                                <input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
                                <button type="submit" class="btn btn-outline btn-sm">Reabrir</button>
                            </form>
                            <?php endif; ?>
                            <form method="post" action="index.php?page=upload_links" style="display:inline;" data-confirm="Excluir este link e as fotos enviadas? Esta ação não pode ser desfeita.">
                                <?= csrfField() ?>
                                <input type="hidden" name="_action" value="delete">
                                <input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
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
