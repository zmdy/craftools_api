<?php
/** @var array $admin */
$counts = dashboardCounts();
$recentLogins = db()->query('SELECT * FROM login_attempts ORDER BY id DESC LIMIT 8')->fetchAll();
$recentAudit = db()->query('SELECT * FROM audit_log ORDER BY id DESC LIMIT 8')->fetchAll();
?>
<div class="stat-grid">
    <div class="stat-card"><div class="stat-num"><?= (int) $counts['app_users'] ?></div><div class="stat-label">Usuários</div></div>
    <div class="stat-card"><div class="stat-num"><?= (int) $counts['api_tokens'] ?></div><div class="stat-label">Tokens de API</div></div>
    <div class="stat-card"><div class="stat-num"><?= (int) $counts['grid_sizes'] ?></div><div class="stat-label">Tamanhos de Grid</div></div>
    <div class="stat-card"><div class="stat-num"><?= (int) $counts['album_templates'] ?></div><div class="stat-label">Templates de Álbum</div></div>
    <div class="stat-card"><div class="stat-num"><?= (int) $counts['asset_collections'] ?></div><div class="stat-label">Coleções de Imagem</div></div>
    <div class="stat-card"><div class="stat-num"><?= (int) $counts['asset_images'] ?></div><div class="stat-label">Imagens</div></div>
    <div class="stat-card"><div class="stat-num"><?= (int) $counts['phrases'] ?></div><div class="stat-label">Frases</div></div>
</div>

<div class="card">
    <div class="card-head"><h2>Bem-vindo(a), <?= e($admin['name'] ?? '') ?></h2></div>
    <div class="card-body">
        <p style="margin:0 0 10px;color:var(--text-secondary);font-size:13.5px;">
            Use o menu lateral para cadastrar usuários, tokens de API, tamanhos de grid, templates de álbum,
            imagens de overlay/fundo e o banco de frases motivacionais.
        </p>
        <p style="margin:0;color:var(--text-secondary);font-size:13.5px;">
            A API pública usada pelo PWA fica em <code>/v1/</code> (grids, templates de álbum, frases e
            coleções de overlay/fundo).
        </p>
    </div>
</div>

<div class="field-row">
    <div class="card mb-0">
        <div class="card-head"><h2>Últimas tentativas de login</h2></div>
        <div class="card-body flush">
            <table class="data-table">
                <thead><tr><th>E-mail</th><th>IP</th><th>Status</th><th>Data</th></tr></thead>
                <tbody>
                <?php if (!$recentLogins): ?>
                    <tr class="empty-row"><td colspan="4">Nenhum registro ainda.</td></tr>
                <?php endif; ?>
                <?php foreach ($recentLogins as $l): ?>
                    <tr>
                        <td><?= e($l['email']) ?></td>
                        <td class="mono"><?= e($l['ip']) ?></td>
                        <td><span class="badge <?= $l['success'] ? 'badge-on' : 'badge-off' ?>"><?= $l['success'] ? 'OK' : 'Falhou' ?></span></td>
                        <td class="text-muted" style="font-size:12px;"><?= e($l['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card mb-0">
        <div class="card-head"><h2>Auditoria recente</h2></div>
        <div class="card-body flush">
            <table class="data-table">
                <thead><tr><th>Ação</th><th>Entidade</th><th>Data</th></tr></thead>
                <tbody>
                <?php if (!$recentAudit): ?>
                    <tr class="empty-row"><td colspan="3">Nenhum registro ainda.</td></tr>
                <?php endif; ?>
                <?php foreach ($recentAudit as $a): ?>
                    <tr>
                        <td><?= e($a['action']) ?></td>
                        <td class="text-muted"><?= e(($a['entity'] ?? '') . ($a['entity_id'] ? ' #' . $a['entity_id'] : '')) ?></td>
                        <td class="text-muted" style="font-size:12px;"><?= e($a['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
