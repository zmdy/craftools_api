<?php
/**
 * upload_link_photo.php — serve uma foto enviada por um cliente (via link de
 * upload) para o admin ver/baixar no painel. As fotos ficam fora de public/
 * (storage/uploads/<uuid>/) justamente para não serem acessíveis por URL
 * direta sem estar logado — este é o único ponto de leitura autorizado.
 */

require_once __DIR__ . '/../src/bootstrap.php';
requireAdminLogin();

$uuid = (string) ($_GET['uuid'] ?? '');
$file = basename((string) ($_GET['file'] ?? '')); // basename() já neutraliza "../"

$link = $uuid !== '' ? uploadLinkFindByUuid($uuid) : null;
if (!$link || $file === '' || !preg_match('/^foto_\d+\.webp$/', $file)) {
    http_response_code(404);
    exit('Arquivo não encontrado.');
}

$path = CRAFTOOLS_API_STORAGE . '/uploads/' . $link['uuid'] . '/' . $file;
try {
    assertPathInsideBase(dirname($path), CRAFTOOLS_API_STORAGE . '/uploads');
} catch (RuntimeException $ex) {
    http_response_code(404);
    exit('Arquivo não encontrado.');
}

if (!is_file($path)) {
    http_response_code(404);
    exit('Arquivo não encontrado.');
}

header('X-Content-Type-Options: nosniff');
header('Content-Type: image/webp');
header('Content-Length: ' . filesize($path));
if (!empty($_GET['download'])) {
    header('Content-Disposition: attachment; filename="' . $file . '"');
}
readfile($path);
