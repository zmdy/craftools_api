<?php
// Não precisa de checagem adicional: index.php já garante sessão admin.
$stats = apiAccessLogStats();

$filterResource = trim((string) ($_GET['resource'] ?? ''));
$filterTier     = trim((string) ($_GET['tier'] ?? ''));
$filterStatus   = trim((string) ($_GET['status'] ?? ''));
$filterFrom     = trim((string) ($_GET['from'] ?? ''));
$filterTo       = trim((string) ($_GET['to'] ?? ''));

$filters = [
    'resource'  => $filterResource !== '' ? $filterResource : null,
    'tier'      => $filterTier !== '' ? $filterTier : null,
    'status'    => $filterStatus !== '' ? $filterStatus : null,
    'date_from' => $filterFrom !== '' ? $filterFrom : null,
    'date_to'   => $filterTo !== '' ? $filterTo : null,
];

$listPage      = max(1, (int) ($_GET['lp'] ?? 1));
$listPerPage   = 30;
$listOffset    = ($listPage - 1) * $listPerPage;
$listRows      = apiAccessLogList($listPerPage, $listOffset, $filters);
$listTotal     = apiAccessLogCount($filters);
$listTotalPages = max(1, (int) ceil($listTotal / $listPerPage));

$distinctResources = apiAccessLogDistinctResources();

// Monta a query string base (sem "lp") reaproveitada pelos links de paginação.
$baseParams = array_filter([
    'page' => 'api_logs',
    'resource' => $filterResource,
    'tier' => $filterTier,
    'status' => $filterStatus,
    'from' => $filterFrom,
    'to' => $filterTo,
], static function ($v) { return $v !== ''; });
$baseQs = http_build_query($baseParams);

$hasFilters = $filterResource !== '' || $filterTier !== '' || $filterStatus !== '' || $filterFrom !== '' || $filterTo !== '';
?>

<div class="stat-grid">
    <div class="stat-card"><div class="stat-num"><?= number_format($stats['total'], 0, ',', '.') ?></div><div class="stat-label">Acessos registrados (total)</div></div>
    <div class="stat-card"><div class="stat-num"><?= number_format($stats['today'], 0, ',', '.') ?></div><div class="stat-label">Acessos hoje</div></div>
    <div class="stat-card"><div class="stat-num"><?= number_format($stats['errors_today'], 0, ',', '.') ?></div><div class="stat-label">Erros hoje</div></div>
</div>

<div class="card">
    <div class="card-body">
        <p class="text-muted" style="margin-bottom:0; line-height:1.6;">
            Toda requisição feita à API pública (<code>/v1/</code>) é registrada aqui, com sucesso ou erro —
            recurso acessado, token/tier, IP, tempo de resposta e a mensagem de erro (quando houver).
            O parâmetro <code>token</code> nunca é gravado em texto puro nos logs.
        </p>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <h2>Acessos (<?= number_format($listTotal, 0, ',', '.') ?>)</h2>
    </div>
    <div class="card-body">
        <form method="get" action="index.php" class="field-row" style="align-items:flex-end; flex-wrap:wrap;">
            <input type="hidden" name="page" value="api_logs">
            <div class="field">
                <label>Recurso</label>
                <select name="resource">
                    <option value="">Todos</option>
                    <?php foreach ($distinctResources as $r): ?>
                        <option value="<?= e($r) ?>" <?= $filterResource === $r ? 'selected' : '' ?>><?= e($r) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Tier</label>
                <select name="tier">
                    <option value="">Todos</option>
                    <?php foreach (['free', 'plus', 'premium'] as $t): ?>
                        <option value="<?= $t ?>" <?= $filterTier === $t ? 'selected' : '' ?>><?= ucfirst($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Status</label>
                <select name="status">
                    <option value="">Todos</option>
                    <option value="success" <?= $filterStatus === 'success' ? 'selected' : '' ?>>Sucesso</option>
                    <option value="error" <?= $filterStatus === 'error' ? 'selected' : '' ?>>Erro</option>
                </select>
            </div>
            <div class="field">
                <label>De</label>
                <input type="date" name="from" value="<?= e($filterFrom) ?>">
            </div>
            <div class="field">
                <label>Até</label>
                <input type="date" name="to" value="<?= e($filterTo) ?>">
            </div>
            <button type="submit" class="btn btn-outline btn-sm">Filtrar</button>
            <?php if ($hasFilters): ?>
                <a href="index.php?page=api_logs" class="btn btn-outline btn-sm">Limpar filtro</a>
            <?php endif; ?>
        </form>
    </div>
    <div class="card-body flush">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Data/Hora (UTC)</th>
                    <th>Recurso</th>
                    <th>Modo</th>
                    <th>Tier</th>
                    <th>Token</th>
                    <th>Status</th>
                    <th>IP</th>
                    <th>Duração</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$listRows): ?>
                <tr class="empty-row"><td colspan="8">Nenhum acesso registrado<?= $hasFilters ? ' para este filtro' : '' ?>.</td></tr>
            <?php endif; ?>
            <?php foreach ($listRows as $row): ?>
                <?php $isError = (int) $row['status_code'] >= 400; ?>
                <tr>
                    <td class="text-muted" style="font-size:12px; white-space:nowrap;"><?= e($row['created_at']) ?></td>
                    <td><?= e($row['resource'] ?? '—') ?></td>
                    <td class="text-muted"><?= e($row['mode'] ?? '—') ?></td>
                    <td><span class="badge badge-<?= e($row['tier']) ?>"><?= e($row['tier']) ?></span></td>
                    <td class="text-muted" style="font-size:12px;">
                        <?= $row['token_label'] ? e($row['token_label']) . ' (' . e($row['token_prefix']) . '…)' : '—' ?>
                    </td>
                    <td>
                        <span class="badge <?= $isError ? 'badge-danger' : 'badge-on' ?>"><?= (int) $row['status_code'] ?></span>
                        <?php if ($isError && !empty($row['error_message'])): ?>
                            <div class="text-muted" style="font-size:11px; max-width:240px;"><?= e($row['error_message']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="mono" style="font-size:12px;"><?= e($row['ip'] ?? '—') ?></td>
                    <td class="text-muted" style="font-size:12px;"><?= $row['duration_ms'] !== null ? (int) $row['duration_ms'] . 'ms' : '—' ?></td>
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
