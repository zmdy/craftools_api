<?php
/**
 * repo.php — funções de acesso a dados por entidade. Toda escrita usa
 * prepared statements (via os helpers genéricos de db.php); nenhuma consulta
 * monta valores de input diretamente na string SQL.
 */

// ============================================================================
// app_users — clientes do CraftTools+ ("cadastrar os usuários")
// ============================================================================

function appUserList(): array {
    return repoList('app_users', 'created_at DESC');
}

function appUserFind(int $id): ?array {
    return repoFind('app_users', $id);
}

function appUserFindByEmail(string $email): ?array {
    $stmt = db()->prepare('SELECT * FROM app_users WHERE email = ? LIMIT 1');
    $stmt->execute([strtolower(trim($email))]);
    $row = $stmt->fetch();
    return $row !== false ? $row : null;
}

function appUserCreate(array $d): int {
    return repoInsert('app_users', [
        'uuid' => uuidv4(),
        'name' => trim($d['name']),
        'email' => strtolower(trim($d['email'])),
        'tier' => $d['tier'],
        'status' => $d['status'],
        'notes' => $d['notes'] ?? '',
        'created_at' => nowSql(),
        'updated_at' => nowSql(),
    ]);
}

function appUserUpdate(int $id, array $d): void {
    repoUpdate('app_users', $id, [
        'name' => trim($d['name']),
        'email' => strtolower(trim($d['email'])),
        'tier' => $d['tier'],
        'status' => $d['status'],
        'notes' => $d['notes'] ?? '',
        'updated_at' => nowSql(),
    ]);
}

function appUserDelete(int $id): void {
    repoDelete('app_users', $id);
}

// ============================================================================
// api_tokens
// ============================================================================

function apiTokenList(): array {
    $sql = 'SELECT t.*, u.name AS user_name, u.email AS user_email
            FROM api_tokens t LEFT JOIN app_users u ON u.id = t.user_id
            ORDER BY t.created_at DESC';
    return db()->query($sql)->fetchAll();
}

function apiTokenFind(int $id): ?array {
    return repoFind('api_tokens', $id);
}

/** Cria um token e retorna o valor em texto puro (única vez que ele existe em claro). */
function apiTokenCreate(?int $userId, string $label, string $tier, ?string $expiresAt): array {
    $gen = generateApiTokenValue();
    $id = repoInsert('api_tokens', [
        'uuid' => uuidv4(),
        'user_id' => $userId,
        'label' => $label !== '' ? $label : 'Sem nome',
        'token_hash' => $gen['hash'],
        'token_prefix' => $gen['prefix'],
        'tier' => $tier,
        'active' => 1,
        'expires_at' => $expiresAt,
        'created_at' => nowSql(),
    ]);
    return ['id' => $id, 'raw_token' => $gen['raw']];
}

function apiTokenToggle(int $id, bool $active): void {
    repoUpdate('api_tokens', $id, ['active' => $active ? 1 : 0]);
}

function apiTokenDelete(int $id): void {
    repoDelete('api_tokens', $id);
}

// ============================================================================
// grid_sizes — espelha o schema de craftools/craftools/utils/GridSizes.js
// ============================================================================

function gridSizeList(): array {
    return repoList('grid_sizes', 'sort_order ASC, id ASC');
}

function gridSizeFind(int $id): ?array {
    return repoFind('grid_sizes', $id);
}

function gridSizeListActiveForTier(string $tier): array {
    $stmt = db()->prepare('SELECT * FROM grid_sizes WHERE active = 1 ORDER BY sort_order ASC, id ASC');
    $stmt->execute();
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        if (tierAtLeast($tier, $row['tier'])) {
            $out[] = gridSizeToApiShape($row);
        }
    }
    return $out;
}

