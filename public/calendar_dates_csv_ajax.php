<?php
/**
 * calendar_dates_csv_ajax.php — processa lotes de feriados/comemorações/
 * santos/eventos vindos do importador CSV (mesmo padrão de phrases_csv_ajax.php).
 *
 * POST  items=<JSON array>   — insere um lote (máx. 50 por requisição)
 *
 * Cada item do array deve conter:
 *   category      (string, obrigatório: holiday|commemoration_main|commemoration_misc|saint|event)
 *   month, day    (int, obrigatórios)
 *   title         (string, obrigatório)
 *   year          (int, obrigatório se category === 'event')
 *   link          (string, opcional -- só usado se category === 'saint')
 *   holiday_scope (string, opcional -- só usado se category === 'holiday')
 *   uf, city      (string, opcionais -- holiday)
 *   description   (string, opcional)
 *   tier          (string, padrão: free)
 */

require_once __DIR__ . '/../src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
applySecurityHeaders(true);

function csvAjaxError(int $httpCode, string $message): void {
    http_response_code($httpCode);
    echo json_encode(['status' => 'error', 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

// Sessão admin: responde 401 JSON em vez de redirecionar para o login
if (!isAdminLoggedIn()) {
    csvAjaxError(401, 'Sessão expirada. Atualize a página e faça login novamente.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    csvAjaxError(405, 'Método não permitido.');
}

requireCsrf();

const CALENDAR_CSV_MAX_BATCH = 50;
const CALENDAR_VALID_TIERS   = ['free', 'plus', 'premium'];

$adminId  = (int) ($_SESSION['admin_id'] ?? 0);
$itemsRaw = json_decode((string) ($_POST['items'] ?? '[]'), true);

if (!is_array($itemsRaw) || count($itemsRaw) === 0) {
    csvAjaxError(400, 'Nenhum item para processar.');
}
if (count($itemsRaw) > CALENDAR_CSV_MAX_BATCH) {
    csvAjaxError(400, 'Lote grande demais (máximo ' . CALENDAR_CSV_MAX_BATCH . ' itens por requisição).');
}

// Fonte (opcional): marca todos os registros deste import com o mesmo valor
// de "source", em texto puro -- não passa por find-or-create (diferente de
// phrase_collections), é só um rótulo livre gravado na própria linha.
$source = trim((string) ($_POST['source'] ?? ''));

$results = [];

foreach ($itemsRaw as $item) {
    $title = trim((string) ($item['title'] ?? ''));
    $entry = ['title' => mb_substr($title, 0, 80)];

    try {
        $category = (string) ($item['category'] ?? '');
        if (!in_array($category, CALENDAR_ENTRY_CATEGORIES, true)) {
            throw new RuntimeException('Categoria inválida.');
        }
        if ($title === '') {
            throw new RuntimeException('Título não pode ser vazio.');
        }
        $month = (int) ($item['month'] ?? 0);
        $day   = (int) ($item['day'] ?? 0);
        if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
            throw new RuntimeException('Mês/dia inválidos.');
        }
        if ($category === 'event' && trim((string) ($item['year'] ?? '')) === '') {
            throw new RuntimeException('Eventos históricos exigem o ano.');
        }

        $tier = in_array($item['tier'] ?? '', CALENDAR_VALID_TIERS, true) ? $item['tier'] : 'free';

        $newId = calendarEntryCreate([
            'category'      => $category,
            'month'         => $month,
            'day'           => $day,
            'year'          => $item['year'] ?? null,
            'title'         => $title,
            'description'   => $item['description'] ?? '',
            'link'          => $item['link'] ?? null,
            'holiday_scope' => $item['holiday_scope'] ?? null,
            'uf'            => $item['uf'] ?? null,
            'city'          => $item['city'] ?? null,
            'source'        => $source !== '' ? $source : 'csv',
            'tier'          => $tier,
            'active'        => 1,
        ]);

        auditLog($adminId, 'create', 'calendar_entries', (string) $newId);

        $entry['status'] = 'ok';
        $entry['id']     = $newId;
    } catch (Throwable $e) {
        $entry['status']  = 'error';
        $entry['message'] = $e->getMessage();
    }

    $results[] = $entry;
}

echo json_encode(['status' => 'success', 'data' => ['results' => $results]], JSON_UNESCAPED_UNICODE);
