<?php
/**
 * public/v1/index.php — API pública do CrafTools (única servida por este
 * projeto): tamanhos de grid, templates de álbum, banco de frases e
 * coleções de overlay/fundo. Usa resolveApiToken() para token/tier, com
 * formato de resposta simples: {status, data}.
 *
 * Uso: /v1/?resource=grid-sizes
 *      /v1/?resource=album-templates
 *      /v1/?resource=phrases&category=motivacional&language=pt-br&limit=20
 */

require_once __DIR__ . '/../../src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
applySecurityHeaders(true);

$allowedOrigin = env('API_ALLOWED_ORIGIN', '*');
header('Access-Control-Allow-Origin: ' . $allowedOrigin);
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: X-API-Token, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function v1JsonError(int $code, string $message): void {
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    v1JsonError(405, 'Método não permitido.');
}

$rateLimitMax = (int) env('API_RATE_LIMIT_PER_IP', 120);
$rateLimitWindow = (int) env('API_RATE_LIMIT_WINDOW_SECONDS', 60);
if (!rateLimitCheck('public_api_v1:' . clientIp(), $rateLimitMax, $rateLimitWindow)) {
    header('Retry-After: ' . $rateLimitWindow);
    v1JsonError(429, 'Limite de requisições excedido. Tente novamente em breve.');
}

$tokenResult = resolveApiToken();
if (isset($tokenResult['error'])) {
    $errorMessages = [
        'invalid_format' => [401, 'Token inválido.'],
        'not_found' => [403, 'Token não autorizado.'],
        'inactive' => [403, 'Token desativado.'],
        'expired' => [403, 'Token expirado.'],
    ];
    [$code, $msg] = $errorMessages[$tokenResult['error']] ?? [401, 'Token inválido.'];
    v1JsonError($code, $msg);
}
$tier = $tokenResult['tier'] ?? 'free';

$resource = isset($_GET['resource']) ? strtolower(trim((string) $_GET['resource'])) : '';
$validResources = ['grid-sizes', 'album-templates', 'phrases', 'assets', 'backgrounds', 'overlays', 'collection'];
if (!in_array($resource, $validResources, true)) {
    v1JsonError(400, 'Recurso inválido. Disponíveis: grid-sizes, album-templates, phrases, assets, backgrounds, overlays, collection.');
}

$data = [];
switch ($resource) {
    case 'grid-sizes':
        $data = gridSizeListActiveForTier($tier);
        break;

    case 'album-templates':
        $data = albumTemplateListActiveForTier($tier);
        break;

    case 'phrases':
        $category = isset($_GET['category']) ? (string) $_GET['category'] : null;
        $language = isset($_GET['language']) ? (string) $_GET['language'] : null;
        $limit = intInput($_GET, 'limit', 50, 1, 200);
        $data = phraseListActiveForTier($tier, $category, $language, $limit);
        break;

    case 'assets':
        $data = assetCollectionsForApi($tier);
        break;

    case 'backgrounds':
        $data = assetCollectionsForApi($tier, 'background');
        break;

    case 'overlays':
        $data = assetCollectionsForApi($tier, 'overlay');
        break;

    case 'collection':
        $id = isset($_GET['id']) ? preg_replace('/[^a-f0-9\-]/', '', (string) $_GET['id']) : '';
        if ($id === '') {
            v1JsonError(400, 'Parâmetro "id" é obrigatório para a rota "collection".');
        }

        $visible = assetCollectionsForApi($tier, null, $id);
        if ($visible) {
            $data = $visible[0];
            break;
        }

        $rawCollection = assetCollectionFindByUuid($id);
        if ($rawCollection !== null) {
            v1JsonError(403, 'Esta coleção requer um nível de acesso superior.');
        }
        v1JsonError(404, 'Coleção não encontrada.');
        break;
}

echo json_encode(['status' => 'success', 'access_level' => $tier, 'data' => $data], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
