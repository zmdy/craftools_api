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

// Contexto acumulado ao longo da requisição, usado por apiAccessLogRecord()
// em TODO caminho de saída (sucesso ou erro) -- ver v1JsonError() e o echo
// final. $logCtx some enriquecido conforme token/recurso/modo vão sendo
// resolvidos; v1JsonError() lê o que já estiver preenchido até o ponto do erro.
$requestStartedAt = microtime(true);
$logCtx = [
    'resource' => null,
    'mode' => null,
    'query_string' => $_SERVER['QUERY_STRING'] ?? '',
    'token_id' => null,
    'user_id' => null,
    'tier' => 'free',
];

function v1LogAndExit(int $code, ?string $errorMessage = null): void {
    global $logCtx, $requestStartedAt;
    apiAccessLogRecord(array_merge($logCtx, [
        'status_code' => $code,
        'error_message' => $errorMessage,
        'duration_ms' => (int) round((microtime(true) - $requestStartedAt) * 1000),
    ]));
}

function v1JsonError(int $code, string $message): void {
    v1LogAndExit($code, $message);
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
$logCtx['tier'] = $tier;
$logCtx['token_id'] = $tokenResult['token_row']['id'] ?? null;
$logCtx['user_id'] = $tokenResult['token_row']['user_id'] ?? null;

$resource = isset($_GET['resource']) ? strtolower(trim((string) $_GET['resource'])) : '';
$logCtx['resource'] = $resource !== '' ? $resource : null;
$validResources = ['grid-sizes', 'album-templates', 'phrases', 'phrase-collections', 'assets', 'backgrounds', 'overlays', 'collection', 'emoji-kitchen', 'calendar-dates', 'shapes', 'shape-collection'];
if (!in_array($resource, $validResources, true)) {
    v1JsonError(400, 'Recurso inválido. Disponíveis: grid-sizes, album-templates, phrases, phrase-collections, assets, backgrounds, overlays, collection, emoji-kitchen, calendar-dates, shapes, shape-collection.');
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

    // Packs de shapes SVG extra (ex.: assets/shapes/pack_01 do craftools),
    // servidos aqui em vez de embutidos no build via import.meta.glob —
    // mesmo formato de "assets" acima, mas cada item de "shapes" é um SVG
    // (não uma imagem raster).
    //   ?resource=shapes
    //   ?resource=shape-collection&id=<uuid>
    case 'shapes':
        $data = shapeCollectionsForApi($tier);
        break;

    case 'shape-collection':
        $id = isset($_GET['id']) ? preg_replace('/[^a-f0-9\-]/', '', (string) $_GET['id']) : '';
        if ($id === '') {
            v1JsonError(400, 'Parâmetro "id" é obrigatório para a rota "shape-collection".');
        }

        $visibleShapes = shapeCollectionsForApi($tier, $id);
        if ($visibleShapes) {
            $data = $visibleShapes[0];
            break;
        }

        $rawShapeCollection = shapeCollectionFindByUuid($id);
        if ($rawShapeCollection !== null) {
            v1JsonError(403, 'Este pack de shapes requer um nível de acesso superior.');
        }
        v1JsonError(404, 'Pack de shapes não encontrado.');
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
        $logCtx['mode'] = $mode !== '' ? $mode : null;

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

    // Feriados/comemorações/santos/eventos históricos de uma data (mês+dia,
    // sem ano -- recorrente todo ano). Aceita "month"+"day" OU "date=mmdd"
    // (ex.: 25/12 -> date=1225). Sem nenhum dos dois, usa a data atual (UTC),
    // já que o caso de uso mais comum é "o que temos para hoje".
    //   ?resource=calendar-dates&month=12&day=25
    //   ?resource=calendar-dates&date=1225
    case 'calendar-dates':
        $month = null;
        $day = null;
        if (isset($_GET['date']) && preg_match('/^(\d{2})(\d{2})$/', (string) $_GET['date'], $m)) {
            $month = (int) $m[1];
            $day = (int) $m[2];
        } elseif (isset($_GET['month']) && isset($_GET['day'])) {
            $month = (int) $_GET['month'];
            $day = (int) $_GET['day'];
        }
        if ($month === null || $day === null) {
            $now = new DateTime('now', new DateTimeZone('UTC'));
            $month = (int) $now->format('n');
            $day = (int) $now->format('j');
        }
        if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
            v1JsonError(400, 'Parâmetros "month"/"day" (ou "date=mmdd") inválidos.');
        }
        $data = [
            'month' => $month,
            'day' => $day,
        ] + calendarEntryForDate($tier, $month, $day);
        break;
}

v1LogAndExit(200);
echo json_encode(['status' => 'success', 'access_level' => $tier, 'data' => $data], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
