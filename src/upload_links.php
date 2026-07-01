<?php
/**
 * upload_links.php — links de upload de fotos para clientes.
 *
 * O admin cria um link já escolhendo o kit (grid_size) e a quantidade de
 * fotos; o cliente só recebe a URL e faz o upload em public/upload.php.
 * Diferente de api_tokens (credencial de API), este é um link compartilhável
 * — o valor em texto puro fica salvo em `token` para poder ser copiado a
 * qualquer momento pelo painel, não só na criação. token_hash/token_prefix
 * continuam existindo para a busca indexada e para identificar links criados
 * antes desta coluna existir.
 */

/** Cria a tabela se ainda não existir — necessário para bancos já instalados
 *  antes deste recurso existir (schema.sql só roda inteiro em banco novo). */
function uploadLinksEnsureSchema(): void {
    db()->exec("CREATE TABLE IF NOT EXISTS upload_links (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        uuid            TEXT NOT NULL UNIQUE,
        client_name     TEXT NOT NULL,
        grid_size_id    INTEGER NULL REFERENCES grid_sizes(id) ON DELETE SET NULL,
        photo_count     INTEGER NOT NULL DEFAULT 0,
        notes           TEXT NULL,
        token           TEXT NULL,
        token_hash      TEXT NOT NULL UNIQUE,
        token_prefix    TEXT NOT NULL,
        status          TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','submitted')),
        admin_id        INTEGER NULL REFERENCES admin_users(id) ON DELETE SET NULL,
        submission_json TEXT NULL,
        submitted_at    TEXT NULL,
        created_at      TEXT NOT NULL DEFAULT (datetime('now')),
        updated_at      TEXT NOT NULL DEFAULT (datetime('now'))
    )");
    db()->exec('CREATE INDEX IF NOT EXISTS idx_upload_links_hash ON upload_links(token_hash)');

    // `token` foi adicionado depois da criação inicial da tabela — bancos que já
    // tinham upload_links sem essa coluna precisam de um ALTER TABLE (SQLite não
    // tem "ADD COLUMN IF NOT EXISTS", então checa via PRAGMA antes).
    $cols = db()->query('PRAGMA table_info(upload_links)')->fetchAll();
    $hasTokenCol = false;
    foreach ($cols as $col) {
        if ($col['name'] === 'token') {
            $hasTokenCol = true;
            break;
        }
    }
    if (!$hasTokenCol) {
        db()->exec('ALTER TABLE upload_links ADD COLUMN token TEXT NULL');
    }
}

/** Gera um novo token (mesmo formato de generateApiTokenValue() em api_auth.php). */
function uploadLinkGenerateToken(): array {
    $raw = bin2hex(random_bytes(32)); // 64 caracteres hex
    return [
        'raw' => $raw,
        'hash' => hash('sha256', $raw),
        'prefix' => substr($raw, 0, 8),
    ];
}

function uploadLinkList(): array {
    $sql = 'SELECT l.*, g.name AS grid_size_name
            FROM upload_links l LEFT JOIN grid_sizes g ON g.id = l.grid_size_id
            ORDER BY l.created_at DESC';
    return db()->query($sql)->fetchAll();
}

function uploadLinkFind(int $id): ?array {
    return repoFind('upload_links', $id);
}

function uploadLinkFindByUuid(string $uuid): ?array {
    $sql = 'SELECT l.*, g.name AS grid_size_name
            FROM upload_links l LEFT JOIN grid_sizes g ON g.id = l.grid_size_id
            WHERE l.uuid = ? LIMIT 1';
    $stmt = db()->prepare($sql);
    $stmt->execute([$uuid]);
    $row = $stmt->fetch();
    return $row !== false ? $row : null;
}

/** Resolve um token bruto vindo da URL pública (public/upload.php?token=...). */
function uploadLinkResolveByToken(string $rawToken): ?array {
    if (!preg_match('/^[a-f0-9]{64}$/', $rawToken)) {
        return null;
    }
    $hash = hash('sha256', $rawToken);
    $sql = 'SELECT l.*, g.name AS grid_size_name
            FROM upload_links l LEFT JOIN grid_sizes g ON g.id = l.grid_size_id
            WHERE l.token_hash = ? LIMIT 1';
    $stmt = db()->prepare($sql);
    $stmt->execute([$hash]);
    $row = $stmt->fetch();
    return $row !== false ? $row : null;
}

/** @return array{id:int, raw_token:string} */
function uploadLinkCreate(string $clientName, ?int $gridSizeId, int $photoCount, string $notes, ?int $adminId): array {
    $gen = uploadLinkGenerateToken();
    $id = repoInsert('upload_links', [
        'uuid' => uuidv4(),
        'client_name' => trim($clientName),
        'grid_size_id' => $gridSizeId,
        'photo_count' => $photoCount,
        'notes' => trim($notes),
        'token' => $gen['raw'],
        'token_hash' => $gen['hash'],
        'token_prefix' => $gen['prefix'],
        'status' => 'pending',
        'admin_id' => $adminId,
        'created_at' => nowSql(),
        'updated_at' => nowSql(),
    ]);
    return ['id' => $id, 'raw_token' => $gen['raw']];
}

