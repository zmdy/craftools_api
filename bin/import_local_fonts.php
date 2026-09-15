<?php
/**
 * bin/import_local_fonts.php — bulk-imports a folder of already-licensed
 * local font files (the user's own "fontes/fonts" staging folder) into
 * font_families/font_files, the same catalog bin/seed_default_fonts.php
 * populates with the Google Fonts defaults.
 *
 * Unlike seed_default_fonts.php (downloads from Google Fonts by name),
 * this reads real font FILES already on disk and groups them into
 * families by filename (family name + weight/style suffix, e.g.
 * "Caugen-ExtraBold.ttf" -> family "Caugen", weight 800). One file per
 * (family, weight, style) is registered, picking the best available
 * format per the same preference order fonts.css.php itself uses when
 * choosing which src to list first: woff2 > woff > ttf > otf.
 *
 * Category (sans/serif/mono/display/script -- the DB's CHECK constraint)
 * comes from the folder's own fonts_metadata.json (one entry per file,
 * already category-tagged) when available; the family's own category is
 * the majority vote across its faces. Falls back to 'display' if a face
 * has no metadata entry at all.
 *
 * Idempotent: skips any family whose name (case-insensitive) already
 * exists in the database -- safe to re-run after adding more files to
 * the source folder.
 *
 * Uso:
 *     php bin/import_local_fonts.php "C:\path\to\fonts_dir" ["C:\path\to\fonts_metadata.json"]
 *
 * fonts_dir should contain the actual font files directly (not zips).
 * fonts_metadata.json is optional -- if omitted, looks for
 * fonts_metadata.json next to fonts_dir, else defaults every face to
 * 'display'.
 */

require_once __DIR__ . '/../src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script só pode ser executado via linha de comando.');
}

$fontsDir = $argv[1] ?? null;
if (!$fontsDir || !is_dir($fontsDir)) {
    fwrite(STDERR, "Uso: php bin/import_local_fonts.php <pasta_com_arquivos_de_fonte> [fonts_metadata.json]\n");
    exit(1);
}
$fontsDir = rtrim(str_replace('\\', '/', $fontsDir), '/');

$metadataPath = $argv[2] ?? (dirname($fontsDir) . '/fonts_metadata.json');
$categoryByFile = [];
if (is_file($metadataPath)) {
    $meta = json_decode((string) file_get_contents($metadataPath), true);
    if (is_array($meta)) {
        foreach ($meta as $entry) {
            if (isset($entry['file'], $entry['category'])) {
                $categoryByFile[$entry['file']] = $entry['category'];
            }
        }
    }
    echo "Metadados carregados: " . count($categoryByFile) . " entradas de {$metadataPath}\n";
} else {
    echo "Nenhum fonts_metadata.json encontrado -- todas as famílias cairão em 'display'.\n";
}

const VALID_CATEGORIES = ['sans', 'serif', 'mono', 'display', 'script'];

// Weight keywords, longest/most-specific first so e.g. "extrabold" is tried
// before "bold" would otherwise short-circuit it.
const WEIGHT_MAP = [
    'extrablack' => 950, 'ultrablack' => 950,
    'extrabold' => 800, 'ultrabold' => 800,
    'semibold' => 600, 'demibold' => 600,
    'extralight' => 200, 'ultralight' => 200,
    'black' => 900, 'heavy' => 900,
    'bold' => 700,
    'medium' => 500,
    'light' => 300,
    'thin' => 100,
    'regular' => 400, 'normal' => 400, 'book' => 400,
];

/**
 * Parses "AdjustRanch-Regular" / "Caugen-ExtraBoldItalic" / "KanileItalic-Black" /
 * "Sign-Painting-Corporate-Bold-Slanted" style basenames into
 * [family, weight, italic]. Falls back to weight 400 / non-italic with the
 * WHOLE basename as the family when no recognized weight token is found at
 * all (e.g. "Broadway-SansOne", "LS Olive 01 Script") -- each such file
 * becomes its own single-face "family" rather than risking a wrong merge.
 */
