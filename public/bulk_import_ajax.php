<?php
/**
 * bulk_import_ajax.php — endpoint JSON usado pela tela de Importação em Massa
 * (public/views/bulk_import.php + assets/bulk-import.js).
 *
 * GET  ?op=scan      — lista as imagens disponíveis em assets/original/{backgrounds,overlays}/*
 * POST  op=process   — processa um lote pequeno (campo "items") de imagens já
 *                       listadas pelo scan, usando a resolução/qualidade enviadas.
 *
 * Processar em lotes pequenos — em vez da única requisição síncrona que o
 * formulário antigo disparava (e que travava a tela até tudo terminar) — é o
 * que permite ao frontend mostrar uma barra de progresso real a cada resposta.
 */

require_once __DIR__ . '/../src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
applySecurityHeaders(true);

function bulkImportJsonError(int $httpCode, string $message): void {
    http_response_code($httpCode);
    echo json_encode(['status' => 'error', 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

// Guarda de sessão própria (em vez de requireAdminLogin()): essa função
// redireciona via header Location para a tela de login em caso de sessão
// expirada, o que o fetch() do frontend seguiria silenciosamente e tentaria
// interpretar a página de login (HTML) como JSON. Aqui respondemos 401 puro.
if (!isAdminLoggedIn()) {
    bulkImportJsonError(401, 'Sessão expirada. Atualize a página e faça login novamente.');
}

const BULK_IMPORT_TYPES = ['backgrounds' => 'background', 'overlays' => 'overlay'];
const BULK_IMPORT_EXTS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
const BULK_IMPORT_BASE_DIR_REL = 'assets/original';
const BULK_IMPORT_MAX_BATCH = 50;

/** Resolve e valida o caminho de assets/original/{tipo}/{coleção}/{arquivo}. */
function bulkImportResolvePath(string $type, string $collection, string $file): string {
    if (!isset(BULK_IMPORT_TYPES[$type])) {
        throw new RuntimeException('Tipo inválido: ' . $type);
    }
    // Nomes de pasta/arquivo nunca podem conter separadores de caminho ou "..":
    // bloqueia path traversal antes mesmo de montar o caminho.
    foreach (['collection' => $collection, 'file' => $file] as $label => $part) {
        if ($part === '' || $part !== basename($part) || strpos($part, '..') !== false) {
            throw new RuntimeException('Nome de ' . $label . ' inválido.');
        }
    }

    $typeBaseDir = CRAFTOOLS_API_ROOT . '/' . BULK_IMPORT_BASE_DIR_REL . '/' . $type;
    $path = $typeBaseDir . '/' . $collection . '/' . $file;

    assertPathInsideBase(dirname($path), $typeBaseDir);

    if (!is_file($path)) {
        throw new RuntimeException('Arquivo não encontrado no servidor.');
    }
    return $path;
}

$method = $_SERVER['REQUEST_METHOD'];
$op = $method === 'GET' ? (string) ($_GET['op'] ?? '') : (string) ($_POST['op'] ?? '');

try {
    // ------------------------------------------------------------- op=scan
    if ($method === 'GET' && $op === 'scan') {
        $baseDir = CRAFTOOLS_API_ROOT . '/' . BULK_IMPORT_BASE_DIR_REL;
        $collections = [];
        $totalFiles = 0;

        foreach (BULK_IMPORT_TYPES as $folder => $typeStr) {
            $typeDir = $baseDir . '/' . $folder;
            if (!is_dir($typeDir)) {
                continue;
            }

            foreach (new DirectoryIterator($typeDir) as $dirInfo) {
                if ($dirInfo->isDot() || !$dirInfo->isDir()) {
                    continue;
                }

                $files = [];
                foreach (new DirectoryIterator($dirInfo->getPathname()) as $fileInfo) {
                    if ($fileInfo->isDot() || !$fileInfo->isFile()) {
                        continue;
                    }
                    $ext = strtolower($fileInfo->getExtension());
                    if (!in_array($ext, BULK_IMPORT_EXTS, true)) {
                        continue;
                    }
                    $files[] = ['name' => $fileInfo->getFilename(), 'size' => (int) $fileInfo->getSize()];
                }
                if (!$files) {
                    continue; // pasta sem nenhuma imagem válida: não vale a pena listar
                }

                usort($files, fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
                $collections[] = [
                    'type' => $folder,
                    'name' => $dirInfo->getFilename(),
                    'files' => $files,
                ];
                $totalFiles += count($files);
            }
        }

        usort($collections, fn($a, $b) => [$a['type'], $a['name']] <=> [$b['type'], $b['name']]);

        echo json_encode([
            'status' => 'success',
            'data' => [
                'dirExists' => is_dir($baseDir),
                'collections' => $collections,
                'totalFiles' => $totalFiles,
            ],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ---------------------------------------------------------- op=process
    if ($method === 'POST' && $op === 'process') {
        requireCsrf();

        $adminId = (int) ($_SESSION['admin_id'] ?? 0);
        $maxWidth = intInput($_POST, 'max_width', IMG_MAX_WIDTH, 200, 6000);
        $quality = intInput($_POST, 'quality', IMG_WEBP_QUALITY, 1, 100);

        $itemsRaw = json_decode((string) ($_POST['items'] ?? '[]'), true);
        if (!is_array($itemsRaw) || count($itemsRaw) === 0) {
            bulkImportJsonError(400, 'Nenhum item para processar.');
        }
        if (count($itemsRaw) > BULK_IMPORT_MAX_BATCH) {
            bulkImportJsonError(400, 'Lote grande demais (máximo de ' . BULK_IMPORT_MAX_BATCH . ' itens por requisição).');
        }

        $collectionCache = []; // original_path => linha da coleção (evita SELECT repetido no mesmo lote)
        $results = [];

        foreach ($itemsRaw as $item) {
            $type = (string) ($item['type'] ?? '');
            $collectionName = (string) ($item['collection'] ?? '');
            $file = (string) ($item['file'] ?? '');
            $entry = ['type' => $type, 'collection' => $collectionName, 'file' => $file];

            try {
                $srcPath = bulkImportResolvePath($type, $collectionName, $file);
                $typeStr = BULK_IMPORT_TYPES[$type];
                $originalPath = BULK_IMPORT_BASE_DIR_REL . '/' . $type . '/' . $collectionName;

                if (!isset($collectionCache[$originalPath])) {
                    $existing = assetCollectionFindByOriginalPath($originalPath);
                    if ($existing) {
                        $collectionCache[$originalPath] = $existing;
                    } else {
                        $colId = assetCollectionCreate([
                            'type' => $typeStr,
                            'tier' => 'free',
                            'sort_order' => 0,
                            'comment' => $collectionName,
                            'original_path' => $originalPath,
                            'active' => 1,
                        ]);
                        auditLog($adminId, 'create', 'asset_collections', (string) $colId);
                        $collectionCache[$originalPath] = assetCollectionFind($colId);
                    }
                }
                $col = $collectionCache[$originalPath];

                $imgUuid = uuidv4();
                $destPath = CRAFTOOLS_API_ROOT . '/public/v1/assets/' . $col['uuid'] . '/' . $imgUuid . '.webp';
                $destDir = dirname($destPath);
                if (!is_dir($destDir) && !mkdir($destDir, 0775, true) && !is_dir($destDir)) {
                    throw new RuntimeException('Não foi possível criar a pasta de destino.');
                }

                [$width, $height] = processAndConvertToWebp($srcPath, $destPath, $maxWidth, $quality);

                $newImgId = assetImageCreate([
                    'collection_id' => (int) $col['id'],
                    'original_name' => $file,
                    'file_path' => 'v1/assets/' . $col['uuid'] . '/' . $imgUuid . '.webp',
                    'width' => $width,
                    'height' => $height,
                    'size_bytes' => (int) filesize($destPath),
                    'comment' => '',
                    'tier' => 'free',
                ]);
                // Usa o mesmo uuid gerado para nome de arquivo e registro (mesmo padrão de actions.php).
                db()->prepare('UPDATE asset_images SET uuid = ? WHERE id = ?')->execute([$imgUuid, $newImgId]);
                auditLog($adminId, 'create', 'asset_images', (string) $newImgId);

                $entry['status'] = 'ok';
                $entry['collectionId'] = (int) $col['id'];
                $entry['imageId'] = $newImgId;
            } catch (Throwable $e) {
                $entry['status'] = 'error';
                $entry['message'] = $e->getMessage();
            }
            $results[] = $entry;
        }

        echo json_encode(['status' => 'success', 'data' => ['results' => $results]], JSON_UNESCAPED_UNICODE);
        exit;
    }

    bulkImportJsonError(400, 'Operação inválida.');
} catch (Throwable $e) {
    error_log('bulk_import_ajax: ' . $e->getMessage());
    bulkImportJsonError(500, 'Erro interno ao processar a solicitação.');
}
