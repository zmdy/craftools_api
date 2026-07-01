<?php
/**
 * upload_save.php — recebe o "Salvar" do photo_uploader adaptado (upload.php).
 * multipart/form-data: token, meta (JSON com legendas/fundo/ajustes/transform),
 * photos[] (arquivos). Salva as fotos em storage/uploads/<uuid>/ (fora de
 * public/) e trava o link (status -> 'submitted'), mesmo comportamento pedido
 * para o cliente não conseguir reenviar por engano.
 */

require_once __DIR__ . '/../src/bootstrap.php';
applySecurityHeaders(true);
header('Content-Type: application/json; charset=utf-8');

function jsonError(int $code, string $message): void {
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError(405, 'Método não permitido.');
}

if (!rateLimitCheck('upload_link_save:' . clientIp(), 20, 300)) {
    jsonError(429, 'Muitas tentativas. Aguarde alguns minutos e tente novamente.');
}

$rawToken = (string) ($_POST['token'] ?? '');
$link = $rawToken !== '' ? uploadLinkResolveByToken($rawToken) : null;
if (!$link) {
    jsonError(404, 'Link inválido ou expirado.');
}
if ($link['status'] === 'submitted') {
    jsonError(409, 'Este link já foi utilizado — as fotos já foram enviadas.');
}

$meta = json_decode((string) ($_POST['meta'] ?? ''), true);
if (!is_array($meta)) {
    jsonError(400, 'Dados de envio inválidos.');
}

if (empty($_FILES['photos']) || empty($_FILES['photos']['name']) || !is_array($_FILES['photos']['name'])) {
    jsonError(400, 'Nenhuma foto recebida.');
}

$total = count($_FILES['photos']['name']);
$savedPhotos = [];

try {
    for ($i = 0; $i < $total; $i++) {
        if ((int) $_FILES['photos']['error'][$i] === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $fileItem = [
            'name' => $_FILES['photos']['name'][$i],
            'type' => $_FILES['photos']['type'][$i],
            'tmp_name' => $_FILES['photos']['tmp_name'][$i],
            'error' => $_FILES['photos']['error'][$i],
            'size' => $_FILES['photos']['size'][$i],
        ];
        $result = uploadLinkSavePhoto($fileItem, $link['uuid'], $i);
        $savedPhotos[] = [
            'index' => $i,
            'filename' => $result['file_name'],
            'originalName' => $fileItem['name'],
            'width' => $result['width'],
            'height' => $result['height'],
        ];
    }
} catch (RuntimeException $ex) {
    // Falha parcial: não deixa uma pasta com fotos incompletas/inconsistentes.
    uploadLinksRemoveDir(CRAFTOOLS_API_STORAGE . '/uploads/' . $link['uuid']);
    jsonError(400, $ex->getMessage());
}

if (!$savedPhotos) {
    jsonError(400, 'Nenhuma foto válida recebida.');
}

$submission = [
    'clientName' => (string) ($meta['clientName'] ?? $link['client_name']),
    'gridSizeName' => (string) ($meta['gridSizeName'] ?? ($link['grid_size_name'] ?? '')),
    'captions' => $meta['captions'] ?? [],
    'backgrounds' => $meta['backgrounds'] ?? [],
    'adjust' => $meta['adjust'] ?? [],
    'transforms' => $meta['transforms'] ?? [],
    'photos' => $savedPhotos,
];

uploadLinkMarkSubmitted((int) $link['id'], json_encode($submission, JSON_UNESCAPED_UNICODE));
auditLog(null, 'submit', 'upload_links', (string) $link['id']);

echo json_encode(['status' => 'success'], JSON_UNESCAPED_UNICODE);
