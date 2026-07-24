<?php
/**
 * shapes.php — pipeline de upload/sanitização de shapes SVG extra.
 *
 * Diferente de images.php (que sempre regrava a imagem via GD como WebP),
 * shapes SVG são mantidos como vetor: o ShapeTool.ts do craftools carrega o
 * markup do SVG e recolore fill/stroke em runtime (ShapeAssetLoader.ts's
 * recolorAssetSvgMarkup()), o que exige o SVG original, não um raster.
 *
 * A segurança aqui vem de um sanitizador (sanitizeSvgMarkup()) que analisa o
 * XML via DOMDocument e remove/rejeita tudo que não seja geometria pura:
 * <script>, <foreignObject>, <style>, atributos "on*" (onload, onclick...) e
 * qualquer href/xlink:href que não seja uma referência interna ("#algo").
 */

const SVG_ALLOWED_MIME = ['image/svg+xml', 'text/xml', 'application/xml', 'text/plain'];
const SVG_MAX_UPLOAD_BYTES = 2097152; // 2MB — shapes são geometria simples, nunca deveriam chegar perto disso
const SVG_DENIED_TAGS = ['script', 'foreignobject', 'iframe', 'embed', 'object', 'link', 'style'];

/**
 * Valida um item de $_FILES, sanitiza o SVG e grava o resultado em
 * $destinationPath. Lança RuntimeException com mensagem segura para exibir
 * ao admin em qualquer falha.
 *
 * @return array{size_bytes:int}
 */
function handleSvgUpload(array $file, string $destinationPath): array {
    if (!isset($file['error']) || is_array($file['error'])) {
        throw new RuntimeException('Upload inválido.');
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Falha no upload (código ' . (int) $file['error'] . ').');
    }
    if (!isset($file['size'], $file['tmp_name']) || $file['size'] <= 0 || $file['size'] > SVG_MAX_UPLOAD_BYTES) {
        throw new RuntimeException('Arquivo vazio ou maior que o limite permitido (2MB).');
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('Upload suspeito rejeitado.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if (!in_array($mime, SVG_ALLOWED_MIME, true)) {
        throw new RuntimeException('Tipo de arquivo não suportado (' . e($mime) . '). Envie um .svg válido.');
    }

    $raw = file_get_contents($file['tmp_name']);
    if ($raw === false || trim($raw) === '') {
        throw new RuntimeException('Arquivo SVG vazio ou ilegível.');
    }

    return writeSanitizedSvg($raw, $destinationPath);
}

/**
 * Mesma sanitização/gravação de handleSvgUpload(), mas a partir de conteúdo
 * já lido em memória (usado pelo importador em lote, que lê os arquivos de
 * assets/original/shapes/*.svg diretamente do disco em vez de $_FILES).
 *
 * @return array{size_bytes:int}
 */
function writeSanitizedSvg(string $raw, string $destinationPath): array {
    $clean = sanitizeSvgMarkup($raw);

    $destDir = dirname($destinationPath);
    if (!is_dir($destDir) && !mkdir($destDir, 0775, true) && !is_dir($destDir)) {
        throw new RuntimeException('Não foi possível criar a pasta de destino.');
    }
    assertPathInsideBase($destDir, CRAFTOOLS_API_ROOT . '/public/v1/shapes');

    if (file_put_contents($destinationPath, $clean) === false) {
        throw new RuntimeException('Falha ao gravar o arquivo SVG.');
    }
    @chmod($destinationPath, 0644);

    return ['size_bytes' => (int) filesize($destinationPath)];
}

/**
 * Analisa $raw como XML, valida que a raiz é <svg> e remove qualquer
 * conteúdo perigoso (tags de script/estilo/embed, handlers "on*", referências
 * externas). Lança RuntimeException se o XML for inválido ou a raiz não for
 * <svg>. Retorna o markup sanitizado (apenas o elemento <svg>, sem XML
 * prolog/DOCTYPE).
 */
function sanitizeSvgMarkup(string $raw): string {
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw); // remove BOM, se houver

    $prevSetting = libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $doc->resolveExternals = false;
    $doc->substituteEntities = false;
    // LIBXML_NONET bloqueia qualquer tentativa de acesso de rede durante o
    // parse (defesa em profundidade contra XXE/SSRF via DTD externa).
    $ok = @$doc->loadXML($raw, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prevSetting);

    if (!$ok) {
        throw new RuntimeException('SVG inválido ou malformado.');
    }

    // Remove qualquer DOCTYPE/ENTITY declarado no arquivo — não há motivo
    // legítimo para um shape SVG ter um, e isso fecha qualquer brecha de XXE
    // que o parse acima ainda não tenha coberto.
    foreach (iterator_to_array($doc->childNodes) as $node) {
        if ($node->nodeType === XML_DOCUMENT_TYPE_NODE) {
            $doc->removeChild($node);
        }
    }

    $root = $doc->documentElement;
    if ($root === null || strtolower($root->localName ?? $root->nodeName) !== 'svg') {
        throw new RuntimeException('Arquivo não é um SVG válido (elemento raiz precisa ser <svg>).');
    }

    svgRemoveDeniedTags($doc);
    svgStripDangerousAttributes($doc->documentElement);

    return $doc->saveXML($doc->documentElement);
}

function svgRemoveDeniedTags(DOMDocument $doc): void {
    $xpath = new DOMXPath($doc);
    $toRemove = [];
    foreach ($xpath->query('//*') as $el) {
        /** @var DOMElement $el */
        $tag = strtolower($el->localName ?? $el->nodeName);
        if (in_array($tag, SVG_DENIED_TAGS, true)) {
            $toRemove[] = $el;
        }
    }
    foreach ($toRemove as $el) {
        // Nullsafe (?->) evitado de propósito: install.php declara PHP 7.2+
        // como requisito mínimo do projeto, e ?-> só existe a partir do 8.0.
        if ($el->parentNode !== null) {
            $el->parentNode->removeChild($el);
        }
    }
}

function svgStripDangerousAttributes(DOMElement $el): void {
    $toRemove = [];
    foreach ($el->attributes as $attr) {
        $name = strtolower($attr->nodeName);
        $value = trim((string) $attr->nodeValue);
        if (strpos($name, 'on') === 0) {
            // onload, onclick, onmouseover... — nenhum atributo de evento é
            // legítimo em um shape estático.
            $toRemove[] = $attr->nodeName;
            continue;
        }
        if (($name === 'href' || $name === 'xlink:href') && $value !== '' && $value[0] !== '#') {
            // Só permite referências internas ao próprio documento
            // (ex.: "#gradient1", usado por <use>/<textPath>/fills com
            // gradiente). Bloqueia javascript:, data:, http(s):// etc.
            $toRemove[] = $attr->nodeName;
        }
    }
    foreach ($toRemove as $name) {
        $el->removeAttribute($name);
    }
    foreach ($el->childNodes as $child) {
        if ($child instanceof DOMElement) {
            svgStripDangerousAttributes($child);
        }
    }
}
