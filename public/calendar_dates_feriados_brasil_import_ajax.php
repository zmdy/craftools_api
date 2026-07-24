<?php
/**
 * calendar_dates_feriados_brasil_import_ajax.php — importa feriados
 * nacionais, feriados estaduais e datas comemorativas de
 * https://github.com/joaopbini/feriados-brasil para popular
 * calendar_entries.
 *
 * Diferente de calendar_dates_api_import_ajax.php (que consulta
 * apicdata.biduinfo.com.br uma data por vez, porque aquela API só responde
 * um dia por chamada), o feriados-brasil publica o ANO INTEIRO de cada
 * categoria num único arquivo JSON no GitHub -- então este importador busca
 * as 3 categorias pedidas (nacional/estadual/comemorativas) de uma vez só,
 * em 3 requisições HTTP de saída, e grava tudo numa única transação (ver
 * calendarEntryReplaceYearFromSource() em repo.php). "Feriado municipal" e
 * "ponto facultativo" (as outras 2 categorias que o repositório também
 * publica) são deliberadamente ignorados -- não foram pedidos.
 *
 * A data de cada item vem como "DD/MM/YYYY"; só dia e mês são usados (ver
 * calendar_entries.year, sempre NULL para holiday/commemoration -- o
 * registro vale todo ano). Para feriados de data móvel (Sexta-Feira Santa,
 * Carnaval no RJ, Dia das Mães/Pais) isso significa que a data gravada é a
 * do ANO IMPORTADO -- rodar de novo escolhendo o ano seguinte atualiza essas
 * datas (calendarEntryReplaceYearFromSource() troca a base inteira desta
 * fonte a cada execução).
 *
 * POST  op=process  year=YYYY  — busca as 3 categorias para o ano informado
 *                                 e substitui em bloco todos os registros
 *                                 desta fonte (rodar de novo não duplica).
 */

require_once __DIR__ . '/../src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
applySecurityHeaders(true);

function fbImportJsonError(int $httpCode, string $message): void {
    http_response_code($httpCode);
    echo json_encode(['status' => 'error', 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isAdminLoggedIn()) {
    fbImportJsonError(401, 'Sessão expirada. Atualize a página e faça login novamente.');
}

const FERIADOS_BRASIL_SOURCE  = 'github.com/joaopbini/feriados-brasil';
const FERIADOS_BRASIL_BASE    = 'https://raw.githubusercontent.com/joaopbini/feriados-brasil/master/dados';
const FERIADOS_BRASIL_TIMEOUT = 10; // segundos, por chamada HTTP (arquivos maiores que os da apicdata)

/**
 * Busca uma URL e decodifica a resposta como JSON (array). Mesma estratégia
 * de calImportFetchJson() em calendar_dates_api_import_ajax.php (cURL com
 * fallback pra file_get_contents) -- não compartilhada entre os dois
 * arquivos porque cada um já é standalone/sem dependências entre si, e a
 * função é pequena o bastante pra duplicar sem custo real de manutenção.
 * Retorna null em qualquer falha (rede, HTTP != 2xx, JSON inválido/não é
 * array) -- o chamador trata isso como "categoria indisponível", nunca
 * interrompe o processamento das outras.
 */
function fbFetchJsonArray(string $url): ?array {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => FERIADOS_BRASIL_TIMEOUT,
            CURLOPT_TIMEOUT        => FERIADOS_BRASIL_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_USERAGENT      => 'CraftToolsAPI-CalendarImport/1.0',
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $status < 200 || $status >= 300) {
            return null;
        }
    } else {
        $context = stream_context_create(['http' => [
            'method'  => 'GET',
            'timeout' => FERIADOS_BRASIL_TIMEOUT,
            'header'  => "User-Agent: CraftToolsAPI-CalendarImport/1.0\r\n",
        ]]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            return null;
        }
    }
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

/**
 * Converte "DD/MM/YYYY" em [dia, mes] (int, int) ou null se o formato não
 * bater -- o ano do arquivo é intencionalmente descartado (ver comentário
 * de cabeçalho: category holiday/commemoration sempre recorre todo ano).
 */
function fbParseDayMonth(string $data): ?array {
    if (!preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $data, $m)) {
        return null;
    }
    $day = (int) $m[1];
    $month = (int) $m[2];
    if ($day < 1 || $day > 31 || $month < 1 || $month > 12) {
        return null;
    }
    return [$day, $month];
}

