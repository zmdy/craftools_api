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
 * FALLBACK dos feriados nacionais: se o feriados-brasil ainda não publicou
 * o ano pedido (ex.: ano futuro), busca a mesma lista via scraping da
 * página de feriados nacionais da ANBIMA
 * (https://www.anbima.com.br/feriados/fer_nacionais/{ano}.asp -- ver
 * fbFetchAnbimaNacionais()). Feriados estaduais e datas comemorativas não
 * têm fallback -- a ANBIMA só publica o calendário nacional/bancário.
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
 * Busca uma URL e retorna o corpo cru da resposta (string) ou null em
 * qualquer falha (rede, HTTP != 2xx). Mesma estratégia de
 * calImportFetchJson() em calendar_dates_api_import_ajax.php (cURL com
 * fallback pra file_get_contents) -- não compartilhada entre os dois
 * arquivos porque cada um já é standalone/sem dependências entre si, e a
 * função é pequena o bastante pra duplicar sem custo real de manutenção.
 * Usada tanto por fbFetchJsonArray() (GitHub) quanto por
 * fbFetchAnbimaNacionais() (scraping da ANBIMA), já que a segunda precisa
 * do HTML cru, não de JSON já decodificado.
 */
function fbFetchRaw(string $url): ?string {
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
        return $body;
    }
    $context = stream_context_create(['http' => [
        'method'  => 'GET',
        'timeout' => FERIADOS_BRASIL_TIMEOUT,
        'header'  => "User-Agent: CraftToolsAPI-CalendarImport/1.0\r\n",
    ]]);
    $body = @file_get_contents($url, false, $context);
    return $body === false ? null : $body;
}

/**
 * Busca uma URL e decodifica a resposta como JSON (array). Retorna null em
 * qualquer falha (fetch, JSON inválido/não é array) -- o chamador trata
 * isso como "categoria indisponível", nunca interrompe o processamento das
 * outras.
 */
function fbFetchJsonArray(string $url): ?array {
    $body = fbFetchRaw($url);
    if ($body === null) {
        return null;
    }
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

/**
 * Fallback para feriados nacionais quando o feriados-brasil (GitHub) ainda
 * não tem o ano pedido (ex.: ano futuro que o repositório ainda não
 * publicou): faz scraping da página de feriados nacionais da ANBIMA
 * (calendário bancário oficial), que publica uma tabela HTML simples com
 * Data/Dia da Semana/Feriado por ano -- ver
 * https://www.anbima.com.br/feriados/fer_nacionais/{ano}.asp. Usa
 * DOMDocument+XPath (tolerante a HTML malformado) em vez de regex contra a
 * marcação exata, já que essa página não tem contrato/versionamento como o
 * JSON do GitHub e pode mudar de estrutura sem aviso.
 *
 * IMPORTANTE: a ANBIMA lista o calendário BANCÁRIO, que inclui Carnaval e
 * Corpus Christi (ponto facultativo na maior parte do país, não feriado
 * nacional oficial em todo lugar) -- um escopo um pouco mais amplo que a
 * categoria "NACIONAL" estrita do feriados-brasil. Como isso só roda
 * quando o GitHub não tem o ano ainda, aceita-se essa diferença em troca
 * de ter dados em vez de nada.
 *
 * @return array<int,array{day:int,month:int,title:string}>|null null em
 *   qualquer falha (rede, extensão DOM ausente, tabela não encontrada/vazia).
 */
function fbFetchAnbimaNacionais(int $year): ?array {
    if (!class_exists('DOMDocument')) {
        return null;
    }

    $body = fbFetchRaw('https://www.anbima.com.br/feriados/fer_nacionais/' . $year . '.asp');
    if ($body === null) {
        return null;
    }

    // Página antiga (.asp), normalmente publicada em ISO-8859-1 -- converte
    // pra UTF-8 antes do DOMDocument, senão acentos (ç, ã, é...) corrompem.
    $encoding = mb_detect_encoding($body, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true) ?: 'ISO-8859-1';
    if ($encoding !== 'UTF-8') {
        $converted = @iconv($encoding, 'UTF-8//IGNORE', $body);
        if ($converted !== false) {
            $body = $converted;
        }
    }

    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="UTF-8">' . $body);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);
    $rows = $xpath->query('//table//tr');
    if ($rows === false || $rows->length === 0) {
        return null;
    }

    $items = [];
    foreach ($rows as $row) {
        $cells = $xpath->query('.//td', $row);
        if ($cells === false || $cells->length < 3) {
            continue; // linha de cabeçalho ou layout inesperado
        }
        $dateText = trim((string) $cells->item(0)->textContent);
        $title    = trim((string) $cells->item(2)->textContent);
        if (!preg_match('#^(\d{1,2})/(\d{1,2})/\d{2,4}$#', $dateText, $m)) {
            continue;
        }
        $day = (int) $m[1];
        $month = (int) $m[2];
        if ($title === '' || $day < 1 || $day > 31 || $month < 1 || $month > 12) {
            continue;
        }
        $items[] = ['day' => $day, 'month' => $month, 'title' => $title];
    }

    return count($items) > 0 ? $items : null;
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

// ── Feriados nacionais (fallback: ANBIMA se o GitHub ainda não tem o ano) ──
$nacional = fbFetchJsonArray(FERIADOS_BRASIL_BASE . '/feriados/nacional/json/' . $year . '.json');
$nacionalSource = 'github';
if ($nacional === null || count($nacional) === 0) {
    $anbimaItems = fbFetchAnbimaNacionais($year);
    if ($anbimaItems === null) {
        $fetchErrors[] = 'feriados nacionais: sem dados no GitHub para ' . $year . ' e falha ao consultar a ANBIMA como alternativa.';
    } else {
        $nacionalSource = 'anbima';
        foreach ($anbimaItems as $entry) {
            $items[] = [
                'category'    => 'holiday',
                'day'         => $entry['day'],
                'month'       => $entry['month'],
                'title'       => $entry['title'],
                'description' => null, // a tabela da ANBIMA não traz descrição
                'scope'       => 'national',
                'uf'          => null,
            ];
        }
    }
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
            // This GitHub source is a small, hand-curated list of the main
            // commercial/cultural dates (Dia das Mães, Carnaval, Páscoa,
            // etc) -- distinct from the much broader, less curated
            // 'commemoration_misc' list the biduinfo API import produces
            // (see calendar_dates_api_import_ajax.php), so it gets its own
            // category rather than sharing the old single 'commemoration'
            // bucket the two used to be indistinguishable under.
            'category'    => 'commemoration_main',
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

$counts = ['holiday' => 0, 'commemoration_main' => 0];
foreach ($items as $item) {
    $counts[$item['category']] = ($counts[$item['category']] ?? 0) + 1;
}

auditLog($adminId, 'import', 'calendar_entries', 'feriados-brasil ' . $year . ' (nacional via ' . $nacionalSource . '): ' . $inserted . ' itens');

echo json_encode([
    'status' => 'success',
    'data' => [
        'year'           => $year,
        'counts'         => $counts,
        'total'          => $inserted,
        'nacionalSource' => $nacionalSource, // 'github' ou 'anbima' (fallback usado)
        'errors'         => $fetchErrors,
    ],
], JSON_UNESCAPED_UNICODE);