function parseFontBasename(string $base): array {
    $work = $base;

    // "...-Slanted" is a real distinct look (Sign Painting Corporate's
    // slanted set) worth keeping as its own family rather than folding
    // into the upright one -- pull it off first, re-attach to the family
    // name at the end, then keep parsing the remainder for a weight.
    $slanted = false;
    if (preg_match('/^(.*)[-_ ]Slanted$/i', $work, $m)) {
        $slanted = true;
        $work = $m[1];
    }

    $weight = 400;
    $italic = false;
    $matchedWeight = false;

    // Combined "WeightItalic" (e.g. "BoldItalic", "ExtraBoldItalic") or
    // "Italic" alone, or "Weight" alone, as the trailing token.
    if (preg_match('/^(.*)[-_ ](' . implode('|', array_keys(WEIGHT_MAP)) . ')(italic|oblique)$/i', $work, $m)) {
        $work = $m[1];
        $weight = WEIGHT_MAP[strtolower($m[2])];
        $italic = true;
        $matchedWeight = true;
    } elseif (preg_match('/^(.*)[-_ ](italic|oblique)$/i', $work, $m)) {
        $work = $m[1];
        $weight = 400;
        $italic = true;
        $matchedWeight = true;
    } elseif (preg_match('/^(.*)[-_ ](' . implode('|', array_keys(WEIGHT_MAP)) . ')$/i', $work, $m)) {
        $work = $m[1];
        $weight = WEIGHT_MAP[strtolower($m[2])];
        $matchedWeight = true;
    }

    if (!$matchedWeight && !$slanted) {
        // No recognized weight/style token anywhere -- keep the whole
        // original basename as its own standalone family (safest option;
        // see file doc comment).
        return [$base, 400, false];
    }

    // "KanileItalic-Black" -> family prefix "KanileItalic" still carries its
    // own trailing "Italic" after the weight was already stripped.
    if (preg_match('/^(.*)Italic$/i', $work, $m2)) {
        $work = $m2[1];
        $italic = true;
    }

    $family = trim($work, " -_");
    if ($slanted) $family .= ' Slanted';
    if ($family === '') $family = $base;

    return [$family, $weight, $italic];
}

$formatRank = ['woff2' => 1, 'woff' => 2, 'ttf' => 3, 'otf' => 4];

// ── Pass 1: scan files, parse, group into faces (family|weight|italic) ────
$faces = []; // "$family\x1F$weight\x1F$italic" -> ['family'=>, 'weight'=>, 'italic'=>, 'files'=>[ext=>path], 'categories'=>[cat=>count]]

foreach (scandir($fontsDir) as $file) {
    if ($file === '.' || $file === '..') continue;
    $full = $fontsDir . '/' . $file;
    if (!is_file($full)) continue;
    if ($file === '.DS_Store') continue;
    if (stripos($file, 'variable') !== false) continue; // see file doc comment

    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if (!in_array($ext, ['ttf', 'otf', 'woff', 'woff2'], true)) continue;

    $base = pathinfo($file, PATHINFO_FILENAME);
    [$family, $weight, $italic] = parseFontBasename($base);

    $key = $family . "\x1F" . $weight . "\x1F" . ($italic ? '1' : '0');
    if (!isset($faces[$key])) {
        $faces[$key] = ['family' => $family, 'weight' => $weight, 'italic' => $italic, 'files' => [], 'categories' => []];
    }
    // Keep the best-ranked format if this (family,weight,italic) somehow
    // already has one (shouldn't normally happen -- distinct filenames).
    if (!isset($faces[$key]['files'][$ext])) {
        $faces[$key]['files'][$ext] = $full;
    }
    $cat = $categoryByFile[$file] ?? null;
    if ($cat && in_array($cat, VALID_CATEGORIES, true)) {
        $faces[$key]['categories'][$cat] = ($faces[$key]['categories'][$cat] ?? 0) + 1;
    }
}

