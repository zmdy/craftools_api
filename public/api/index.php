<?php
/**
 * public/api/index.php — API pública de assets, COMPATÍVEL com o contrato
 * legado de api/api/index.php (mesmo formato de resposta, mesmas rotas,
 * mesmo esquema de token). O cliente já em produção
 * (craftools/craftools/tools/album/ApiPicker.js) consome este endpoint sem
 * nenhuma alteração.
 *
 * ÚNICA mudança de comportamento intencional: o projeto legado EXIGIA um
 * token (sem token = 401). Aqui, a ausência de token é tratada como acesso
 * anônimo de tier "free" — decisão de produto do plano de tiers do
 * CraftTools+ (o nível gratuito não deve exigir login). Tokens enviados
 * continuam validados exatamente como antes.
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

function apiJsonError(int $code, string $message): void {
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    apiJsonError(405, 'Método não permitido.');
}

$rateLimitMax = (int) env('API_RATE_LIMIT_PER_IP', 120);
$rateLimitWindow = (int) env('API_RATE_LIMIT_WINDOW_SECONDS', 60);
if (!rateLimitCheck('public_api:' . clientIp(), $rateLimitMax, $rateLimitWindow)) {
    header('Retry-After: ' . $rateLimitWindow);
    apiJsonError(429, 'Limite de requisições excedido. Tente novamente em breve.');
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
    apiJsonError($code, $msg);
}

$accessLevel = $tokenResult['tier'] ?? 'free';

$route = isset($_GET['route']) ? strtolower(trim((string) $_GET['route'])) : 'all';
$validRoutes = ['all', 'backgrounds', 'overlays', 'collection'];
if (!in_array($route, $validRoutes, true)) {
    apiJsonError(400, 'Rota inválida. Rotas disponíveis: all, backgrounds, overlays, collection.');
}

$response = [
    'status' => 'success',
    'access_level' => $accessLevel,
    'data' => [],
];

switch ($route) {
    case 'all':
        $response['data'] = assetCollectionsForApi($accessLevel);
        break;

    case 'backgrounds':
        $response['data'] = assetCollectionsForApi($accessLevel, 'background');
        break;

    case 'overlays':
        $response['data'] = assetCollectionsForApi($accessLevel, 'overlay');
        break;

    case 'collection':
        $id = isset($_GET['id']) ? preg_replace('/[^a-f0-9\-]/', '', (string) $_GET['id']) : '';
        if ($id === '') {
            apiJsonError(400, 'Parâmetro "id" é obrigatório para a rota "collection".');
        }

        $visible = assetCollectionsForApi($accessLevel, null, $id);
        if ($visible) {
            $response['data'] = $visible[0];
            break;
        }

        // Existe mas foi bloqueada pelo tier, ou realmente não existe.
        $rawCollection = assetCollectionFindByUuid($id);
        if ($rawCollection !== null) {
            apiJsonError(403, 'Esta coleção requer um nível de acesso superior.');
        }
        apiJsonError(404, 'Coleção não encontrada.');
        break;
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
