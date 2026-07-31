<?php
/**
 * calendar_dates.php — cadastro de feriados/comemorações/santos/eventos
 * históricos, consumidos pela API pública (?resource=calendar-dates).
 * Uma única tabela (calendar_entries) com "category" como discriminador;
 * os campos exibidos no formulário mudam conforme a categoria selecionada
 * (ver assets/calendar-dates.js -- CSP bloqueia script inline).
 */
$editId         = (int) ($_GET['edit'] ?? 0);
$editing        = $editId > 0 ? calendarEntryFind($editId) : null;
$filterCategory = (string) ($_GET['category'] ?? '');
$filterMonth    = (string) ($_GET['month'] ?? '');

// Pagination -- previously calendarEntryList() had no LIMIT at all, so this
// view fetched and rendered EVERY matching row in one page load (fine for a
// handful of manual entries, but the feriados-brasil/ANBIMA/apicdata
// importers can each add hundreds of rows in one run).
$listPage       = max(1, (int) ($_GET['lp'] ?? 1));
$listPerPage    = 50;
$listOffset     = ($listPage - 1) * $listPerPage;
$rows           = calendarEntryList(
    $filterCategory !== '' ? $filterCategory : null,
    $filterMonth !== '' ? (int) $filterMonth : null,
    null,
    null,
    $listPerPage,
    $listOffset
);
$listTotal      = calendarEntryListCount(
    $filterCategory !== '' ? $filterCategory : null,
    $filterMonth !== '' ? (int) $filterMonth : null
);
$listTotalPages = max(1, (int) ceil($listTotal / $listPerPage));
$baseQs         = http_build_query(array_filter([
    'page'     => 'calendar_dates',
    'category' => $filterCategory,
    'month'    => $filterMonth,
], static function ($v) { return $v !== ''; }));

$sources = calendarEntrySources();

// Regra de data móvel (se houver) já salva no registro em edição -- decodifica
// pra pré-preencher os campos de regra e marcar "Tipo de data" corretamente.
$editingRule = !empty($editing['date_rule']) ? json_decode((string) $editing['date_rule'], true) : null;
if (!is_array($editingRule)) {
    $editingRule = null;
}
$weekdayLabels = [0 => 'Domingo', 1 => 'Segunda-feira', 2 => 'Terça-feira', 3 => 'Quarta-feira', 4 => 'Quinta-feira', 5 => 'Sexta-feira', 6 => 'Sábado'];
$nthLabels     = [1 => '1º', 2 => '2º', 3 => '3º', 4 => '4º', 5 => '5º'];