/** Converte a linha do banco para o formato consumido por GridSizes.js. */
function gridSizeToApiShape(array $row): array {
    $shape = [
        'id' => $row['uuid'],
        'name' => $row['name'],
        'tier' => $row['tier'],
        'sizes' => json_decode($row['sizes_json'] ?: '[]', true),
    ];
    if ($row['type'] !== null && $row['type'] !== '') {
        $shape['type'] = $row['type'];
    }
    foreach (['cell_width' => 'cellWidth', 'cell_height' => 'cellHeight', 'cell_padding' => 'cellPadding',
              'page_margin' => 'pageMargin', 'cell_gap' => 'cellGap', 'cell_lines' => 'cellLines',
              'cell_columns' => 'cellColumns', 'cell_spacing' => 'cellSpacing'] as $col => $jsKey) {
        if ($row[$col] !== null) {
            $shape[$jsKey] = $row[$col];
        }
    }
    if (!empty($row['cell_slots_json'])) {
        $shape['cellSlots'] = json_decode($row['cell_slots_json'], true);
    }
    return $shape;
}

function gridSizeCreate(array $d): int {
    return repoInsert('grid_sizes', gridSizeRowFromInput($d) + ['created_at' => nowSql(), 'updated_at' => nowSql()]);
}

function gridSizeUpdate(int $id, array $d): void {
    $row = gridSizeRowFromInput($d);
    unset($row['uuid']);
    repoUpdate('grid_sizes', $id, $row + ['updated_at' => nowSql()]);
}

function gridSizeDelete(int $id): void {
    repoDelete('grid_sizes', $id);
}

function gridSizeRowFromInput(array $d): array {
    $row = [
        'name' => trim($d['name']),
        'type' => $d['type'] !== '' ? $d['type'] : null,
        'cell_width' => $d['cell_width'] !== '' ? (float) $d['cell_width'] : null,
        'cell_height' => $d['cell_height'] !== '' ? (float) $d['cell_height'] : null,
        'cell_padding' => $d['cell_padding'] !== '' ? $d['cell_padding'] : null,
        'page_margin' => $d['page_margin'] !== '' ? $d['page_margin'] : null,
        'cell_gap' => $d['cell_gap'] !== '' ? (float) $d['cell_gap'] : 0,
        'cell_lines' => $d['cell_lines'] !== '' ? (int) $d['cell_lines'] : null,
        'cell_columns' => $d['cell_columns'] !== '' ? (int) $d['cell_columns'] : null,
        'cell_spacing' => $d['cell_spacing'] !== '' ? (float) $d['cell_spacing'] : null,
        'sizes_json' => jsonLinesToArrayJson($d['sizes_lines'] ?? ''),
        'cell_slots_json' => $d['cell_slots_json'] ?? null,
        'tier' => $d['tier'],
        'sort_order' => (int) ($d['sort_order'] ?? 0),
        'active' => !empty($d['active']) ? 1 : 0,
    ];
    $row = ['uuid' => $d['uuid'] ?? uuidv4()] + $row;
    return $row;
}

/** Converte um textarea (uma medida "L,A" por linha) em JSON array de strings. */
function jsonLinesToArrayJson(string $text): string {
    $lines = array_filter(array_map('trim', explode("\n", $text)), function ($l) {
        return $l !== '';
    });
    return json_encode(array_values($lines), JSON_UNESCAPED_UNICODE);
}

// ============================================================================
// album_templates — conceito novo (cliente ainda não consome via API)
// ============================================================================

function albumTemplateList(): array {
    return repoList('album_templates', 'sort_order ASC, id ASC');
}

function albumTemplateFind(int $id): ?array {
    return repoFind('album_templates', $id);
}

function albumTemplateListActiveForTier(string $tier): array {
    $stmt = db()->query('SELECT * FROM album_templates WHERE active = 1 ORDER BY sort_order ASC, id ASC');
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        if (tierAtLeast($tier, $row['tier'])) {
            $out[] = albumTemplateToApiShape($row);
        }
    }
    return $out;
}

function albumTemplateToApiShape(array $row): array {
    return [
        'id' => $row['uuid'],
        'name' => $row['name'],
        'description' => $row['description'],
        'cover_style' => $row['cover_style'],
        'page_count' => (int) $row['page_count'],
        'layout' => json_decode($row['layout_json'] ?: '[]', true),
        'thumbnail_url' => $row['thumbnail_url'],
        'tier' => $row['tier'],
    ];
}