/**
 * Gera um novo token para um link já existente (ex.: link criado antes da
 * coluna `token` existir, ou o admin suspeita que o link vazou). O link em si
 * (uuid, cliente, kit, status, fotos já enviadas) continua o mesmo — só o
 * segredo da URL muda, invalidando qualquer cópia anterior.
 *
 * @return array{raw_token:string}
 */
function uploadLinkRegenerateToken(int $id): array {
    $gen = uploadLinkGenerateToken();
    repoUpdate('upload_links', $id, [
        'token' => $gen['raw'],
        'token_hash' => $gen['hash'],
        'token_prefix' => $gen['prefix'],
        'updated_at' => nowSql(),
    ]);
    return ['raw_token' => $gen['raw']];
}

/** Marca o link como enviado e trava (uploadLinkResolveByToken continua
 *  funcionando para exibir a tela de "já enviado", só não permite reenviar). */
function uploadLinkMarkSubmitted(int $id, string $submissionJson): void {
    repoUpdate('upload_links', $id, [
        'status' => 'submitted',
        'submission_json' => $submissionJson,
        'submitted_at' => nowSql(),
        'updated_at' => nowSql(),
    ]);
}

/** Reabre um link já enviado (o admin decide reabrir manualmente pelo painel). */
function uploadLinkReopen(int $id): void {
    repoUpdate('upload_links', $id, [
        'status' => 'pending',
        'updated_at' => nowSql(),
    ]);
}

function uploadLinkDelete(int $id): void {
    $link = uploadLinkFind($id);
    repoDelete('upload_links', $id);
    if ($link) {
        uploadLinksRemoveDir(CRAFTOOLS_API_STORAGE . '/uploads/' . $link['uuid']);
    }
}

/** Monta a URL completa e clicável para o painel mostrar/copiar. Usa "/upload"
 *  sem ".php" — public/.htaccess reescreve isso internamente para upload.php. */
function uploadLinkFullUrl(string $rawToken): string {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $scheme = $https ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
    // index.php roda em public/ — o mesmo diretório onde upload.php vai morar.
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/public/index.php'), '/');
    return "{$scheme}://{$host}{$base}/upload?token={$rawToken}";
}

/** Remove recursivamente uma pasta de uploads (equivalente a removeDirRecursive()
 *  de public/actions.php, mas com nome próprio para não colidir — ambos os
 *  arquivos podem ser carregados na mesma requisição admin). */
function uploadLinksRemoveDir(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        is_dir($path) ? uploadLinksRemoveDir($path) : @unlink($path);
    }
    @rmdir($dir);
}

/**
 * Salva um arquivo de foto enviado pelo cliente em storage/uploads/<uuid>/,
 * reaproveitando o mesmo pipeline de conversão/validação de images.php
 * (processAndConvertToWebp) — a única diferença de handleImageUpload() é a
 * pasta base permitida, por isso não dá para chamar aquela função direto
 * aqui: fotos de cliente ficam fora de public/, nunca expostas por URL direta.
 *
 * @return array{width:int,height:int,size_bytes:int,file_name:string}
 */
function uploadLinkSavePhoto(array $file, string $linkUuid, int $index): array {
    if (!isset($file['error']) || is_array($file['error'])) {
        throw new RuntimeException('Upload inválido.');
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Falha no upload da foto ' . ($index + 1) . ' (código ' . (int) $file['error'] . ').');
    }
    if (!isset($file['size'], $file['tmp_name']) || $file['size'] <= 0 || $file['size'] > IMG_MAX_UPLOAD_BYTES) {
        throw new RuntimeException('Foto ' . ($index + 1) . ' vazia ou maior que o limite permitido (15MB).');
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('Upload suspeito rejeitado.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if (!in_array($mime, IMG_ALLOWED_MIME, true)) {
        throw new RuntimeException('Foto ' . ($index + 1) . ': tipo não suportado. Use JPEG, PNG ou WebP.');
    }

    $destDir = CRAFTOOLS_API_STORAGE . '/uploads/' . $linkUuid;
    if (!is_dir($destDir) && !mkdir($destDir, 0775, true) && !is_dir($destDir)) {
        throw new RuntimeException('Não foi possível criar a pasta de destino.');
    }
    assertPathInsideBase($destDir, CRAFTOOLS_API_STORAGE . '/uploads');

    $fileName = 'foto_' . ($index + 1) . '.webp';
    $destPath = $destDir . '/' . $fileName;
    [$width, $height] = processAndConvertToWebp($file['tmp_name'], $destPath, IMG_MAX_WIDTH, IMG_WEBP_QUALITY);

    return [
        'width' => $width,
        'height' => $height,
        'size_bytes' => (int) filesize($destPath),
        'file_name' => $fileName,
    ];
}
