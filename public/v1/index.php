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
$validResources = ['grid-sizes', 'album-templates', 'phrases', 'phrase-collections', 'assets', 'backgrounds', 'overlays', 'collection', 'emoji-kitchen'];
if (!in_array($resource, $validResources, true)) {
    v1JsonError(400, 'Recurso inválido. Disponíveis: grid-sizes, album-templates, phrases, phrase-collections, assets, backgrounds, overlays, collection, emoji-kitchen.');
}

$data = [];
switch ($resource) {
    case 'grid-sizes':
        $data = gridSizeListActiveForTier($tier);
        break;

    case 'album-templates':
        $data = albumTemplateListActiveForTier($tier);
        break;

    // ?resource=phrases&collection=Ano+Novo+2026 -- "1º nível" de filtro
    // (tema/conjunto); category continua sendo o "2º nível", aplicado sobre
    // as frases já restritas à coleção.
    case 'phrases':
        $category = isset($_GET['category']) ? (string) $_GET['category'] : null;
        $language = isset($_GET['language']) ? (string) $_GET['language'] : null;
        $collection = isset($_GET['collection']) ? (string) $_GET['collection'] : null;
        $limit = intInput($_GET, 'limit', 50, 1, 200);
        $data = phraseListActiveForTier($tier, $category, $language, $limit, $collection);
        break;

    // ?resource=phrase-collections -- lista os nomes das coleções de frases
    // ativas, usado para popular o seletor de "Coleção" no craftools.
    case 'phrase-collections':
        $data = phraseCollectionNames();
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

    // Emoji Kitchen (catálogo importado do metadata.json de
    // https://github.com/xsalazar/emoji-kitchen via painel admin):
    //   ?resource=emoji-kitchen&mode=supported
    //   ?resource=emoji-kitchen&mode=partners&emoji=😀
    //   ?resource=emoji-kitchen&mode=combo&left=😀&right=😃
    //   ?resource=emoji-kitchen&mode=list&emoji=😀&limit=50&offset=0
    case 'emoji-kitchen':
        $mode = isset($_GET['mode']) ? strtolower(trim((string) $_GET['mode'])) : '';

        if ($mode === 'supported') {
            $limit = intInput($_GET, 'limit', 500, 1, 2000);
            $data = emojiKitchenSupportedList($limit);
            break;
        }

        if ($mode === 'partners') {
            $emoji = isset($_GET['emoji']) ? (string) $_GET['emoji'] : '';
            if ($emoji === '') {
                v1JsonError(400, 'Parâmetro "emoji" é obrigatório para mode=partners.');
            }
            $data = emojiKitchenPartners($emoji);
            break;
        }

        if ($mode === 'combo') {
            $left = isset($_GET['left']) ? (string) $_GET['left'] : '';
            $right = isset($_GET['right']) ? (string) $_GET['right'] : '';
            if ($left === '' || $right === '') {
                v1JsonError(400, 'Parâmetros "left" e "right" são obrigatórios para mode=combo.');
            }
            $combo = emojiKitchenFindCombo($left, $right);
            $data = $combo ? [
                'imageUrl' => $combo['image_url'],
                'leftEmoji' => $combo['left_emoji'],
                'rightEmoji' => $combo['right_emoji'],
            ] : null;
            break;
        }

        if ($mode === 'list') {
            $emoji = isset($_GET['emoji']) ? (string) $_GET['emoji'] : '';
            $limit = intInput($_GET, 'limit', 50, 1, 500);
            $offset = intInput($_GET, 'offset', 0, 0, 10000000);
            $rows = emojiKitchenList($limit, $offset, $emoji !== '' ? $emoji : null);
            $data = [
                'total' => emojiKitchenListCount($emoji !== '' ? $emoji : null),
                'limit' => $limit,
                'offset' => $offset,
                'items' => array_map(static function (array $row): array {
                    return [
                        'leftEmoji' => $row['left_emoji'],
                        'rightEmoji' => $row['right_emoji'],
                        'imageUrl' => $row['image_url'],
                        'isLatest' => (bool) $row['is_latest'],
                    ];
                }, $rows),
            ];
            break;
        }

        v1JsonError(400, 'Parâmetro "mode" inválido. Disponíveis: supported, partners, combo, list.');
        break;
}

echo json_encode(['status' => 'success', 'access_level' => $tier, 'data' => $data], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
