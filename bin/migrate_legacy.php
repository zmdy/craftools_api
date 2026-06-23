<?php
/**
 * bin/migrate_legacy.php
 *
 * Importa os dados do sistema legado (api/api/data.json e api/api/tokens.json)
 * para o novo banco SQLite, preservando:
 *   - os IDs originais das coleções/imagens (gravados na coluna `uuid`), para
 *     que as URLs já em uso por ApiPicker.js (/v1/assets/<id>/<id>.webp)
 *     continuem funcionando sem qualquer alteração no cliente;
 *   - o token de API já em produção, migrado como HASH (nunca em texto puro).
 *
 * Uso (linha de comando, na raiz de craftools_api):
 *     php bin/migrate_legacy.php
 *     php bin/migrate_legacy.php --data=/caminho/data.json --tokens=/caminho/tokens.json
 *
 * IMPORTANTE: este script migra os REGISTROS (metadados). Os arquivos
 * binários (.webp) só são copiados se forem encontrados no disco — neste
 * ambiente de desenvolvimento, as pastas de assets do projeto legado
 * (api/api/assets, api/assets/backgrounds, api/assets/overlays) existem mas
 * estão vazias, então é esperado que a maioria das imagens apareça como
 * "arquivo físico não encontrado" no resumo final. Nesse caso, as imagens
 * precisam ser reenviadas manualmente pelo painel (Overlays & Fundos) — o
 * registro do banco já estará lá, faltando só o arquivo.
 */

require_once __DIR__ . '/../src/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script só pode ser executado via linha de comando.');
}

function migArg(string $name, string $default): string {
    foreach ($GLOBALS['argv'] as $arg) {
        if (strpos($arg, '--' . $name . '=') === 0) {
            return substr($arg, strlen('--' . $name . '='));
        }
    }
    return $default;
}

$legacyRoot = dirname(CRAFTOOLS_API_ROOT) . '/api';
$dataPath = migArg('data', $legacyRoot . '/api/data.json');
$tokensPath = migArg('tokens', $legacyRoot . '/api/tokens.json');

echo "== Migração do sistema legado para CraftTools API ==\n";
echo "Dados legados:  {$dataPath}\n";
echo "Tokens legados: {$tokensPath}\n\n";

$pdo = db();

$stats = [
    'collections_new' => 0, 'collections_existing' => 0,
    'images_new' => 0, 'images_existing' => 0,
    'images_file_copied' => 0, 'images_file_converted' => 0, 'images_file_missing' => 0,
    'tokens_new' => 0, 'tokens_existing' => 0,
];

// ----------------------------------------------------------------- coleções
if (!is_file($dataPath)) {
    echo "[aviso] Arquivo de dados legado não encontrado em {$dataPath} — pulando migração de coleções/imagens.\n\n";
} else {
    $raw = json_decode((string) file_get_contents($dataPath), true);
    if (!is_array($raw) || !isset($raw['collections'])) {
        echo "[erro] data.json legado está em formato inesperado.\n\n";
    } else {
        foreach ($raw['collections'] as $col) {
            $colId = (string) $col['id'];
            $type = (stripos((string) ($col['original_path'] ?? ''), 'overlay') !== false) ? 'overlay' : 'background';

            $existingCol = assetCollectionFindByUuid($colId);
            if ($existingCol) {
                $stats['collections_existing']++;
                $dbColId = (int) $existingCol['id'];
            } else {
                $pdo->prepare('INSERT INTO asset_collections (uuid, type, original_path, comment, tier, sort_order, active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 0, 1, ?, ?)')
                    ->execute([$colId, $type, (string) ($col['original_path'] ?? ''), (string) ($col['comment'] ?? ''), (string) ($col['tier'] ?? 'free'), nowSql(), nowSql()]);
                $dbColId = (int) $pdo->lastInsertId();
                $stats['collections_new']++;
            }

            foreach ((array) ($col['images'] ?? []) as $img) {
                $imgId = (string) $img['id'];
                $existingImg = repoFindByUuid('asset_images', $imgId);
                $destRelative = 'v1/assets/' . $colId . '/' . $imgId . '.webp';
                $destAbsolute = CRAFTOOLS_API_ROOT . '/public/' . $destRelative;

                $width = null;
                $height = null;
                $sizeBytes = null;
                $fileStatus = 'missing';

                if (!is_file($destAbsolute)) {
                    $destDir = dirname($destAbsolute);
                    if (!is_dir($destDir)) {
                        @mkdir($destDir, 0775, true);
                    }

                    // Candidato 1: webp já convertido pelo sistema legado.
                    $candidateConverted = $legacyRoot . '/api/assets/' . $colId . '/' . $imgId . '.webp';
                    // Candidato 2: arquivo original (pré-conversão), referenciado em original_path/original_name.
                    $candidateOriginal = $legacyRoot . '/' . trim((string) ($col['original_path'] ?? ''), '/') . '/' . ($img['original_name'] ?? '');

                    if (is_file($candidateConverted)) {
                        if (copy($candidateConverted, $destAbsolute)) {
                            $info = @getimagesize($destAbsolute);
                            $width = $info[0] ?? null;
                            $height = $info[1] ?? null;
                            $sizeBytes = filesize($destAbsolute);
                            $fileStatus = 'copied';
                        }
                    } elseif (is_file($candidateOriginal)) {
                        try {
                            [$width, $height] = processAndConvertToWebp($candidateOriginal, $destAbsolute);
                            $sizeBytes = filesize($destAbsolute);
                            $fileStatus = 'converted';
                        } catch (Throwable $ex) {
                            echo "  [aviso] falha ao converter {$candidateOriginal}: {$ex->getMessage()}\n";
                        }
                    }
                } else {
                    $info = @getimagesize($destAbsolute);
                    $width = $info[0] ?? null;
                    $height = $info[1] ?? null;
                    $sizeBytes = filesize($destAbsolute);
                    $fileStatus = 'already_present';
                }

                if ($fileStatus === 'copied') { $stats['images_file_copied']++; }
                elseif ($fileStatus === 'converted') { $stats['images_file_converted']++; }
                elseif ($fileStatus === 'missing') { $stats['images_file_missing']++; }

                if ($existingImg) {
                    $stats['images_existing']++;
                    continue;
                }

                $pdo->prepare('INSERT INTO asset_images (uuid, collection_id, original_name, file_path, width, height, size_bytes, comment, tier, sort_order, active, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 1, ?)')
                    ->execute([$imgId, $dbColId, (string) ($img['original_name'] ?? ''), $destRelative, $width, $height, $sizeBytes, (string) ($img['comment'] ?? ''), (string) ($img['tier'] ?? $col['tier'] ?? 'free'), nowSql()]);
                $stats['images_new']++;
            }
        }
    }
}

