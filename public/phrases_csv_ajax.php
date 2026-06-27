<?php
/**
 * phrases_csv_ajax.php — processa lotes de frases vindas do importador CSV.
 *
 * POST  items=<JSON array>   — insere um lote de frases (máx. 50 por requisição)
 *
 * Cada item do array deve conter:
 *   phrase   (string, obrigatório)
 *   author   (string, opcional)
 *   category (string CSV, ex: "motivacional,amor")
 *   language (string, padrão: pt-br)
 *   tier     (string, padrão: free)
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

const PHRASES_CSV_MAX_BATCH = 50;
const VALID_TIERS           = ['free', 'plus', 'premium'];
const VALID_LANGS           = ['pt-br', 'en', 'es'];

$adminId   = (int) ($_SESSION['admin_id'] ?? 0);
$itemsRaw  = json_decode((string) ($_POST['items'] ?? '[]'), true);

if (!is_array($itemsRaw) || count($itemsRaw) === 0) {
    csvAjaxError(400, 'Nenhum item para processar.');
}
if (count($itemsRaw) > PHRASES_CSV_MAX_BATCH) {
    csvAjaxError(400, 'Lote grande demais (máximo ' . PHRASES_CSV_MAX_BATCH . ' itens por requisição).');
}

$results = [];

foreach ($itemsRaw as $item) {
    $phrase = trim((string) ($item['phrase'] ?? ''));
    $entry  = ['phrase' => mb_substr($phrase, 0, 80)];

    try {
        if ($phrase === '') {
            throw new RuntimeException('Frase não pode ser vazia.');
        }

        $tier = in_array($item['tier'] ?? '', VALID_TIERS, true) ? $item['tier'] : 'free';
        $lang = in_array($item['language'] ?? '', VALID_LANGS, true) ? $item['language'] : 'pt-br';

        $newId = phraseCreate([
            'phrase'   => $phrase,
            'author'   => trim((string) ($item['author'] ?? '')),
            'category' => (string) ($item['category'] ?? ''),
            'language' => $lang,
            'tier'     => $tier,
            'active'   => 1,
        ]);

        auditLog($adminId, 'create', 'phrases', (string) $newId);

        $entry['status'] = 'ok';
        $entry['id']     = $newId;
    } catch (Throwable $e) {
        $entry['status']  = 'error';
        $entry['message'] = $e->getMessage();
    }

    $results[] = $entry;
}

echo json_encode(['status' => 'success', 'data' => ['results' => $results]], JSON_UNESCAPED_UNICODE);