$categoryLabels = [
    'holiday'            => 'Feriado',
    'commemoration_main' => 'Comemoração (principal)',
    'commemoration_misc' => 'Comemoração (diversa)',
    'saint'              => 'Santo do dia',
    'event'              => 'Evento histórico',
];
$scopeLabels = ['national' => 'Nacional', 'state' => 'Estadual', 'municipal' => 'Municipal'];
$monthLabels = [1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril', 5 => 'Maio', 6 => 'Junho',
    7 => 'Julho', 8 => 'Agosto', 9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'];
?>
<div class="card">
    <div class="card-head"><h2><?= $editing ? 'Editar registro' : 'Novo registro' ?></h2></div>
    <div class="card-body">
        <form method="post" action="index.php?page=calendar_dates" id="cal-entry-form">
            <?= csrfField() ?>
            <input type="hidden" name="_action" value="save">
            <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
            <div class="field-row">
                <div class="field">
                    <label>Categoria</label>
                    <select name="category" id="cal-category">
                        <?php foreach ($categoryLabels as $val => $label): ?>
                            <option value="<?= $val ?>" <?= ($editing['category'] ?? 'commemoration_main') === $val ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Tipo de data</label>
                    <select name="date_type" id="cal-date-type">
                        <option value="fixed" <?= $editingRule === null ? 'selected' : '' ?>>Fixa</option>
                        <option value="rule" <?= $editingRule !== null ? 'selected' : '' ?>>Móvel (regra calculada)</option>
                    </select>
                </div>
                <div class="field" data-cal-date-type="fixed">
                    <label>Mês</label>
                    <select name="month">
                        <?php foreach ($monthLabels as $val => $label): ?>
                            <option value="<?= $val ?>" <?= (int) ($editing['month'] ?? 0) === $val ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field" data-cal-date-type="fixed">
                    <label>Dia</label>
                    <input type="number" name="day" min="1" max="31" value="<?= (int) ($editing['day'] ?? 1) ?>" required>
                </div>
                <div class="field" data-cal-field="event">
                    <label>Ano (evento histórico)</label>
                    <input type="number" name="year" value="<?= e($editing['year'] ?? '') ?>" placeholder="Ex: 1822">
                </div>
            </div>
            <div class="field-row" data-cal-date-type="rule">
                <div class="field">
                    <label>Tipo de regra</label>
                    <select name="rule_type" id="cal-rule-type">
                        <option value="nth_weekday" <?= ($editingRule['type'] ?? '') === 'nth_weekday' ? 'selected' : '' ?>>Nª ocorrência de um dia da semana no mês</option>
                        <option value="last_weekday" <?= ($editingRule['type'] ?? '') === 'last_weekday' ? 'selected' : '' ?>>Última ocorrência de um dia da semana no mês</option>
                        <option value="easter_offset" <?= ($editingRule['type'] ?? '') === 'easter_offset' ? 'selected' : '' ?>>Deslocamento em relação à Páscoa</option>
                    </select>
                </div>
                <div class="field" data-cal-rule-type="nth_weekday,last_weekday">
                    <label>Mês</label>
                    <select name="rule_month">
                        <?php foreach ($monthLabels as $val => $label): ?>
                            <option value="<?= $val ?>" <?= (int) ($editingRule['month'] ?? 0) === $val ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field" data-cal-rule-type="nth_weekday,last_weekday">
                    <label>Dia da semana</label>
                    <select name="rule_weekday">
                        <?php foreach ($weekdayLabels as $val => $label): ?>
                            <option value="<?= $val ?>" <?= (int) ($editingRule['weekday'] ?? 0) === $val ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field" data-cal-rule-type="nth_weekday">
                    <label>Ocorrência</label>
                    <select name="rule_nth">
                        <?php foreach ($nthLabels as $val => $label): ?>
                            <option value="<?= $val ?>" <?= (int) ($editingRule['nth'] ?? 1) === $val ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field" data-cal-rule-type="easter_offset">
                    <label>Deslocamento da Páscoa (dias)</label>
                    <input type="number" name="rule_offset" value="<?= e($editingRule['offset'] ?? 0) ?>">
                    <small class="text-muted">Negativo = antes, positivo = depois. Ex: -47 = Carnaval, -2 = Sexta-Feira Santa, 60 = Corpus Christi.</small>
                </div>
            </div>
            <div class="field">
                <label>Título</label>
                <input type="text" name="title" value="<?= e($editing['title'] ?? '') ?>" placeholder="Ex: Independência do Brasil" required>
            </div>
            <div class="field">
                <label>Descrição <small class="text-muted">(opcional)</small></label>
                <textarea name="description" rows="2"><?= e($editing['description'] ?? '') ?></textarea>
            </div>
            <div class="field-row">
                <div class="field" data-cal-field="saint">
                    <label>Link da fonte <small class="text-muted">(opcional)</small></label>
                    <input type="text" name="link" value="<?= e($editing['link'] ?? '') ?>" placeholder="https://...">
                </div>
                <div class="field" data-cal-field="holiday">
                    <label>Abrangência</label>
                    <select name="holiday_scope" id="cal-holiday-scope">
                        <?php foreach ($scopeLabels as $val => $label): ?>
                            <option value="<?= $val ?>" <?= ($editing['holiday_scope'] ?? 'national') === $val ? 'selected' : '' ?>><?= $label ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field" data-cal-field="holiday" data-cal-scope="state,municipal">
                    <label>UF</label>
                    <input type="text" name="uf" value="<?= e($editing['uf'] ?? '') ?>" maxlength="2" placeholder="Ex: SP">
                </div>
                <div class="field" data-cal-field="holiday" data-cal-scope="municipal">
                    <label>Cidade</label>
                    <input type="text" name="city" value="<?= e($editing['city'] ?? '') ?>" placeholder="Ex: São Paulo">
                </div>
            </div>
            <div class="field-row">
                <div class="field">
                    <label>Fonte <small class="text-muted">(opcional -- deixe em branco para cadastro manual)</small></label>
                    <input type="text" name="source" value="<?= e($editing['source'] ?? '') ?>" placeholder="manual" list="source-suggestions">
                    <datalist id="source-suggestions">
                        <?php foreach ($sources as $s): ?><option value="<?= e($s) ?>"><?php endforeach; ?>
                    </datalist>
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
                    <label>Ordem de exibição</label>
                    <input type="number" name="sort_order" value="<?= (int) ($editing['sort_order'] ?? 0) ?>">
                </div>
            </div>
            <div class="checkbox-row field">
                <input type="checkbox" name="active" id="cal_active" <?= ($editing['active'] ?? 1) ? 'checked' : '' ?>>
                <label class="mb-0" for="cal_active">Ativo (visível na API)</label>
            </div>
            <button type="submit" class="btn btn-primary">
                <span class="material-symbols-outlined">save</span> <?= $editing ? 'Salvar alterações' : 'Criar registro' ?>
            </button>
            <?php if ($editing): ?><a href="index.php?page=calendar_dates" class="btn btn-outline">Cancelar</a><?php endif; ?>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <h2>Registros cadastrados (<?= number_format($listTotal, 0, ',', '.') ?>)</h2>
        <div class="d-flex gap-2" style="flex-wrap:wrap; align-items:center;">
            <a href="index.php?page=calendar_dates_feriados_brasil_import" class="btn btn-outline btn-sm">
                <span class="material-symbols-outlined" style="font-size:15px;">cloud_download</span> Importar dados de exemplo (feriados)
            </a>
            <a href="index.php?page=calendar_dates_api_import" class="btn btn-outline btn-sm">
                <span class="material-symbols-outlined" style="font-size:15px;">cloud_download</span> Importar dados de exemplo (comemorações)
            </a>
            <a href="index.php?page=calendar_dates_csv_import" class="btn btn-outline btn-sm">
                <span class="material-symbols-outlined" style="font-size:15px;">upload_file</span> Importar CSV
            </a>
        </div>
    </div>

    <div class="card-body" style="border-bottom:1px solid var(--border);">
        <form method="get" action="index.php" class="d-flex gap-2" style="flex-wrap:wrap; align-items:flex-end;">
            <input type="hidden" name="page" value="calendar_dates">
            <div class="field">
                <label>Categoria</label>
                <select name="category" data-autosubmit>
                    <option value="">Todas as categorias</option>
                    <?php foreach ($categoryLabels as $val => $label): ?>
                        <option value="<?= $val ?>" <?= $filterCategory === $val ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Mês</label>
                <select name="month" data-autosubmit>
                    <option value="">Todos os meses</option>
                    <?php foreach ($monthLabels as $val => $label): ?>
                        <option value="<?= $val ?>" <?= $filterMonth === (string) $val ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-outline btn-sm">Filtrar</button>
            <?php if ($filterCategory !== '' || $filterMonth !== ''): ?>
                <a href="index.php?page=calendar_dates" class="btn btn-outline btn-sm">Limpar filtros</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="card-body flush">
        <table class="data-table">
            <thead><tr><th>Data</th><th>Categoria</th><th>Título</th><th>Detalhe</th><th>Fonte</th><th>Tier</th><th></th></tr></thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr class="empty-row"><td colspan="7">Nenhum registro cadastrado.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r): ?>
                <?php
                $rowRule = !empty($r['date_rule']) ? json_decode((string) $r['date_rule'], true) : null;
                $rowRule = is_array($rowRule) ? $rowRule : null;
                $rowResolved = $rowRule !== null ? calendarEntryResolvedMonthDay($r, (int) date('Y')) : null;
                ?>
                <tr>
                    <td class="mono">
                        <?php if ($rowRule !== null && $rowResolved !== null): ?>
                            <?= str_pad((string) $rowResolved['day'], 2, '0', STR_PAD_LEFT) ?>/<?= str_pad((string) $rowResolved['month'], 2, '0', STR_PAD_LEFT) ?>/<?= date('Y') ?>
                            <br><span class="text-muted" style="font-size:11px; white-space:nowrap;">móvel — <?= e(calendarDateRuleDescribe($rowRule)) ?></span>
                        <?php else: ?>
                            <?= str_pad((string) $r['day'], 2, '0', STR_PAD_LEFT) ?>/<?= str_pad((string) $r['month'], 2, '0', STR_PAD_LEFT) ?>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge" style="background:rgba(99,102,241,.1);color:#6366f1;"><?= e($categoryLabels[$r['category']] ?? $r['category']) ?></span></td>
                    <td style="max-width:280px;"><?= e(mb_strimwidth($r['title'], 0, 100, '…')) ?></td>
                    <td class="text-muted" style="max-width:220px;">
                        <?php
                        // Category-specific detail first (year/scope/source
                        // link, as before); the generic `description` column
                        // is now ALSO shown whenever populated, instead of
                        // only as a last-resort fallback -- previously a
                        // 'holiday' row's description was never displayed in
                        // this list even when filled in via the edit form,
                        // which made the field look dead/missing. For
                        // 'holiday' rows specifically, scope/UF and
                        // description are stacked on two lines since both
                        // can be present at once.
                        ?>
                        <?php if ($r['category'] === 'event'): ?>
                            <?php if ($r['year']): ?>Ano <?= (int) $r['year'] ?><br><?php endif; ?>
                            <?php if (!empty($r['description'])): ?><?= e(mb_strimwidth($r['description'], 0, 80, '…')) ?><?php endif; ?>
                            <?php if (empty($r['year']) && empty($r['description'])): ?>—<?php endif; ?>
                        <?php elseif ($r['category'] === 'holiday'): ?>
                            <?= e($scopeLabels[$r['holiday_scope'] ?? 'national'] ?? '') ?><?= !empty($r['uf']) ? ' — ' . e($r['uf']) : '' ?><?= !empty($r['city']) ? '/' . e($r['city']) : '' ?>
                            <?php if (!empty($r['description'])): ?><br><?= e(mb_strimwidth($r['description'], 0, 80, '…')) ?><?php endif; ?>
                        <?php elseif ($r['category'] === 'saint'): ?>
                            <?php if (!empty($r['link'])): ?><a href="<?= e($r['link']) ?>" target="_blank" rel="noopener">fonte ↗</a><br><?php endif; ?>
                            <?php if (!empty($r['description'])): ?><?= e(mb_strimwidth($r['description'], 0, 80, '…')) ?><?php endif; ?>
                            <?php if (empty($r['link']) && empty($r['description'])): ?>—<?php endif; ?>
                        <?php elseif (!empty($r['description'])): ?>
                            <?= e(mb_strimwidth($r['description'], 0, 80, '…')) ?>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td class="text-muted"><?= e($r['source'] ?: 'manual') ?></td>
                    <td><span class="badge badge-<?= e($r['tier']) ?>"><?= e($r['tier']) ?></span></td>
                    <td class="actions">
                        <a href="index.php?page=calendar_dates&edit=<?= (int) $r['id'] ?>" class="btn btn-outline btn-sm">Editar</a>
                        <form method="post" action="index.php?page=calendar_dates" style="display:inline;" data-confirm="Remover este registro?">
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
    <?php if ($listTotalPages > 1): ?>
    <div class="card-body d-flex" style="justify-content:center; gap:6px; align-items:center; border-top:1px solid var(--border);">
        <a href="index.php?<?= $baseQs ?>&lp=<?= max(1, $listPage - 1) ?>" class="btn btn-outline btn-sm" <?= $listPage <= 1 ? 'style="pointer-events:none;opacity:.4;"' : '' ?>>Anterior</a>
        <span class="text-muted" style="font-size:12.5px;">Página <?= $listPage ?> de <?= $listTotalPages ?></span>
        <a href="index.php?<?= $baseQs ?>&lp=<?= min($listTotalPages, $listPage + 1) ?>" class="btn btn-outline btn-sm" <?= $listPage >= $listTotalPages ? 'style="pointer-events:none;opacity:.4;"' : '' ?>>Próxima</a>
    </div>
    <?php endif; ?>
</div>

<script src="assets/calendar-dates.js"></script>
