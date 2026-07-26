<?php
/**
 * fonts.php — pipeline de upload e validação de arquivos de fonte (TTF, OTF, WOFF, WOFF2).
 */

const FONT_ALLOWED_EXTENSIONS = ['ttf', 'otf', 'woff', 'woff2'];
const FONT_MAX_UPLOAD_BYTES = 10485760; // 10MB

/**
 * Valida o upload de um arquivo de fonte e grava em $destinationPath.
 *
 * @return array{size_bytes:int, format:string}
 */
function handleFontUpload(array $file, string $destinationPath): array {
    if (!isset($file['error']) || is_array($file['error'])) {
        throw new RuntimeException('Upload inválido.');
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Falha no upload (código ' . (int) $file['error'] . ').');
    }
    if (!isset($file['size'], $file['tmp_name']) || $file['size'] <= 0 || $file['size'] > FONT_MAX_UPLOAD_BYTES) {
        throw new RuntimeException('Arquivo vazio ou maior que o limite permitido (10MB).');
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('Upload suspeito rejeitado.');
    }

    $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    if (!in_array($ext, FONT_ALLOWED_EXTENSIONS, true)) {
        throw new RuntimeException('Extensão de arquivo não suportada (.' . e($ext) . '). Envie .ttf, .otf, .woff ou .woff2.');
    }

    // Validação básica de assinatura de arquivo (magic bytes)
    $handle = fopen($file['tmp_name'], 'rb');
    if (!$handle) {
        throw new RuntimeException('Não foi possível ler o arquivo enviado.');
    }
    $bytes = fread($handle, 4);
    fclose($handle);

    $isValid = false;
    if ($ext === 'ttf') {
        // TrueType: 0x00010000 ou 'true'
        $isValid = ($bytes === "\x00\x01\x00\x00" || $bytes === "true");
    } elseif ($ext === 'otf') {
        // OpenType: 'OTTO' ou TrueType outline 0x00010000
        $isValid = ($bytes === "OTTO" || $bytes === "\x00\x01\x00\x00");
    } elseif ($ext === 'woff') {
        // WOFF: 'wOFF'
        $isValid = ($bytes === "wOFF");
    } elseif ($ext === 'woff2') {
        // WOFF2: 'wOF2'
        $isValid = ($bytes === "wOF2");
    }

    if (!$isValid) {
        throw new RuntimeException('O arquivo enviado não possui uma assinatura binária válida de fonte .' . strtoupper($ext) . '.');
    }

    $destDir = dirname($destinationPath);
    if (!is_dir($destDir) && !mkdir($destDir, 0775, true) && !is_dir($destDir)) {
        throw new RuntimeException('Não foi possível criar a pasta de destino.');
    }
    assertPathInsideBase($destDir, CRAFTOOLS_API_ROOT . '/public/v1/fonts');

    if (!move_uploaded_file($file['tmp_name'], $destinationPath)) {
        throw new RuntimeException('Falha ao mover o arquivo de fonte para o destino.');
    }
    @chmod($destinationPath, 0644);

    return [
        'size_bytes' => (int) filesize($destinationPath),
        'format' => $ext,
    ];
}
