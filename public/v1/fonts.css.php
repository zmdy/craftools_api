<?php
/**
 * public/v1/fonts.css.php — Endpoint de CSS dinâmico que gera regras @font-face
 * para as fontes cadastradas na craftools_api.
 *
 * Exemplo de chamada:
 *   /v1/fonts.css.php?family=DM+Sans:400,700|DM+Serif+Display:400
 */

require_once __DIR__ . '/../../src/bootstrap.php';

header('Content-Type: text/css; charset=utf-8');
applySecurityHeaders(true);

$allowedOrigin = env('API_ALLOWED_ORIGIN', '*');
header('Access-Control-Allow-Origin: ' . $allowedOrigin);
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Cache HTTP nativo de 24 horas no navegador
header('Cache-Control: public, max-age=86400');

$familyParam = isset($_GET['family']) ? trim((string) $_GET['family']) : '';
if ($familyParam === '') {
    // Retorna CSS vazio se nenhuma família for solicitada
    exit;
}

// Parse do parâmetro ?family=Family+Name:400,700,400i|Other+Family:400
$requestedFamilies = [];
$parts = explode('|', $familyParam);
foreach ($parts as $part) {
    $part = trim($part);
    if ($part === '') continue;

    $subParts = explode(':', $part, 2);
    $familyName = str_replace('+', ' ', trim($subParts[0]));
    $weightsStyles = [];

    if (isset($subParts[1]) && trim($subParts[1]) !== '') {
        $specifiers = explode(',', trim($subParts[1]));
        foreach ($specifiers as $spec) {
            $spec = strtolower(trim($spec));
            $isItalic = false;
            if (substr($spec, -1) === 'i') {
                $isItalic = true;
                $spec = substr($spec, 0, -1);
            }
            $weight = (int) $spec;
            if ($weight > 0) {
                $weightsStyles[] = [
                    'weight' => $weight,
                    'style' => $isItalic ? 'italic' : 'normal',
                ];
            }
        }
    }

    $requestedFamilies[$familyName] = $weightsStyles;
}

if (empty($requestedFamilies)) {
    exit;
}

$tokenResult = resolveApiToken();
$tier = $tokenResult['tier'] ?? 'free';
$catalog = fontFamiliesForApi($tier);

$catalogByName = [];
foreach ($catalog as $f) {
    $catalogByName[mb_strtolower($f['name'])] = $f;
}

$formatMap = [
    'woff2' => "format('woff2')",
    'woff'  => "format('woff')",
    'ttf'   => "format('truetype')",
    'otf'   => "format('opentype')",
];

$schemeAndHost = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
// Extrai base do script (ex: /v1)
$baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/v1'), '/\\');

$cssRules = [];

foreach ($requestedFamilies as $reqName => $reqSpecs) {
    $lookupKey = mb_strtolower($reqName);
    if (!isset($catalogByName[$lookupKey])) {
        continue;
    }

    $familyData = $catalogByName[$lookupKey];
    $files = $familyData['files'] ?? [];
    if (empty($files)) {
        continue;
    }

    // Se nenhuma especificação de peso/estilo foi informada, gera para todos os arquivos da família
    $specsToMatch = !empty($reqSpecs) ? $reqSpecs : null;

    // Agrupa arquivos por (weight + style)
    $groupedFiles = [];
    foreach ($files as $file) {
        $key = $file['weight'] . '_' . $file['style'];
        $groupedFiles[$key][] = $file;
    }

    foreach ($groupedFiles as $key => $fileGroup) {
        [$w, $s] = explode('_', $key);
        $weight = (int) $w;
        $style = $s;

        if ($specsToMatch !== null) {
            $matched = false;
            foreach ($specsToMatch as $spec) {
                if ($spec['weight'] === $weight && $spec['style'] === $style) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                continue;
            }
        }

        $srcParts = [];
        // Ordena preferências de formato: woff2 -> woff -> ttf -> otf
        usort($fileGroup, function ($a, $b) {
            $order = ['woff2' => 1, 'woff' => 2, 'ttf' => 3, 'otf' => 4];
            return ($order[$a['format']] ?? 9) <=> ($order[$b['format']] ?? 9);
        });

        foreach ($fileGroup as $file) {
            $fullUrl = $schemeAndHost . $file['api_url'];
            $fmt = $formatMap[$file['format']] ?? '';
            $srcParts[] = "url('{$fullUrl}') " . $fmt;
        }

        if (!empty($srcParts)) {
            $srcCss = implode(', ', $srcParts);
            $cssRules[] = "@font-face {\n  font-family: '{$familyData['name']}';\n  font-style: {$style};\n  font-weight: {$weight};\n  font-display: swap;\n  src: {$srcCss};\n}";
        }
    }
}

echo implode("\n\n", $cssRules);
