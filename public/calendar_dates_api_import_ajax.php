<?php
/**
 * calendar_dates_api_import_ajax.php — importa dados de EXEMPLO de
 * https://apicdata.biduinfo.com.br/ para popular calendar_entries antes da
 * carga completa (via CSV) que o usuário fará depois.
 *
 * A API externa só serve 3 das 4 categorias -- /comemoracoes/mmdd,
 * /eventos/mmdd e /santos/mmdd (confirmado por teste manual: /feriados e
 * /feriados/mmdd retornam vazio). "feriado" continua sendo cadastro
 * manual/CSV apenas.
 *
 * POST  op=process  month=M  day=D  — busca as 3 categorias para 1 data e
 *                                     substitui em bloco os registros dessa
 *                                     data+categoria vindos desta mesma fonte
 *                                     (ver calendarEntryReplaceFromSource()),
 *                                     assim rodar de novo não duplica.
 *
 * Processado 1 data por requisição (não em lote) porque cada data já dispara
 * até 3 chamadas HTTP de saída -- deixar o JS iterar data a data (ver
 * assets/calendar-dates-api-import.js) é o que permite mostrar progresso real
 * e não estourar o tempo de execução do PHP.
 */

require_once __DIR__ . '/../src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
applySecurityHeaders(true);

function calImportJsonError(int $httpCode, string $message): void {
    http_response_code($httpCode);
    echo json_encode(['status' => 'error', 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isAdminLoggedIn()) {
    calImportJsonError(401, 'Sessão expirada. Atualize a página e faça login novamente.');
}

const CALENDAR_API_IMPORT_SOURCE  = 'apicdata.biduinfo.com.br';
const CALENDAR_API_IMPORT_BASE    = 'https://apicdata.biduinfo.com.br';
const CALENDAR_API_IMPORT_TIMEOUT = 8; // segundos, por chamada HTTP

/**
 * Busca uma URL e decodifica a resposta como JSON. Usa cURL quando
 * disponível (timeout de conexão + total configurável); cai para
 * file_get_contents com stream context caso a extensão cURL não esteja
 * habilitada. Retorna null em qualquer falha (rede, HTTP != 2xx, JSON
 * inválido) -- o chamador trata isso como "categoria vazia para esta data",
 * nunca interrompe o processamento das outras categorias.
 */
function calImportFetchJson(string $url): ?array {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => CALENDAR_API_IMPORT_TIMEOUT,
            CURLOPT_TIMEOUT        => CALENDAR_API_IMPORT_TIMEOUT,
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
            'method' => 'GET',
            'timeout' => CALENDAR_API_IMPORT_TIMEOUT,
            'header' => "User-Agent: CraftToolsAPI-CalendarImport/1.0\r\n",
        ]]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            return null;
        }
    }
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

$method = $_SERVER['REQUEST_METHOD'];
$op = $method === 'POST' ? (string) ($_POST['op'] ?? '') : (string) ($_GET['op'] ?? '');

if ($op !== 'process' || $method !== 'POST') {
    calImportJsonError(400, 'Operação inválida.');
}

requireCsrf();

$adminId = (int) ($_SESSION['admin_id'] ?? 0);
$month = intInput($_POST, 'month', 0, 1, 12);
$day   = intInput($_POST, 'day', 0, 1, 31);
if ($month < 1 || $day < 1) {
    calImportJsonError(400, 'Mês/dia inválidos.');
}
$mmdd = sprintf('%02d%02d', $month, $day);

$counts = [];
$fetchErrors = [];

// ── comemoracoes -> {"comemoracoes": ["string", ...]} ──────────────────────
$comemoracoes = calImportFetchJson(CALENDAR_API_IMPORT_BASE . '/comemoracoes/' . $mmdd);
if ($comemoracoes !== null && !empty($comemoracoes['comemoracoes']) && is_array($comemoracoes['comemoracoes'])) {
    $items = [];
    foreach ($comemoracoes['comemoracoes'] as $name) {
        $name = trim((string) $name);
        if ($name !== '') {
            $items[] = ['title' => $name];
        }
    }
    $counts['commemoration'] = calendarEntryReplaceFromSource('commemoration', $month, $day, CALENDAR_API_IMPORT_SOURCE, $items);
} elseif ($comemoracoes === null) {
    $fetchErrors[] = 'comemoracoes: falha ao consultar a API externa.';
} else {
    $counts['commemoration'] = calendarEntryReplaceFromSource('commemoration', $month, $day, CALENDAR_API_IMPORT_SOURCE, []);
}

// ── eventos -> {"eventos": [{"ano":1900,"descricao":"..."}]} ───────────────
$eventos = calImportFetchJson(CALENDAR_API_IMPORT_BASE . '/eventos/' . $mmdd);
if ($eventos !== null && !empty($eventos['eventos']) && is_array($eventos['eventos'])) {
    $items = [];
    foreach ($eventos['eventos'] as $ev) {
        $desc = trim((string) ($ev['descricao'] ?? ''));
        if ($desc !== '') {
            $items[] = ['title' => $desc, 'year' => isset($ev['ano']) ? (int) $ev['ano'] : null];
        }
    }
    $counts['event'] = calendarEntryReplaceFromSource('event', $month, $day, CALENDAR_API_IMPORT_SOURCE, $items);
} elseif ($eventos === null) {
    $fetchErrors[] = 'eventos: falha ao consultar a API externa.';
} else {
    $counts['event'] = calendarEntryReplaceFromSource('event', $month, $day, CALENDAR_API_IMPORT_SOURCE, []);
}

// ── santos -> {"santos": [{"nome":"...","link":"https://..."|null}]} ───────
$santos = calImportFetchJson(CALENDAR_API_IMPORT_BASE . '/santos/' . $mmdd);
if ($santos !== null && !empty($santos['santos']) && is_array($santos['santos'])) {
    $items = [];
    foreach ($santos['santos'] as $s) {
        $name = trim((string) ($s['nome'] ?? ''));
        if ($name !== '') {
            $items[] = ['title' => $name, 'link' => $s['link'] ?? null];
        }
    }
    $counts['saint'] = calendarEntryReplaceFromSource('saint', $month, $day, CALENDAR_API_IMPORT_SOURCE, $items);
} elseif ($santos === null) {
    $fetchErrors[] = 'santos: falha ao consultar a API externa.';
} else {
    $counts['saint'] = calendarEntryReplaceFromSource('saint', $month, $day, CALENDAR_API_IMPORT_SOURCE, []);
}

$total = array_sum($counts);
auditLog($adminId, 'import', 'calendar_entries', sprintf('%02d/%02d: %d itens', $day, $month, $total));

echo json_encode([
    'status' => 'success',
    'data' => [
        'month' => $month,
        'day' => $day,
        'counts' => $counts,
        'total' => $total,
        'errors' => $fetchErrors,
    ],
], JSON_UNESCAPED_UNICODE);