// ── Pass 2: group faces into families ──────────────────────────────────
$families = []; // familyName -> ['faces'=>[...], 'categories'=>[cat=>count]]
foreach ($faces as $face) {
    $fam = $face['family'];
    if (!isset($families[$fam])) $families[$fam] = ['faces' => [], 'categories' => []];
    $families[$fam]['faces'][] = $face;
    foreach ($face['categories'] as $cat => $count) {
        $families[$fam]['categories'][$cat] = ($families[$fam]['categories'][$cat] ?? 0) + $count;
    }
}

echo "\n" . count($families) . " família(s) detectada(s) a partir de " . array_sum(array_map(fn($f) => count($f['faces']), $families)) . " face(s) (peso+estilo únicos).\n\n";

$existingNames = array_map(
    static fn(array $f): string => mb_strtolower($f['name']),
    fontFamilyList()
);

$createdFamilies = 0;
$createdFiles = 0;
$skippedExisting = 0;

foreach ($families as $famName => $famData) {
    if (in_array(mb_strtolower($famName), $existingNames, true)) {
        echo "· {$famName}: já existe no banco, pulando.\n";
        $skippedExisting++;
        continue;
    }

    // Majority-vote category, default 'display'.
    $category = 'display';
    if ($famData['categories']) {
        arsort($famData['categories']);
        $category = array_key_first($famData['categories']);
    }

    $familyId = fontFamilyCreate([
        'name' => $famName,
        'category' => $category,
        'tier' => 'free',
        'sort_order' => 100 + $createdFamilies, // after the Google Fonts defaults
    ]);
    $family = fontFamilyFind($familyId);
    $familyUuid = $family['uuid'];

    $destDir = CRAFTOOLS_API_ROOT . '/public/v1/fonts/' . $familyUuid;
    if (!is_dir($destDir) && !mkdir($destDir, 0775, true) && !is_dir($destDir)) {
        fwrite(STDERR, "! {$famName}: não foi possível criar {$destDir}, família ficará sem arquivos.\n");
        $createdFamilies++;
        continue;
    }

    $filesForFamily = 0;
    foreach ($famData['faces'] as $face) {
        // Pick the best available format for this face.
        $bestExt = null;
        foreach (['woff2', 'woff', 'ttf', 'otf'] as $candidate) {
            if (isset($face['files'][$candidate])) { $bestExt = $candidate; break; }
        }
        if ($bestExt === null) continue;
        $srcPath = $face['files'][$bestExt];

        $bytes = file_get_contents($srcPath);
        if ($bytes === false) {
            fwrite(STDERR, "  ! falha ao ler {$srcPath}\n");
            continue;
        }

        $fileUuid = uuidv4();
        $fileName = $fileUuid . '.' . $bestExt;
        $destPath = $destDir . '/' . $fileName;
        if (file_put_contents($destPath, $bytes) === false) {
            fwrite(STDERR, "  ! falha ao gravar {$destPath}\n");
            continue;
        }
        @chmod($destPath, 0644);

        fontFileCreate([
            'family_id' => $familyId,
            'weight' => $face['weight'],
            'style' => $face['italic'] ? 'italic' : 'normal',
            'format' => $bestExt,
            'file_path' => 'v1/fonts/' . $familyUuid . '/' . $fileName,
            'size_bytes' => strlen($bytes),
        ]);
        $filesForFamily++;
        $createdFiles++;
    }

    echo "✓ {$famName} [{$category}]: {$filesForFamily} face(s) cadastrada(s).\n";
    $createdFamilies++;
}

echo "\nConcluído: {$createdFamilies} família(s) nova(s), {$createdFiles} arquivo(s) de fonte, {$skippedExisting} já existiam.\n";
