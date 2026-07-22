<?php
// Não precisa de checagem adicional: index.php já garante sessão admin
$monthLabels = [1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril', 5 => 'Maio', 6 => 'Junho',
    7 => 'Julho', 8 => 'Agosto', 9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'];
$today = new DateTime('now', new DateTimeZone('UTC'));
?>

<div class="card">
    <div class="card-head"><h2>Importar Dados de Exemplo (apicdata.biduinfo.com.br)</h2></div>
    <div class="card-body">
        <p class="text-muted" style="margin-bottom:16px; line-height:1.6;">
            Busca <strong>comemorações</strong>, <strong>santos</strong> e <strong>eventos históricos</strong> na API pública
            <code>apicdata.biduinfo.com.br</code> e grava como dados de exemplo em <code>calendar_entries</code>
            (fonte <code>apicdata.biduinfo.com.br</code>). Essa API não fornece feriados — continue cadastrando
            feriados manualmente ou via CSV.<br>
            Rodar de novo para a mesma data <strong>não duplica</strong>: os registros anteriores desta mesma fonte
            são substituídos. Cadastros manuais ou vindos de CSV nunca são afetados.
        </p>

        <div id="cal-api-import-app" data-csrf="<?= e(csrfToken()) ?>">
            <div class="field-row" style="align-items:flex-end;">
                <div class="field">
                    <label>Data inicial</label>
                    <div class="d-flex gap-2">
                        <select id="cai-start-month">
                            <?php foreach ($monthLabels as $val => $label): ?>
                                <option value="<?= $val ?>" <?= (int) $today->format('n') === $val ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="number" id="cai-start-day" min="1" max="31" value="<?= (int) $today->format('j') ?>" style="width:80px;">
                    </div>
                </div>
                <div class="field">
                    <label>Data final</label>
                    <div class="d-flex gap-2">
                        <select id="cai-end-month">
                            <?php foreach ($monthLabels as $val => $label): ?>
                                <option value="<?= $val ?>" <?= (int) $today->format('n') === $val ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="number" id="cai-end-day" min="1" max="31" value="<?= (int) $today->format('j') ?>" style="width:80px;">
                    </div>
                </div>
                <div class="field">
                    <button type="button" class="btn btn-outline btn-sm" id="cai-whole-year">Ano inteiro (01/01 – 31/12)</button>
                </div>
            </div>

            <div class="help-text" id="cai-range-summary" style="margin-bottom:14px;"></div>

            <div>
                <button type="button" class="btn btn-primary" id="cai-start" style="padding:12px 24px; font-size:15px;">
                    <span class="material-symbols-outlined">cloud_download</span>
                    Iniciar importação
                </button>
                <button type="button" class="btn btn-outline" id="cai-stop" hidden>Parar</button>
                <a href="index.php?page=calendar_dates" class="btn btn-outline" style="margin-left:8px;">Voltar</a>
            </div>

            <div id="cai-progress" hidden style="margin-top:20px;">
                <div class="progress-bar"><div class="progress-bar-fill" id="cai-progress-fill" style="width:0%"></div></div>
                <div class="progress-label" id="cai-progress-label">0%</div>
                <div class="flex-between" style="margin-top:4px;">
                    <span class="text-muted" id="cai-progress-counts" style="font-size:12.5px;"></span>
                </div>
            </div>

            <div id="cai-done" class="flash flash-success" hidden style="margin-top:18px;">
                <span class="material-symbols-outlined">check_circle</span>
                <span id="cai-done-msg"></span>
                <a href="index.php?page=calendar_dates" style="margin-left:8px;">Ver registros</a>
            </div>

            <div id="cai-error-log" style="margin-top:12px;"></div>
        </div>
    </div>
</div>

<script src="assets/calendar-dates-api-import.js"></script>
