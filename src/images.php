<?php
/**
 * images.php — pipeline de upload/conversão de imagens (overlay/background).
 *
 * Toda imagem enviada é decodificada e regravada via GD como WebP. Isso tem
 * duas funções: (1) padronizar formato/tamanho para o que o ApiPicker.js já
 * consome, e (2) servir como camada de segurança — a regravação descarta
 * qualquer dado fora da estrutura de pixels da imagem (EXIF malicioso,
 * polyglots, scripts embutidos em PNG/JPEG), pois o GD só lê e re-emite os
 * pixels decodificados. Porta e reforça processAndConvertToWebp() de
 * api/index.php.
 */

const IMG_ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp'];
const IMG_MAX_UPLOAD_BYTES = 15728640; // 15MB
const IMG_MAX_WIDTH = 2000;
const IMG_WEBP_QUALITY = 82;
const IMG_MAX_MEGAPIXELS = 60000000;

/**
 * Valida um item de $_FILES e grava o resultado convertido em $destinationPath.
 * Lança RuntimeException com mensagem segura para exibir ao admin em qualquer falha.
 *
 * @return array{width:int,height:int,size_bytes:int}
 */
function handleImageUpload(array $file, string $destinationPath): array {
    if (!isset($file['error']) || is_array($file['error'])) {
        throw new RuntimeException('Upload inválido.');
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Falha no upload (código ' . (int) $file['error'] . ').');
    }
    if (!isset($file['size'], $file['tmp_name']) || $file['size'] <= 0 || $file['size'] > IMG_MAX_UPLOAD_BYTES) {
        throw new RuntimeException('Arquivo vazio ou maior que o limite permitido (15MB).');
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('Upload suspeito rejeitado.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if (!in_array($mime, IMG_ALLOWED_MIME, true)) {
        throw new RuntimeException('Tipo de arquivo não suportado (' . e($mime) . '). Use JPEG, PNG ou WebP.');
    }

    $info = @getimagesize($file['tmp_name']);
    if ($info === false) {
        throw new RuntimeException('Arquivo não é uma imagem válida.');
    }

    $destDir = dirname($destinationPath);
    if (!is_dir($destDir) && !mkdir($destDir, 0775, true) && !is_dir($destDir)) {
        throw new RuntimeException('Não foi possível criar a pasta de destino.');
    }

    // Garante que o destino realmente fica dentro da pasta pública de assets,
    // mesmo que algum chamador futuro monte $destinationPath a partir de
    // input menos confiável do que os uuids gerados internamente hoje.
    assertPathInsideBase($destDir, CRAFTOOLS_API_ROOT . '/public/v1/assets');

    [$width, $height] = processAndConvertToWebp($file['tmp_name'], $destinationPath, IMG_MAX_WIDTH, IMG_WEBP_QUALITY);

    return [
        'width' => $width,
        'height' => $height,
        'size_bytes' => (int) filesize($destinationPath),
    ];
}

/**
 * Garante que $path (após resolução de symlinks/"..") continua dentro de $base.
 * Defesa contra path traversal em qualquer rotina que monte caminhos a partir
 * de input externo (uuid de coleção/imagem, nomes de arquivo etc.).
 */
function assertPathInsideBase(string $path, string $base): void {
    $baseReal = realpath($base);
    $pathReal = realpath($path) ?: $path; // path pode ainda não existir (ex.: arquivo de destino)
    if ($baseReal === false) {
        throw new RuntimeException('Pasta base inválida.');
    }
    if (strpos($pathReal . DIRECTORY_SEPARATOR, $baseReal . DIRECTORY_SEPARATOR) !== 0) {
        throw new RuntimeException('Caminho fora da pasta permitida.');
    }
}

/**
 * Decodifica a imagem original via GD, redimensiona (se necessário) e grava
 * como WebP. Apenas pixels decodificados são reescritos — nenhum byte do
 * arquivo de origem é copiado diretamente.
 *
 * @return array{0:int,1:int} [largura final, altura final]
 */
function processAndConvertToWebp(string $source, string $destination, int $maxWidth = 2000, int $quality = 82): array {
    $info = @getimagesize($source);
    if ($info === false) {
        throw new RuntimeException('Não foi possível ler as dimensões da imagem.');
    }
    $origWidth = $info[0];
    $origHeight = $info[1];
    $type = $info[2];

    if ($origWidth * $origHeight > IMG_MAX_MEGAPIXELS) {
        throw new RuntimeException('Imagem excede o limite de megapixels permitido.');
    }

    switch ($type) {
        case IMAGETYPE_JPEG:
            $image = @imagecreatefromjpeg($source);
            break;
        case IMAGETYPE_PNG:
            $image = @imagecreatefrompng($source);
            break;
        case IMAGETYPE_WEBP:
            $image = @imagecreatefromwebp($source);
            break;
        case IMAGETYPE_GIF:
            // bulk_import_original() em public/actions.php aceita .gif na whitelist
            // de extensões; sem este case, essa conversão sempre falhava com
            // "Formato de imagem não suportado" (engolido silenciosamente pelo
            // catch do import em lote). GIFs animados ficam só com o primeiro
            // quadro — esperado, já que a saída é sempre uma imagem estática.
            $image = @imagecreatefromgif($source);
            break;
        default:
            throw new RuntimeException('Formato de imagem não suportado.');
    }
    if (!$image) {
        throw new RuntimeException('Falha ao decodificar a imagem.');
    }

    $width = $origWidth;
    $height = $origHeight;

    if ($width > $maxWidth) {
        $height = (int) round($height * ($maxWidth / $width));
        $width = $maxWidth;
        $resized = imagecreatetruecolor($width, $height);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $width, $height, $origWidth, $origHeight);
        imagedestroy($image);
        $image = $resized;
    }

    $ok = imagewebp($image, $destination, $quality);
    imagedestroy($image);

    if (!$ok) {
        throw new RuntimeException('Falha ao gravar o arquivo WebP.');
    }
    @chmod($destination, 0644);

    return [$width, $height];
}
