<?php
// Não precisa de checagem adicional: index.php já garante sessão admin
$currentYear = (int) (new DateTime('now', new DateTimeZone('UTC')))->format('Y');
?>

<div class="card">
    <div class="card-head"><h2>Importar Dados de Exemplo (feriados-brasil)</h2></div>
    <div class="card-body">
        <p class="text-muted" style="margin-bottom:16px; line-height:1.6;">
            Busca <strong>feriados nacionais</strong>, <strong>feriados estaduais</strong> (todos os estados) e
            <strong>datas comemorativas</strong> do repositório público
            <code>github.com/joaopbini/feriados-brasil</code> para o ano escolhido, e grava em
            <code>calendar_entries</code> (fonte <code>github.com/joaopbini/feriados-brasil</code>). Feriados
            municipais e pontos facultativos do repositório não são importados.<br><br>
            Só o dia e o mês de cada data são gravados -- os registros valem todo ano, igual a um feriado fixo no
            calendário. Para feriados de <strong>data móvel</strong> (Sexta-Feira Santa, Carnaval no RJ, Dia das
            Mães/Pais), isso significa que a data fica a do ano importado; rode a importação de novo escolhendo o
            ano seguinte para atualizar essas datas -- isso substitui a base inteira desta fonte, sem duplicar.
            Cadastros manuais, via CSV ou de outra fonte nunca são afetados.
        </p>

        <div id="cfb-import-app" data-csrf="<?= e(csrfToken()) ?>">
            <div class="field-row" style="align-items:flex-end;">
                <div class="field">
                    <label>Ano</label>
                    <input type="number" id="cfb-year" min="2000" max="2100" value="<?= $currentYear ?>" style="width:120px;">
                </div>
                <div class="field">
                    <button type="button" class="btn btn-primary" id="cfb-start" style="padding:12px 24px; font-size:15px;">
                        <span class="material-symbols-outlined">cloud_download</span>
                        Iniciar importação
                    </button>
                </div>
                <div class="field">
                    <a href="index.php?page=calendar_dates" class="btn btn-outline">Voltar</a>
                </div>
            </div>

            <div id="cfb-loading" class="text-muted" hidden style="margin-top:16px;">Importando...</div>

            <div id="cfb-done" class="flash flash-success" hidden style="margin-top:18px;">
                <span class="material-symbols-outlined">check_circle</span>
                <span id="cfb-done-msg"></span>
                <a href="index.php?page=calendar_dates" style="margin-left:8px;">Ver registros</a>
            </div>

            <div id="cfb-error-log" style="margin-top:12px;"></div>
        </div>
    </div>
</div>

<script src="assets/calendar-dates-feriados-brasil-import.js"></script>
