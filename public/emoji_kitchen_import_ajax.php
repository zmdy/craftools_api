<?php
/**
 * emoji_kitchen_import_ajax.php — processa lotes de combos do Emoji Kitchen
 * vindos do importador do metadata.json (ver views/emoji_kitchen.php e
 * assets/emoji-kitchen-import.js).
 *
 * POST  items=<JSON array>   — insere/atualiza um lote de combos (máx. 1000
 *                               por requisição, upsert por par de codepoints)
 *
 * Cada item do array deve seguir o formato do metadata.json original de
 * https://github.com/xsalazar/emoji-kitchen:
 *   leftEmoji, rightEmoji, leftEmojiCodepoint, rightEmojiCodepoint, gStaticUrl, isLatest
 *
 * Diferente do importador de frases (phrases_csv_ajax.php), aqui NÃO se
 * grava um registro de auditoria por item -- o volume esperado (dezenas de
 * milhares de combos) tornaria isso proibitivamente lento. Um único registro
 * de auditoria é gravado resumindo o lote inteiro.
 */

require_once __DIR__ . '/../src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
applySecurityHeaders(true);

function ekAjaxError(int $httpCode, string $message): void {
    http_response_code($httpCode);
    echo json_encode(['status' => 'error', 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

// Sessão admin: responde 401 JSON em vez de redirecionar para o login
if (!isAdminLoggedIn()) {
    ekAjaxError(401, 'Sessão expirada. Atualize a página e faça login novamente.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ekAjaxError(405, 'Método não permitido.');
}

requireCsrf();

const EMOJI_KITCHEN_MAX_BATCH = 1000;

$adminId  = (int) ($_SESSION['admin_id'] ?? 0);
$itemsRaw = json_decode((string) ($_POST['items'] ?? '[]'), true);

if (!is_array($itemsRaw) || count($itemsRaw) === 0) {
    ekAjaxError(400, 'Nenhum item para processar.');
}
if (count($itemsRaw) > EMOJI_KITCHEN_MAX_BATCH) {
    ekAjaxError(400, 'Lote grande demais (máximo ' . EMOJI_KITCHEN_MAX_BATCH . ' itens por requisição).');
}

try {
    $inserted = emojiKitchenUpsertBatch($itemsRaw);
} catch (Throwable $e) {
    ekAjaxError(500, 'Falha ao gravar o lote: ' . $e->getMessage());
}

auditLog($adminId, 'import', 'emoji_kitchen_combos', $inserted . '/' . count($itemsRaw));

echo json_encode([
    'status' => 'success',
    'data' => [
        'processed' => $inserted,
        'received' => count($itemsRaw),
        'total' => emojiKitchenCount(),
    ],
], JSON_UNESCAPED_UNICODE);
