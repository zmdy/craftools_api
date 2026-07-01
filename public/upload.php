<?php
/**
 * upload.php — porta de entrada pública do link de upload de fotos.
 *
 * Resolve ?token=, e conforme o estado do link:
 *   - token inválido/inexistente  → tela de erro
 *   - status 'submitted'          → tela travada ("fotos já enviadas")
 *   - status 'pending'            → serve a cópia do photo_uploader
 *     (public/upload_uploader.html) já personalizada com o nome do cliente,
 *     o kit escolhido pelo admin e a quantidade de fotos.
 *
 * Sem login/CSRF — é a própria posse do token de 64 caracteres que autoriza
 * o acesso (mesmo esquema de api_tokens/resolveApiToken(), nunca o valor em
 * texto puro é comparado/persistido, só o hash).
 */

require_once __DIR__ . '/../src/bootstrap.php';

// Não é uma API JSON, mas o HTML desta página usa <script type="module"> e
// <style> inline extensivamente (é uma cópia adaptada do editor, não uma tela
// nova) — a CSP padrão (script-src 'self', sem 'unsafe-inline') quebraria o
// editor. applySecurityHeaders(true) mantém os demais cabeçalhos de proteção
// e só pula a CSP restritiva, em vez de criar uma política solta à parte.
applySecurityHeaders(true);

function renderMessagePage(string $title, string $message, string $icon = 'error'): void {
    ?><!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($title) ?> · CraftTools</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600&display=swap">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200">
<style>
    * { box-sizing: border-box; }
    body {
        margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
        background: #f4f4f5; font-family: 'DM Sans', sans-serif; color: #18181b; padding: 24px;
    }
    .card {
        max-width: 420px; width: 100%; background: #fff; border: 1px solid #e4e4e7;
        border-radius: 16px; padding: 36px 28px; text-align: center;
    }
    .card .material-symbols-outlined { font-size: 48px; color: #f97316; }
    .card h1 { font-size: 19px; margin: 14px 0 8px; }
    .card p { font-size: 14px; color: #71717a; line-height: 1.5; margin: 0; }
</style>
</head>
<body>
    <div class="card">
        <span class="material-symbols-outlined"><?= e($icon) ?></span>
        <h1><?= e($title) ?></h1>
        <p><?= e($message) ?></p>
    </div>
</body>
</html><?php
}

$rawToken = (string) ($_GET['token'] ?? '');

if (!rateLimitCheck('upload_link_view:' . clientIp(), 60, 300)) {
    http_response_code(429);
    renderMessagePage('Muitas tentativas', 'Aguarde alguns minutos e tente novamente.', 'hourglass_top');
    exit;
}

$link = $rawToken !== '' ? uploadLinkResolveByToken($rawToken) : null;

if (!$link) {
    http_response_code(404);
    renderMessagePage('Link inválido', 'Este link de upload não existe ou foi removido. Peça um novo link para a equipe.', 'link_off');
    exit;
}

if ($link['status'] === 'submitted') {
    renderMessagePage(
        'Fotos já enviadas',
        'Você já enviou suas fotos por este link em ' . ($link['submitted_at'] ?? '') . '. '
        . 'Se precisar alterar algo, entre em contato com a nossa equipe.',
        'check_circle'
    );
    exit;
}

$gridSizeShape = null;
if (!empty($link['grid_size_id'])) {
    $gridRow = gridSizeFind((int) $link['grid_size_id']);
    if ($gridRow) {
        $gridSizeShape = gridSizeToApiShape($gridRow);
    }
}

$uploadLinkData = [
    'token' => $rawToken,
    'clientName' => $link['client_name'],
    'photoCount' => (int) $link['photo_count'],
    'gridSize' => $gridSizeShape,
    'saveUrl' => 'upload_save.php',
];

$template = file_get_contents(__DIR__ . '/upload_uploader.html');
// HEX_TAG/AMP/APOS/QUOT evitam que um nome de cliente com "</script>" ou
// aspas quebre para fora da tag ao ser embutido diretamente no HTML.
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$injection = '<script>window.UPLOAD_LINK = ' . json_encode($uploadLinkData, $jsonFlags) . ';</script>';
$template = str_replace('<!--UPLOAD_LINK_DATA_MARKER-->', $injection, $template);

echo $template;
