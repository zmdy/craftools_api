<?php
/**
 * bin/seed_default_fonts.php — popula font_families/font_files com os
 * arquivos reais das fontes do catálogo estático do frontend
 * (craftools/utils/FontList.ts's FONTS, exceto as SYSTEM_FONTS -- essas
 * são assumidas pré-instaladas no SO e nunca passam por esta API).
 *
 * Motivo: craftools_api/public/v1/fonts.css.php só gera @font-face para
 * famílias que já existem no banco -- qualquer nome pedido que não esteja
 * cadastrado é silenciosamente ignorado (sem fallback próprio). Com o
 * catálogo praticamente vazio (só a família de teste "Teste"), toda fonte
 * não-DM-* do seletor do editor ficava sem nenhum arquivo real por trás.
 *
 * Busca cada família direto do Google Fonts (fonts.googleapis.com/css2)
 * SEM enviar um User-Agent moderno -- isso faz o Google responder com um
 * único arquivo .ttf não-subsetado por peso/estilo (sem divisão por
 * unicode-range), garantindo cobertura completa dos acentos do português
 * (ã, ç, õ, ...) em UM arquivo só, em vez de ter que escolher entre vários
 * blocos "latin"/"latin-ext"/"vietnamese" que o Google devolve pra UAs
 * modernos (que pedem woff2 com subsetting).
 *
 * Idempotente: pula qualquer família cujo nome já exista no banco.
 *
 * Uso:
 *     php bin/seed_default_fonts.php
 */

require_once __DIR__ . '/../src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script só pode ser executado via linha de comando.');
}

/**
 * Nome -> [categoria, query string do Google Fonts css2 (sem o "family=")].
 * Pesos escolhidos para cobrir o uso real do app: regular/bold/italic para
 * as fontes de texto corrido (DM Sans/DM Mono/Open Sans/Quicksand), e só o
 * que cada fonte decorativa realmente tem disponível (a maioria só existe
 * em peso 400).
 */
const SEED_FONTS = [
    'DM Sans'          => ['sans',   'DM+Sans:ital,wght@0,400;0,500;0,700;1,400'],
    'DM Serif Display' => ['serif',  'DM+Serif+Display:ital,wght@0,400;1,400'],
    'DM Mono'          => ['mono',   'DM+Mono:wght@400;500'],
    'Open Sans'        => ['sans',   'Open+Sans:ital,wght@0,400;0,700;1,400'],
    'Pacifico'         => ['script', 'Pacifico'],
    'Lobster'          => ['display','Lobster'],
    'Parisienne'       => ['script', 'Parisienne'],
    'Dancing Script'   => ['script', 'Dancing+Script:wght@400;700'],
    'Quicksand'        => ['sans',   'Quicksand:wght@400;700'],
    'Quintessential'   => ['display','Quintessential'],
    'Grenze Gotisch'   => ['display','Grenze+Gotisch:wght@400;700'],
];

/** Fetches a URL with a bare/legacy User-Agent so Google returns a single
 *  non-subsetted file per weight/style (see file doc comment above). */
function sdfFetch(string $url): ?string {
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: Mozilla/5.0\r\n",
            'timeout' => 15,
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    return $body !== false ? $body : null;
}

$existingNames = array_map(
    static fn(array $f): string => mb_strtolower($f['name']),
    fontFamilyList()
);

$createdFamilies = 0;
$createdFiles = 0;

foreach (SEED_FONTS as $name => [$category, $query]) {
    if (in_array(mb_strtolower($name), $existingNames, true)) {
        echo "· {$name}: já existe no banco, pulando.\n";
        continue;
    }

    $cssUrl = 'https://fonts.googleapis.com/css2?family=' . $query . '&display=swap';
    $css = sdfFetch($cssUrl);
    if ($css === null || $css === '') {
        fwrite(STDERR, "! {$name}: não foi possível buscar o CSS do Google Fonts, pulando.\n");
        continue;
    }

    // Cada bloco @font-face: font-style / font-weight / src url(...) format('...')
    $blocks = [];
    preg_match_all(
        '/@font-face\s*\{([^}]*)\}/s',
        $css,
        $blockMatches
    );
    foreach ($blockMatches[1] as $blockBody) {
        if (!preg_match('/font-style:\s*(\w+)/', $blockBody, $mStyle)) continue;
        if (!preg_match('/font-weight:\s*(\d+)/', $blockBody, $mWeight)) continue;
        if (!preg_match('/src:\s*url\(([^)]+)\)\s*format\([\'"](\w+)[\'"]\)/', $blockBody, $mSrc)) continue;

        $format = strtolower($mSrc[2]);
        // truetype/opentype -> a extensão real de arquivo (schema exige ttf/otf/woff/woff2)
        $format = $format === 'truetype' ? 'ttf' : ($format === 'opentype' ? 'otf' : $format);

        $blocks[] = [
            'style' => strtolower($mStyle[1]),
            'weight' => (int) $mWeight[1],
            'url' => trim($mSrc[1]),
            'format' => $format,
        ];
    }

    if (!$blocks) {
        fwrite(STDERR, "! {$name}: nenhum @font-face reconhecido na resposta do Google Fonts, pulando.\n");
        continue;
    }

    $familyId = fontFamilyCreate([
        'name' => $name,
        'category' => $category,
        'tier' => 'free',
        'sort_order' => $createdFamilies,
    ]);
    $family = fontFamilyFind($familyId);
    $familyUuid = $family['uuid'];

    $destDir = CRAFTOOLS_API_ROOT . '/public/v1/fonts/' . $familyUuid;
    if (!is_dir($destDir) && !mkdir($destDir, 0775, true) && !is_dir($destDir)) {
        fwrite(STDERR, "! {$name}: não foi possível criar {$destDir}, família ficará sem arquivos.\n");
        $createdFamilies++;
        continue;
    }

    $filesForFamily = 0;
    foreach ($blocks as $block) {
        $bytes = sdfFetch($block['url']);
        if ($bytes === null || $bytes === '') {
            fwrite(STDERR, "  ! falha ao baixar {$block['url']}\n");
            continue;
        }

        $fileUuid = uuidv4();
        $fileName = $fileUuid . '.' . $block['format'];
        $destPath = $destDir . '/' . $fileName;
        if (file_put_contents($destPath, $bytes) === false) {
            fwrite(STDERR, "  ! falha ao gravar {$destPath}\n");
            continue;
        }
        @chmod($destPath, 0644);

        fontFileCreate([
            'family_id' => $familyId,
            'weight' => $block['weight'],
            'style' => $block['style'],
            'format' => $block['format'],
            'file_path' => 'v1/fonts/' . $familyUuid . '/' . $fileName,
            'size_bytes' => strlen($bytes),
        ]);
        $filesForFamily++;
        $createdFiles++;
    }

    echo "✓ {$name}: {$filesForFamily} arquivo(s) cadastrado(s).\n";
    $createdFamilies++;
}

echo "\nConcluído: {$createdFamilies} família(s) nova(s), {$createdFiles} arquivo(s) de fonte no total.\n";