function albumTemplateCreate(array $d): int {
    return repoInsert('album_templates', albumTemplateRowFromInput($d) + ['created_at' => nowSql(), 'updated_at' => nowSql()]);
}

function albumTemplateUpdate(int $id, array $d): void {
    $row = albumTemplateRowFromInput($d);
    unset($row['uuid']);
    repoUpdate('album_templates', $id, $row + ['updated_at' => nowSql()]);
}

function albumTemplateDelete(int $id): void {
    repoDelete('album_templates', $id);
}

function albumTemplateRowFromInput(array $d): array {
    return [
        'uuid' => $d['uuid'] ?? uuidv4(),
        'name' => trim($d['name']),
        'description' => $d['description'] ?? '',
        'cover_style' => $d['cover_style'] ?? null,
        'page_count' => (int) ($d['page_count'] ?? 1),
        'layout_json' => $d['layout_json'] !== '' ? $d['layout_json'] : '[]',
        'thumbnail_url' => $d['thumbnail_url'] ?? null,
        'tier' => $d['tier'],
        'sort_order' => (int) ($d['sort_order'] ?? 0),
        'active' => !empty($d['active']) ? 1 : 0,
    ];
}

// ============================================================================
// phrases — banco de frases (autor / frase / categoria / idioma)
// ============================================================================

function phraseList(?string $filterCategory = null, ?string $filterAuthor = null): array {
    $sql = 'SELECT * FROM phrases WHERE 1=1';
    $params = [];
    if ($filterCategory !== null && $filterCategory !== '') {
        // category é CSV: "amor,motivacional" — busca categoria exata dentro do vetor
        $sql .= " AND (',' || category || ',' LIKE ?)";
        $params[] = '%,' . $filterCategory . ',%';
    }
    if ($filterAuthor !== null && $filterAuthor !== '') {
        $sql .= ' AND author = ?';
        $params[] = $filterAuthor;
    }
    $sql .= ' ORDER BY created_at DESC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function phraseFind(int $id): ?array {
    return repoFind('phrases', $id);
}

function phraseListActiveForTier(string $tier, ?string $category = null, ?string $language = null, int $limit = 50): array {
    $sql = 'SELECT * FROM phrases WHERE active = 1';
    $params = [];
    if ($category !== null && $category !== '') {
        // suporta múltiplas categorias armazenadas como CSV
        $sql .= " AND (',' || category || ',' LIKE ?)";
        $params[] = '%,' . $category . ',%';
    }
    if ($language !== null && $language !== '') {
        $sql .= ' AND language = ?';
        $params[] = $language;
    }
    $sql .= ' ORDER BY id ASC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        if (!tierAtLeast($tier, $row['tier'])) {
            continue;
        }
        $cats = array_values(array_filter(array_map('trim', explode(',', $row['category'] ?? ''))));
        $out[] = [
            'id' => $row['uuid'],
            'phrase' => $row['phrase'],
            'author' => $row['author'],
            'category' => $cats,
            'language' => $row['language'],
            'tier' => $row['tier'],
        ];
        if (count($out) >= $limit) {
            break;
        }
    }
    return $out;
}

function phraseCreate(array $d): int {
    return repoInsert('phrases', phraseRowFromInput($d) + ['created_at' => nowSql(), 'updated_at' => nowSql()]);
}

function phraseUpdate(int $id, array $d): void {
    $row = phraseRowFromInput($d);
    unset($row['uuid']);
    repoUpdate('phrases', $id, $row + ['updated_at' => nowSql()]);
}

function phraseDelete(int $id): void {
    repoDelete('phrases', $id);
}

/**
 * Normaliza categorias vindas de qualquer fonte (string CSV ou array) em string CSV limpa.
 * Ex: "  amor , motivacional " → "amor,motivacional"
 */
