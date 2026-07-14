<?php
// No additional auth check needed: index.php already enforces the admin session.
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

// Build the base query string (without "lp") reused by pagination links.
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
    <div class="stat-card"><div class="stat-num"><?= number_format($stats['total'], 0, ',', '.') ?></div><div class="stat-label">Total requests logged</div></div>
    <div class="stat-card"><div class="stat-num"><?= number_format($stats['today'], 0, ',', '.') ?></div><div class="stat-label">Requests today</div></div>
    <div class="stat-card"><div class="stat-num"><?= number_format($stats['errors_today'], 0, ',', '.') ?></div><div class="stat-label">Errors today</div></div>
</div>

<div class="card">
    <div class="card-body">
        <p class="text-muted" style="margin-bottom:0; line-height:1.6;">
            Every request to the public API (<code>/v1/</code>) is logged here, whether successful or not —
            resource accessed, token/tier, IP, response time, and error message (if any).
            The <code>token</code> parameter is never stored in plain text in the logs.
            Records are stored in daily files: <code>storage/logs/api/YYYY-MM-DD.jsonl</code>.
            <?php if (!$hasFilters || ($filterFrom === '' && $filterTo === '')): ?>
                <br><strong>No date filter:</strong> showing the last 30 days. Use the "From" / "To" fields to query earlier periods.
            <?php endif; ?>
        </p>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <h2>Requests (<?= number_format($listTotal, 0, ',', '.') ?>)</h2>
    </div>
    <div class="card-body">
        <form method="get" action="index.php" class="field-row" style="align-items:flex-end; flex-wrap:wrap;">
            <input type="hidden" name="page" value="api_logs">
            <div class="field">
                <label>Resource</label>
                <select name="resource">
                    <option value="">All</option>
                    <?php foreach ($distinctResources as $r): ?>
                        <option value="<?= e($r) ?>" <?= $filterResource === $r ? 'selected' : '' ?>><?= e($r) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Tier</label>
                <select name="tier">
                    <option value="">All</option>
                    <?php foreach (['free', 'plus', 'premium'] as $t): ?>
                        <option value="<?= $t ?>" <?= $filterTier === $t ? 'selected' : '' ?>><?= ucfirst($t) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Status</label>
                <select name="status">
                    <option value="">All</option>
                    <option value="success" <?= $filterStatus === 'success' ? 'selected' : '' ?>>Success</option>
                    <option value="error" <?= $filterStatus === 'error' ? 'selected' : '' ?>>Error</option>
                </select>
            </div>
            <div class="field">
                <label>From</label>
                <input type="date" name="from" value="<?= e($filterFrom) ?>">
            </div>
            <div class="field">
                <label>To</label>
                <input type="date" name="to" value="<?= e($filterTo) ?>">
            </div>
            <button type="submit" class="btn btn-outline btn-sm">Filter</button>
            <?php if ($hasFilters): ?>
                <a href="index.php?page=api_logs" class="btn btn-outline btn-sm">Clear filter</a>
            <?php endif; ?>
        </form>
    </div>
    <div class="card-body flush">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Date/Time (UTC)</th>
                    <th>Resource</th>
                    <th>Mode</th>
                    <th>Tier</th>
                    <th>Token</th>
                    <th>Status</th>
                    <th>IP</th>
                    <th>Duration</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$listRows): ?>
                <tr class="empty-row"><td colspan="8">No requests logged<?= $hasFilters ? ' for this filter' : '' ?>.</td></tr>
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
        <a href="index.php?<?= $baseQs ?>&lp=<?= max(1, $listPage - 1) ?>" class="btn btn-outline btn-sm" <?= $listPage <= 1 ? 'style="pointer-events:none;opacity:.4;"' : '' ?>>Previous</a>
        <span class="text-muted" style="font-size:12.5px;">Page <?= $listPage ?> of <?= $listTotalPages ?></span>
        <a href="index.php?<?= $baseQs ?>&lp=<?= min($listTotalPages, $listPage + 1) ?>" class="btn btn-outline btn-sm" <?= $listPage >= $listTotalPages ? 'style="pointer-events:none;opacity:.4;"' : '' ?>>Next</a>
    </div>
    <?php endif; ?>
</div>
