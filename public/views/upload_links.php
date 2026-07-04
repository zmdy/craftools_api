<?php
/**
 * upload_links.php (view) — cria e gerencia links de upload de fotos para
 * clientes. O valor em texto puro do link nunca é salvo (só o hash, como em
 * api_tokens) — por isso só aparece uma vez, logo após "Gerar link" ou
 * "Gerar novo link", e a URL em si é montada no navegador
 * (admin.js: buildUploadLink()), não aqui no PHP.
 */

/** Mostra só as INFORMAÇÕES capturadas de uma foto enviada (texto/fonte/cor da
 *  legenda, tipo e valor do fundo) — sem tentar remontar visualmente como a
 *  foto ficaria (isso exigiria compor a imagem final, que não é o que este
 *  recurso guarda; ele guarda a foto original + os metadados separados). */
function renderUploadPhotoInfo(string $linkUuid, array $photo, ?array $caption, ?array $background): void {
    $bg = $background['bg'] ?? null;
    $overlay = $background['overlay'] ?? null;
    $swatch = function (string $color): string {
        return '<span style="display:inline-block;width:11px;height:11px;border-radius:3px;'
            . 'background:' . e($color) . ';border:1px solid var(--border);vertical-align:middle;margin-right:4px;"></span>';
    };
    ?>
    <div class="upload-photo-row">
        <div class="img-thumb" style="width:72px;height:72px;flex-shrink:0;">
            <img src="upload_link_photo.php?uuid=<?= e($linkUuid) ?>&file=<?= e($photo['filename']) ?>"
                 alt="<?= e($photo['originalName'] ?? '') ?>" loading="lazy">
        </div>
        <div style="flex:1;min-width:0;font-size:12.5px;">
            <?php if ($caption && $caption['text']): ?>
                <div><strong>Legenda:</strong> "<?= e($caption['text']) ?>"</div>
                <div class="text-muted" style="margin-top:2px;">
                    Fonte: <?= e($caption['fontFamily'] ?: '—') ?> ·
                    Tamanho: <?= e($caption['fontSize'] ?: '—') ?> ·
                    Cor: <?= $swatch($caption['color'] ?: '#000000') ?><?= e($caption['color'] ?: '—') ?>
                </div>
            <?php else: ?>
                <div class="text-muted">Sem legenda</div>
            <?php endif; ?>

            <?php if ($bg && !empty($bg['type'])): ?>
                <div style="margin-top:6px;">
                    <strong>Fundo:</strong>
                    <?php if ($bg['type'] === 'color'): ?>
                        <?= $swatch($bg['value']) ?><?= e($bg['value']) ?>
                    <?php elseif ($bg['type'] === 'gradient'): ?>
                        gradiente
                    <?php elseif ($bg['type'] === 'image'): ?>
                        imagem — <a href="<?= e($bg['value']) ?>" target="_blank" rel="noopener">ver</a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="text-muted" style="margin-top:6px;">Sem fundo definido</div>
            <?php endif; ?>

            <?php if ($overlay && !empty($overlay['url'])): ?>
                <div class="text-muted" style="margin-top:4px;">Overlay: <a href="<?= e($overlay['url']) ?>" target="_blank" rel="noopener">ver</a></div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

// Reveal do link em texto puro -- só existe uma vez, logo após "Gerar link"/
// "Gerar novo link" (create ou regenerate em actions.php), independente de o
// admin estar na lista ou já ter ido direto para a tela de detalhe do link.
$reveal = $_SESSION['reveal_upload_link'] ?? null;
unset($_SESSION['reveal_upload_link']);

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

    <?php if ($reveal): ?>
    <div class="card">
        <div class="card-head"><h2>Link pronto</h2></div>
        <div class="card-body">
            <p class="help-text" style="margin-bottom:8px;">
                Copie agora e envie para o cliente: por segurança, este link completo não é salvo em
                texto puro, então não pode ser reexibido depois — se precisar de novo, use "Gerar novo link".
            </p>
            <div class="token-reveal d-flex flex-between">
                <span id="new-link-value" class="mono" style="word-break:break-all;" data-upload-token="<?= e($reveal) ?>">Carregando link…</span>
                <button type="button" class="btn btn-sm btn-secondary" data-copy="#new-link-value">Copiar</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

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
            <?php if ($viewLink['notes']): ?>
                <div class="field"><label>Observações</label><div><?= nl2br(e($viewLink['notes'])) ?></div></div>
            <?php endif; ?>
            <form method="post" action="index.php?page=upload_links" style="margin-top:10px;" data-confirm="Gerar um novo link para este cliente? O link anterior para de funcionar.">
                <?= csrfField() ?>
                <input type="hidden" name="_action" value="regenerate">
                <input type="hidden" name="id" value="<?= (int) $viewLink['id'] ?>">
                <input type="hidden" name="redirect_view" value="<?= e($viewLink['uuid']) ?>">
                <button type="submit" class="btn btn-outline btn-sm">
                    <span class="material-symbols-outlined" style="font-size:15px;">content_copy</span> Mostrar/gerar link de envio
                </button>
            </form>
        </div>
    </div>

    <?php if ($viewLink['status'] === 'submitted' && $photoFiles): ?>
    <div class="card">
        <div class="card-head"><h2>Fotos enviadas (<?= count($photoFiles) ?>)</h2></div>
        <div class="card-body">
            <?php foreach ($photoFiles as $p): ?>
                <?php
                $capIdx = (string) ($p['index'] ?? '');
                $cap = $submission['captions'][$capIdx] ?? null;
                $bgData = $submission['backgrounds'][$capIdx] ?? null;
                renderUploadPhotoInfo($viewLink['uuid'], $p, $cap, $bgData);
                ?>
            <?php endforeach; ?>
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
    $links = uploadLinkList();
    $kits = gridSizeList();
    ?>

    <?php if ($reveal): ?>
    <div class="card">
        <div class="card-head"><h2>Link pronto</h2></div>
        <div class="card-body">
            <p class="help-text" style="margin-bottom:8px;">
                Copie agora e envie para o cliente: por segurança, este link completo não é salvo em
                texto puro, então não pode ser reexibido depois — se precisar de novo, use "Gerar novo link".
            </p>
            <div class="token-reveal d-flex flex-between">
                <span id="new-link-value" class="mono" style="word-break:break-all;" data-upload-token="<?= e($reveal) ?>">Carregando link…</span>
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
                            <form method="post" action="index.php?page=upload_links" style="display:inline;" data-confirm="Gerar um novo link para este cliente? O link anterior para de funcionar.">
                                <?= csrfField() ?>
                                <input type="hidden" name="_action" value="regenerate">
                                <input type="hidden" name="id" value="<?= (int) $l['id'] ?>">
                                <button type="submit" class="btn btn-outline btn-sm">
                                    <span class="material-symbols-outlined" style="font-size:15px;">content_copy</span> Gerar novo link
                                </button>
                            </form>
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