function phraseNormalizeCategory($raw): string {
    if (is_array($raw)) {
        $parts = $raw;
    } else {
        $parts = explode(',', (string) $raw);
    }
    $parts = array_values(array_unique(array_filter(array_map('trim', $parts))));
    return implode(',', $parts);
}

function phraseRowFromInput(array $d): array {
    return [
        'uuid'     => $d['uuid'] ?? uuidv4(),
        'phrase'   => trim($d['phrase']),
        'author'   => trim($d['author'] ?? ''),
        'category' => phraseNormalizeCategory($d['category'] ?? ''),
        'language' => ($d['language'] ?? '') !== '' ? $d['language'] : 'pt-br',
        'tier'     => $d['tier'],
        'active'   => !empty($d['active']) ? 1 : 0,
    ];
}

/** Retorna lista de categorias individuais distintas (expande o CSV de cada linha). */
function phraseCategories(): array {
    $rows = db()->query("SELECT DISTINCT category FROM phrases WHERE category IS NOT NULL AND category <> '' ORDER BY category")->fetchAll();
    $all = [];
    foreach ($rows as $row) {
        foreach (explode(',', $row['category']) as $cat) {
            $cat = trim($cat);
            if ($cat !== '') {
                $all[$cat] = true;
            }
        }
    }
    $keys = array_keys($all);
    sort($keys);
    return $keys;
}

/** Retorna lista de autores distintos. */
function phraseAuthors(): array {
    $rows = db()->query("SELECT DISTINCT author FROM phrases WHERE author IS NOT NULL AND author <> '' ORDER BY author")->fetchAll();
    return array_column($rows, 'author');
}

// ============================================================================
// asset_collections / asset_images — overlays e backgrounds
// ============================================================================

function assetCollectionList(?string $type = null): array {
    $where = $type !== null ? ['type' => $type] : [];
    return repoList('asset_collections', 'sort_order ASC, id ASC', $where);
}

function assetCollectionFind(int $id): ?array {
    return repoFind('asset_collections', $id);
}

function assetCollectionFindByUuid(string $uuid): ?array {
    return repoFindByUuid('asset_collections', $uuid);
}

/**
 * Busca uma coleção pelo seu original_path (ex.: "assets/original/backgrounds/praia").
 * Usado pela importação em massa para reaproveitar a coleção já criada em uma
 * chamada anterior, já que o processamento acontece em lotes (várias
 * requisições AJAX sequenciais) em vez de uma única requisição síncrona.
 */
function assetCollectionFindByOriginalPath(string $originalPath): ?array {
    $stmt = db()->prepare('SELECT * FROM asset_collections WHERE original_path = ? LIMIT 1');
    $stmt->execute([$originalPath]);
    $row = $stmt->fetch();
    return $row !== false ? $row : null;
}

function assetCollectionCreate(array $d): int {
    return repoInsert('asset_collections', [
        'uuid' => uuidv4(),
        'type' => $d['type'],
        'original_path' => $d['original_path'] ?? '',
        'comment' => $d['comment'] ?? '',
        'tier' => $d['tier'],
        'sort_order' => (int) ($d['sort_order'] ?? 0),
        'active' => !empty($d['active']) ? 1 : 0,
        'created_at' => nowSql(),
        'updated_at' => nowSql(),
    ]);
}

function assetCollectionUpdate(int $id, array $d): void {
    repoUpdate('asset_collections', $id, [
        'type' => $d['type'],
        'original_path' => $d['original_path'] ?? '',
        'comment' => $d['comment'] ?? '',
        'tier' => $d['tier'],
        'sort_order' => (int) ($d['sort_order'] ?? 0),
        'active' => !empty($d['active']) ? 1 : 0,
        'updated_at' => nowSql(),
    ]);
}

function assetCollectionDelete(int $id): void {
    repoDelete('asset_collections', $id); // ON DELETE CASCADE remove as imagens da coleção
}

function assetImagesByCollection(int $collectionId): array {
    return repoList('asset_images', 'sort_order ASC, id ASC', ['collection_id' => $collectionId]);
}

function assetImageFind(int $id): ?array {
    return repoFind('asset_images', $id);
}