$method = $_SERVER['REQUEST_METHOD'];
$op = $method === 'POST' ? (string) ($_POST['op'] ?? '') : (string) ($_GET['op'] ?? '');

if ($op !== 'process' || $method !== 'POST') {
    fbImportJsonError(400, 'Operação inválida.');
}

requireCsrf();

$adminId = (int) ($_SESSION['admin_id'] ?? 0);
$year = intInput($_POST, 'year', 0, 2000, 2100);
if ($year < 2000) {
    fbImportJsonError(400, 'Ano inválido.');
}

$items = [];
$fetchErrors = [];

// ── Feriados nacionais ──────────────────────────────────────────────────
$nacional = fbFetchJsonArray(FERIADOS_BRASIL_BASE . '/feriados/nacional/json/' . $year . '.json');
if ($nacional === null) {
    $fetchErrors[] = 'feriados nacionais: falha ao consultar o GitHub.';
} else {
    foreach ($nacional as $entry) {
        $dm = is_array($entry) ? fbParseDayMonth((string) ($entry['data'] ?? '')) : null;
        $title = trim((string) ($entry['nome'] ?? ''));
        if ($dm === null || $title === '') {
            continue;
        }
        $items[] = [
            'category'    => 'holiday',
            'day'         => $dm[0],
            'month'       => $dm[1],
            'title'       => $title,
            'description' => $entry['descricao'] ?? null,
            'scope'       => 'national',
            'uf'          => null,
        ];
    }
}

// ── Feriados estaduais (todos os estados) ───────────────────────────────
$estadual = fbFetchJsonArray(FERIADOS_BRASIL_BASE . '/feriados/estadual/json/' . $year . '.json');
if ($estadual === null) {
    $fetchErrors[] = 'feriados estaduais: falha ao consultar o GitHub.';
} else {
    foreach ($estadual as $entry) {
        $dm = is_array($entry) ? fbParseDayMonth((string) ($entry['data'] ?? '')) : null;
        $title = trim((string) ($entry['nome'] ?? ''));
        $uf = strtoupper(trim((string) ($entry['uf'] ?? '')));
        if ($dm === null || $title === '' || $uf === '') {
            continue;
        }
        $items[] = [
            'category'    => 'holiday',
            'day'         => $dm[0],
            'month'       => $dm[1],
            'title'       => $title,
            'description' => $entry['descricao'] ?? null,
            'scope'       => 'state',
            'uf'          => $uf,
        ];
    }
}

// ── Datas comemorativas ──────────────────────────────────────────────────
$comemorativas = fbFetchJsonArray(FERIADOS_BRASIL_BASE . '/comemorativas/json/' . $year . '.json');
if ($comemorativas === null) {
    $fetchErrors[] = 'datas comemorativas: falha ao consultar o GitHub.';
} else {
    foreach ($comemorativas as $entry) {
        $dm = is_array($entry) ? fbParseDayMonth((string) ($entry['data'] ?? '')) : null;
        $title = trim((string) ($entry['nome'] ?? ''));
        if ($dm === null || $title === '') {
            continue;
        }
        $items[] = [
            'category'    => 'commemoration',
            'day'         => $dm[0],
            'month'       => $dm[1],
            'title'       => $title,
            'description' => $entry['descricao'] ?? null,
        ];
    }
}

if (count($items) === 0) {
    fbImportJsonError(502, 'Nenhum item pôde ser importado (falha ao consultar o GitHub em todas as categorias).');
}

try {
    $inserted = calendarEntryReplaceYearFromSource(FERIADOS_BRASIL_SOURCE, $items);
} catch (Throwable $e) {
    fbImportJsonError(500, 'Falha ao gravar os registros: ' . $e->getMessage());
}

$counts = ['holiday' => 0, 'commemoration' => 0];
foreach ($items as $item) {
    $counts[$item['category']] = ($counts[$item['category']] ?? 0) + 1;
}

auditLog($adminId, 'import', 'calendar_entries', 'feriados-brasil ' . $year . ': ' . $inserted . ' itens');

echo json_encode([
    'status' => 'success',
    'data' => [
        'year'   => $year,
        'counts' => $counts,
        'total'  => $inserted,
        'errors' => $fetchErrors,
    ],
], JSON_UNESCAPED_UNICODE);