// ------------------------------------------------------------------ tokens
if (!is_file($tokensPath)) {
    echo "[aviso] Arquivo de tokens legado não encontrado em {$tokensPath} — pulando migração de tokens.\n\n";
} else {
    $rawTokens = json_decode((string) file_get_contents($tokensPath), true);
    if (!is_array($rawTokens) || !isset($rawTokens['tokens'])) {
        echo "[erro] tokens.json legado está em formato inesperado.\n\n";
    } else {
        foreach ($rawTokens['tokens'] as $t) {
            $rawToken = (string) ($t['token'] ?? '');
            if (!preg_match('/^[a-f0-9]{64}$/', $rawToken)) {
                echo "  [aviso] token com formato inesperado ignorado (label: " . ($t['label'] ?? '?') . ")\n";
                continue;
            }
            $hash = hash('sha256', $rawToken);
            $stmt = $pdo->prepare('SELECT id FROM api_tokens WHERE token_hash = ? LIMIT 1');
            $stmt->execute([$hash]);
            if ($stmt->fetch()) {
                $stats['tokens_existing']++;
                continue;
            }

            $createdAt = !empty($t['created_at']) ? gmdate('Y-m-d H:i:s', strtotime((string) $t['created_at'])) : nowSql();
            $expiresAt = !empty($t['expires_at']) ? gmdate('Y-m-d H:i:s', strtotime((string) $t['expires_at'])) : null;

            $pdo->prepare('INSERT INTO api_tokens (uuid, user_id, label, token_hash, token_prefix, tier, active, expires_at, created_at) VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?)')
                ->execute([
                    uuidv4(),
                    (string) ($t['label'] ?? 'Sem nome'),
                    $hash,
                    substr($rawToken, 0, 8),
                    (string) ($t['tier'] ?? 'free'),
                    !empty($t['active']) ? 1 : 0,
                    $expiresAt,
                    $createdAt,
                ]);
            $stats['tokens_new']++;
        }
    }
}

echo "\n== Resumo da migração ==\n";
foreach ($stats as $key => $val) {
    echo str_pad($key, 24) . ": {$val}\n";
}
if ($stats['images_file_missing'] > 0) {
    echo "\n[ATENÇÃO] {$stats['images_file_missing']} imagem(ns) foram registradas no banco mas o arquivo .webp\n";
    echo "não foi encontrado em nenhum dos caminhos legados verificados. As URLs públicas\n";
    echo "(/api/assets/<coleção>/<imagem>.webp) vão retornar 404 até que você reenvie o\n";
    echo "arquivo correspondente pelo painel (Overlays & Fundos → coleção → enviar imagem)\n";
    echo "ou copie manualmente o arquivo .webp para public/api/assets/<coleção>/<imagem>.webp.\n";
}
echo "\nMigração concluída.\n";