function assetImageCreate(array $d): int {
    return repoInsert('asset_images', [
        'uuid' => uuidv4(),
        'collection_id' => (int) $d['collection_id'],
        'original_name' => $d['original_name'] ?? null,
        'file_path' => $d['file_path'],
        'width' => $d['width'] ?? null,
        'height' => $d['height'] ?? null,
        'size_bytes' => $d['size_bytes'] ?? null,
        'comment' => $d['comment'] ?? '',
        'tier' => $d['tier'],
        'sort_order' => (int) ($d['sort_order'] ?? 0),
        'active' => 1,
        'created_at' => nowSql(),
    ]);
}

function assetImageUpdate(int $id, array $d): void {
    repoUpdate('asset_images', $id, [
        'comment' => $d['comment'] ?? '',
        'tier' => $d['tier'],
        'sort_order' => (int) ($d['sort_order'] ?? 0),
        'active' => !empty($d['active']) ? 1 : 0,
    ]);
}

function assetImageDelete(int $id): void {
    repoDelete('asset_images', $id);
}

/**
 * Monta a resposta no formato consumido por ApiPicker.js / craftools_api
 * legado: [{id, comment, original_path, tier, images:[{id, api_url, comment, tier}]}].
 *
 * Regra de visibilidade (generaliza filterByTier() do projeto legado para os
 * 3 tiers): uma coleção só aparece se o tier do requisitante for >= tier da
 * coleção; dentro de coleções visíveis, cada imagem é filtrada da mesma forma
 * pelo seu próprio tier.
 */
function assetCollectionsForApi(string $tier, ?string $typeFilter = null, ?string $onlyUuid = null): array {
    $sql = 'SELECT * FROM asset_collections WHERE active = 1';
    $params = [];
    if ($typeFilter !== null) {
        $sql .= ' AND type = ?';
        $params[] = $typeFilter;
    }
    if ($onlyUuid !== null) {
        $sql .= ' AND uuid = ?';
        $params[] = $onlyUuid;
    }
    $sql .= ' ORDER BY sort_order ASC, id ASC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $collections = $stmt->fetchAll();

    $out = [];
    foreach ($collections as $col) {
        if (!tierAtLeast($tier, $col['tier'])) {
            continue;
        }

        $imgStmt = db()->prepare('SELECT * FROM asset_images WHERE collection_id = ? AND active = 1 ORDER BY sort_order ASC, id ASC');
        $imgStmt->execute([$col['id']]);

        $images = [];
        foreach ($imgStmt->fetchAll() as $img) {
            if (!tierAtLeast($tier, $img['tier'])) {
                continue;
            }
            $images[] = [
                'id' => $img['uuid'],
                // ApiPicker.js monta a URL final como `${API_BASE}${api_url}` — precisa
                // da barra inicial. file_path é salvo sem barra (bulk_import, image_upload,
                // migrate_legacy.php), então ela é adicionada aqui, no único ponto de saída.
                'api_url' => '/' . ltrim((string) $img['file_path'], '/'),
                'comment' => (string) $img['comment'],
                'tier' => $img['tier'],
            ];
        }

        $out[] = [
            'id' => $col['uuid'],
            'comment' => (string) $col['comment'],
            'original_path' => (string) $col['original_path'],
            'tier' => $col['tier'],
            'images' => $images,
        ];
    }
    return $out;
}

// ============================================================================
// Dashboard — contagens rápidas
// ============================================================================

function dashboardCounts(): array {
    $pdo = db();
    $count = function (string $table) use ($pdo) {
        return (int) $pdo->query("SELECT COUNT(*) AS c FROM {$table}")->fetch()['c'];
    };
    return [
        'app_users' => $count('app_users'),
        'api_tokens' => $count('api_tokens'),
        'grid_sizes' => $count('grid_sizes'),
        'album_templates' => $count('album_templates'),
        'asset_collections' => $count('asset_collections'),
        'asset_images' => $count('asset_images'),
        'phrases' => $count('phrases'),
    ];
}
