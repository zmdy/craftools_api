<?php
/**
 * repo.php — per-entity data-access functions. All writes use prepared
 * statements (via the generic helpers in db.php); no query builds input
 * values directly into the SQL string.
 */

// ============================================================================
// app_users — CraftTools+ subscribers ("register users")
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

/** Creates a token and returns its plain-text value (the only time it exists in clear). */
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
// album_templates — new concept (client does not yet consume via API)
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

function phraseList(?string $filterCategory = null, ?string $filterAuthor = null, ?string $filterCollection = null): array {
    // LEFT JOIN only to bring the collection name (if any) alongside each
    // phrase — displayed in the admin panel listing.
    $sql = "SELECT phrases.*, pc.name AS collection_name
            FROM phrases
            LEFT JOIN phrase_collection_links l ON l.phrase_id = phrases.id
            LEFT JOIN phrase_collections pc ON pc.id = l.collection_id
            WHERE 1=1";
    $params = [];
    if ($filterCategory !== null && $filterCategory !== '') {
        // category is CSV: "amor,motivacional" — exact category search within the vector
        $sql .= " AND (',' || phrases.category || ',' LIKE ?)";
        $params[] = '%,' . $filterCategory . ',%';
    }
    if ($filterAuthor !== null && $filterAuthor !== '') {
        $sql .= ' AND phrases.author = ?';
        $params[] = $filterAuthor;
    }
    if ($filterCollection !== null && $filterCollection !== '') {
        $sql .= ' AND phrases.id IN (
            SELECT l2.phrase_id FROM phrase_collection_links l2
            INNER JOIN phrase_collections pc2 ON pc2.id = l2.collection_id
            WHERE pc2.name = ? COLLATE NOCASE
        )';
        $params[] = $filterCollection;
    }
    $sql .= ' GROUP BY phrases.id ORDER BY phrases.created_at DESC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function phraseFind(int $id): ?array {
    return repoFind('phrases', $id);
}

function phraseListActiveForTier(string $tier, ?string $category = null, ?string $language = null, int $limit = 50, ?string $collection = null): array {
    $sql = 'SELECT * FROM phrases WHERE active = 1';
    $params = [];
    if ($category !== null && $category !== '') {
        // supports multiple categories stored as CSV
        $sql .= " AND (',' || category || ',' LIKE ?)";
        $params[] = '%,' . $category . ',%';
    }
    if ($language !== null && $language !== '') {
        $sql .= ' AND language = ?';
        $params[] = $language;
    }
    if ($collection !== null && $collection !== '') {
        // Collection is the "1st-level" filter (theme/set); category/author
        // remain the "2nd-level" filter, applied on top of this result.
        $sql .= ' AND id IN (
            SELECT l.phrase_id FROM phrase_collection_links l
            INNER JOIN phrase_collections pc ON pc.id = l.collection_id
            WHERE pc.name = ? COLLATE NOCASE
        )';
        $params[] = $collection;
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
 * Applies bulk changes to a set of phrases (tier, language, category
 * and/or collection) — used by the "Apply" action in the multi-select panel.
 * Each field in $changes is optional; only provided fields are changed
 * (same value applied to all phrases in $ids).
 * @param int[] $ids
 * @param array{tier?:string, language?:string, category?:string, collection?:string} $changes
 * @return int number of phrases actually updated
 */
function phraseBulkUpdate(array $ids, array $changes): int {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn($v) => $v > 0)));
    if (!$ids) {
        return 0;
    }

    $set = [];
    $params = [];
    if (array_key_exists('tier', $changes) && $changes['tier'] !== '') {
        $set[] = 'tier = ?';
        $params[] = $changes['tier'];
    }
    if (array_key_exists('language', $changes) && $changes['language'] !== '') {
        $set[] = 'language = ?';
        $params[] = $changes['language'];
    }
    if (array_key_exists('category', $changes) && $changes['category'] !== '') {
        $set[] = 'category = ?';
        $params[] = phraseNormalizeCategory($changes['category']);
    }

    $count = 0;
    if ($set) {
        $set[] = 'updated_at = ?';
        $params[] = nowSql();
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = 'UPDATE phrases SET ' . implode(', ', $set) . " WHERE id IN ({$placeholders})";
        $stmt = db()->prepare($sql);
        $stmt->execute(array_merge($params, $ids));
        $count = $stmt->rowCount();
    }

    // Collection is handled separately (link table, not a column of phrases).
    // Explicit '' = remove the collection; absent key = leave the collection unchanged.
    if (array_key_exists('collection', $changes)) {
        $collectionId = $changes['collection'] !== '' ? phraseCollectionFindOrCreateByName($changes['collection']) : null;
        foreach ($ids as $id) {
            phraseSetCollection($id, $collectionId);
        }
        $count = max($count, count($ids));
    }

    return $count;
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
// phrase_collections / phrase_collection_links — grouping of phrases by
// theme/set (e.g. "New Year 2026", "Love Poems"), independent of
// category/author (which are free attributes of the phrase itself).
// ============================================================================

/** Lists collections (with linked phrase count) — used by the admin panel. */
function phraseCollectionList(): array {
    return db()->query(
        "SELECT pc.*, (SELECT COUNT(*) FROM phrase_collection_links l WHERE l.collection_id = pc.id) AS phrase_count
         FROM phrase_collections pc
         ORDER BY pc.sort_order ASC, pc.name ASC"
    )->fetchAll();
}

/** Only the names of active collections — used to populate selects/datalists (admin and public API). */
function phraseCollectionNames(): array {
    $rows = db()->query("SELECT name FROM phrase_collections WHERE active = 1 ORDER BY sort_order ASC, name ASC")->fetchAll();
    return array_column($rows, 'name');
}

function phraseCollectionFind(int $id): ?array {
    return repoFind('phrase_collections', $id);
}

/** Looks up a collection by name (case-insensitive). */
function phraseCollectionFindByName(string $name): ?array {
    $stmt = db()->prepare('SELECT * FROM phrase_collections WHERE name = ? COLLATE NOCASE LIMIT 1');
    $stmt->execute([trim($name)]);
    $row = $stmt->fetch();
    return $row !== false ? $row : null;
}

/**
 * Returns the id of the collection with this name, creating it if it does not exist.
 * Used by CSV import and manual phrase entry — the user only "picks or types"
 * the name, without needing a separate CRUD screen.
 */
function phraseCollectionFindOrCreateByName(string $name): ?int {
    $name = trim($name);
    if ($name === '') {
        return null;
    }
    $existing = phraseCollectionFindByName($name);
    if ($existing) {
        return (int) $existing['id'];
    }
    return repoInsert('phrase_collections', [
        'uuid'       => uuidv4(),
        'name'       => $name,
        'sort_order' => 0,
        'active'     => 1,
        'created_at' => nowSql(),
        'updated_at' => nowSql(),
    ]);
}

/** Creates a collection with a name + description — used by the collection management screen. */
function phraseCollectionCreate(string $name, string $description = ''): int {
    return repoInsert('phrase_collections', [
        'uuid'        => uuidv4(),
        'name'        => trim($name),
        'description' => trim($description),
        'sort_order'  => 0,
        'active'      => 1,
        'created_at'  => nowSql(),
        'updated_at'  => nowSql(),
    ]);
}

function phraseCollectionUpdate(int $id, string $name, string $description = '', bool $active = true): void {
    repoUpdate('phrase_collections', $id, [
        'name'        => trim($name),
        'description' => trim($description),
        'active'      => $active ? 1 : 0,
        'updated_at'  => nowSql(),
    ]);
}

function phraseCollectionDelete(int $id): void {
    repoDelete('phrase_collections', $id); // ON DELETE CASCADE removes only the links, not the phrases
}

/** Replaces the collection link for a phrase (currently used as 0..1 collection per phrase). */
function phraseSetCollection(int $phraseId, ?int $collectionId): void {
    db()->prepare('DELETE FROM phrase_collection_links WHERE phrase_id = ?')->execute([$phraseId]);
    if ($collectionId !== null) {
        db()->prepare('INSERT OR IGNORE INTO phrase_collection_links (phrase_id, collection_id) VALUES (?, ?)')
            ->execute([$phraseId, $collectionId]);
    }
}

/** Returns the collection linked to a phrase (or null if none). */
function phraseCollectionForPhrase(int $phraseId): ?array {
    $stmt = db()->prepare(
        'SELECT pc.* FROM phrase_collections pc
         INNER JOIN phrase_collection_links l ON l.collection_id = pc.id
         WHERE l.phrase_id = ? LIMIT 1'
    );
    $stmt->execute([$phraseId]);
    $row = $stmt->fetch();
    return $row !== false ? $row : null;
}

// ============================================================================
// emoji_kitchen_combos — combos do Emoji Kitchen (importados do metadata.json
// de https://github.com/xsalazar/emoji-kitchen via painel admin)
// ============================================================================

/**
 * Insere ou atualiza (upsert por par de codepoints) um lote de combos.
 * Cada item aceito segue o formato do metadata.json original: leftEmoji,
 * rightEmoji, leftEmojiCodepoint, rightEmojiCodepoint, gStaticUrl, isLatest.
 * @return int quantos itens do lote foram gravados com sucesso.
 */
function emojiKitchenUpsertBatch(array $items): int {
    $sql = 'INSERT INTO emoji_kitchen_combos
                (uuid, left_emoji, right_emoji, left_codepoint, right_codepoint, image_url, is_latest, tier, active, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?)
            ON CONFLICT(left_codepoint, right_codepoint) DO UPDATE SET
                left_emoji = excluded.left_emoji,
                right_emoji = excluded.right_emoji,
                image_url = excluded.image_url,
                is_latest = excluded.is_latest';
    $stmt = db()->prepare($sql);

    $count = 0;
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $leftEmoji  = trim((string) ($item['leftEmoji'] ?? ''));
        $rightEmoji = trim((string) ($item['rightEmoji'] ?? ''));
        $leftCp     = trim((string) ($item['leftEmojiCodepoint'] ?? ''));
        $rightCp    = trim((string) ($item['rightEmojiCodepoint'] ?? ''));
        $imageUrl   = trim((string) ($item['gStaticUrl'] ?? $item['imageUrl'] ?? ''));
        if ($leftEmoji === '' || $rightEmoji === '' || $leftCp === '' || $rightCp === '' || $imageUrl === '') {
            continue;
        }
        $isLatest = array_key_exists('isLatest', $item) ? (empty($item['isLatest']) ? 0 : 1) : 1;

        try {
            $stmt->execute([uuidv4(), $leftEmoji, $rightEmoji, $leftCp, $rightCp, $imageUrl, $isLatest, 'free', nowSql()]);
            $count++;
        } catch (Throwable $e) {
            // A single malformed item must not abort the entire batch.
            continue;
        }
    }
    return $count;
}

/** Busca o combo (em qualquer ordem esquerda/direita) para um par de emojis. */
function emojiKitchenFindCombo(string $left, string $right): ?array {
    $stmt = db()->prepare(
        'SELECT * FROM emoji_kitchen_combos
         WHERE active = 1 AND (
             (left_emoji = ? AND right_emoji = ?) OR (left_emoji = ? AND right_emoji = ?)
         )
         ORDER BY is_latest DESC LIMIT 1'
    );
    $stmt->execute([$left, $right, $right, $left]);
    $row = $stmt->fetch();
    return $row !== false ? $row : null;
}

/** Emojis that have at least 1 registered combo with the given emoji (in either order). */
function emojiKitchenPartners(string $emoji): array {
    $stmt = db()->prepare(
        "SELECT DISTINCT right_emoji AS partner FROM emoji_kitchen_combos WHERE active = 1 AND left_emoji = ?
         UNION
         SELECT DISTINCT left_emoji AS partner FROM emoji_kitchen_combos WHERE active = 1 AND right_emoji = ?
         ORDER BY partner ASC"
    );
    $stmt->execute([$emoji, $emoji]);
    return array_column($stmt->fetchAll(), 'partner');
}

/** Lista (limitada) de emojis com pelo menos 1 combo cadastrado -- usado como "conjunto suportado". */
function emojiKitchenSupportedList(int $limit = 500): array {
    $limit = max(1, min(2000, $limit));
    $stmt = db()->query(
        "SELECT e FROM (
            SELECT DISTINCT left_emoji AS e FROM emoji_kitchen_combos WHERE active = 1
            UNION
            SELECT DISTINCT right_emoji AS e FROM emoji_kitchen_combos WHERE active = 1
        ) ORDER BY e ASC LIMIT {$limit}"
    );
    return array_column($stmt->fetchAll(), 'e');
}

/** Total de combos cadastrados -- usado pelo painel admin (feedback do import). */
function emojiKitchenCount(): int {
    $row = db()->query('SELECT COUNT(*) AS c FROM emoji_kitchen_combos')->fetch();
    return (int) ($row['c'] ?? 0);
}

/**
 * Paginated list of registered combos — used by the admin panel (navigation
 * table) and the public API (?resource=emoji-kitchen&mode=list).
 * $filterEmoji, when provided, restricts to combos where the emoji appears
 * on either side (left_emoji OR right_emoji).
 */
function emojiKitchenList(int $limit = 50, int $offset = 0, ?string $filterEmoji = null): array {
    $limit = max(1, min(500, $limit));
    $offset = max(0, $offset);
    $sql = 'SELECT * FROM emoji_kitchen_combos WHERE active = 1';
    $params = [];
    if ($filterEmoji !== null && $filterEmoji !== '') {
        $sql .= ' AND (left_emoji = ? OR right_emoji = ?)';
        $params[] = $filterEmoji;
        $params[] = $filterEmoji;
    }
    $sql .= ' ORDER BY created_at DESC, id DESC LIMIT ? OFFSET ?';
    $stmt = db()->prepare($sql);
    foreach ($params as $i => $p) {
        $stmt->bindValue($i + 1, $p);
    }
    $stmt->bindValue(count($params) + 1, $limit, PDO::PARAM_INT);
    $stmt->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

/** Total combos matching the same filter as emojiKitchenList() — used for pagination. */
function emojiKitchenListCount(?string $filterEmoji = null): int {
    $sql = 'SELECT COUNT(*) AS c FROM emoji_kitchen_combos WHERE active = 1';
    $params = [];
    if ($filterEmoji !== null && $filterEmoji !== '') {
        $sql .= ' AND (left_emoji = ? OR right_emoji = ?)';
        $params[] = $filterEmoji;
        $params[] = $filterEmoji;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return (int) ($row['c'] ?? 0);
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
 * Looks up a collection by its original_path (e.g. "assets/original/backgrounds/beach").
 * Used by bulk import to reuse a collection already created in a previous call,
 * since processing happens in batches (multiple sequential AJAX requests)
 * rather than a single synchronous request.
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
    repoDelete('asset_collections', $id); // ON DELETE CASCADE removes the collection's images
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
 * Visibility rule (generalises filterByTier() from the legacy project to
 * 3 tiers): a collection only appears if the requester's tier is >= the
 * collection's tier; within visible collections, each image is filtered
 * the same way by its own tier.
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
                // ApiPicker.js builds the final URL as `${API_BASE}${api_url}` — needs
                // the leading slash. file_path is saved without a slash (bulk_import,
                // image_upload, migrate_legacy.php), so it is added here, the single exit point.
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
// Dashboard — quick counts
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

// ============================================================================
// api_access_logs (JSON-Lines) — 1 file per day in storage/logs/api/.
// Each line is a JSON object with the request fields. The database is
// NO LONGER used for access logs — the api_access_logs table stops
// growing and can be removed manually when convenient:
//   sqlite3 craftools.db "DROP TABLE IF EXISTS api_access_logs;"
// ============================================================================

/**
 * Removes the "token" parameter value from a query string before storing it
 * in the log — the token grants API access, so it must never be persisted in
 * plain text anywhere (same caution already applied to api_tokens/upload_links,
 * which store only the SHA-256 hash).
 */
function redactApiQueryString(string $queryString): string {
    if ($queryString === '') {
        return '';
    }
    return preg_replace('/(^|&)token=[^&]*/i', '$1token=***', $queryString) ?? $queryString;
}

// ── Helpers internos de arquivo ───────────────────────────────────────────────

/** Base directory for daily log files. */
function apiLogDir(): string {
    return CRAFTOOLS_API_ROOT . '/storage/logs/api';
}

/** Full path to the .jsonl file for a given date (YYYY-MM-DD). */
function apiLogFilePath(string $date): string {
    return apiLogDir() . '/' . $date . '.jsonl';
}

/**
 * Counts lines in a file without parsing JSON — fast for global totals.
 */
function apiLogCountLines(string $path): int {
    $count = 0;
    $fh = @fopen($path, 'r');
    if (!$fh) return 0;
    while (fgets($fh) !== false) {
        $count++;
    }
    fclose($fh);
    return $count;
}

/**
 * Returns existing .jsonl files in the date range, newest first.
 * Without a date filter, covers the last 30 days.
 */
function apiLogFilesInRange(?string $dateFrom, ?string $dateTo): array {
    $dir = apiLogDir();
    if (!is_dir($dir)) return [];

    $from = $dateFrom ?? gmdate('Y-m-d', strtotime('-29 days'));
    $to   = $dateTo   ?? gmdate('Y-m-d');

    $files = [];
    $d   = new DateTime($from);
    $end = new DateTime($to);
    while ($d <= $end) {
        $date = $d->format('Y-m-d');
        $path = apiLogFilePath($date);
        if (file_exists($path)) {
            $files[] = ['date' => $date, 'path' => $path];
        }
        $d->modify('+1 day');
    }
    return array_reverse($files); // mais recente primeiro
}

/**
 * Reads a .jsonl file and returns the lines that pass the resource/tier/status filters.
 * Result is in ascending chronological order (as stored in the file).
 */
function apiLogParseFile(string $path, array $filters): array {
    $fh = @fopen($path, 'r');
    if (!$fh) return [];

    $filterResource = $filters['resource'] ?? null;
    $filterTier     = $filters['tier']     ?? null;
    $filterStatus   = $filters['status']   ?? null; // 'success' | 'error' | null

    $rows = [];
    while (($line = fgets($fh)) !== false) {
        $line = trim($line);
        if ($line === '') continue;
        $r = json_decode($line, true);
        if (!is_array($r)) continue;

        if ($filterResource !== null && ($r['res'] ?? null) !== $filterResource) continue;
        if ($filterTier     !== null && ($r['tier'] ?? null) !== $filterTier)    continue;
        if ($filterStatus   !== null) {
            $st = (int) ($r['st'] ?? 200);
            if ($filterStatus === 'error'   && $st < 400)  continue;
            if ($filterStatus === 'success' && $st >= 400) continue;
        }

        $rows[] = $r;
    }
    fclose($fh);
    return $rows;
}

/**
 * Converte uma linha bruta de log no formato que api_logs.php espera,
 * enriquecendo com label/prefixo do token via $tokenMap.
 */
function apiLogRowToDisplay(array $r, array $tokenMap): array {
    $tokId = isset($r['tok']) ? (int) $r['tok'] : null;
    return [
        'created_at'    => $r['ts']   ?? null,
        'resource'      => $r['res']  ?? null,
        'mode'          => $r['mod']  ?? null,
        'query_string'  => $r['qs']   ?? null,
        'token_id'      => $tokId,
        'user_id'       => $r['uid']  ?? null,
        'tier'          => $r['tier'] ?? 'free',
        'status_code'   => $r['st']   ?? 200,
        'error_message' => $r['err']  ?? null,
        'ip'            => $r['ip']   ?? null,
        'user_agent'    => $r['ua']   ?? null,
        'duration_ms'   => $r['ms']   ?? null,
        'token_label'   => $tokId !== null ? ($tokenMap[$tokId]['label']        ?? null) : null,
        'token_prefix'  => $tokId !== null ? ($tokenMap[$tokId]['token_prefix'] ?? null) : null,
    ];
}

/** Carrega todos os tokens em mapa [id => row] para enriquecer linhas de log. */
function apiLogLoadTokenMap(): array {
    $rows = db()->query('SELECT id, label, token_prefix FROM api_tokens')->fetchAll();
    $map  = [];
    foreach ($rows as $row) {
        $map[(int) $row['id']] = $row;
    }
    return $map;
}

// ── Public functions (same signature as before — api_logs.php needs no changes) ──

/**
 * Records 1 public API access in the day's JSON-Lines file (UTC).
 * Never lets a log failure abort the actual API response.
 */
function apiAccessLogRecord(array $data): void {
    try {
        $dir = apiLogDir();
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $line = json_encode([
            'ts'   => $data['created_at']    ?? gmdate('Y-m-d H:i:s'),
            'res'  => $data['resource']      ?? null,
            'mod'  => $data['mode']          ?? null,
            'qs'   => isset($data['query_string'])
                        ? redactApiQueryString((string) $data['query_string'])
                        : null,
            'tok'  => $data['token_id']      ?? null,
            'uid'  => $data['user_id']       ?? null,
            'tier' => $data['tier']          ?? 'free',
            'st'   => $data['status_code']   ?? 200,
            'err'  => $data['error_message'] ?? null,
            'ip'   => $data['ip']            ?? clientIp(),
            'ua'   => $data['user_agent']    ?? ($_SERVER['HTTP_USER_AGENT'] ?? null),
            'ms'   => $data['duration_ms']   ?? null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        file_put_contents(
            apiLogFilePath(gmdate('Y-m-d')),
            $line . "\n",
            FILE_APPEND | LOCK_EX
        );
    } catch (Throwable $ex) {
        error_log('apiAccessLogRecord: ' . $ex->getMessage());
    }
}

/**
 * Paginated list of requests — reads JSON-Lines files in the date range.
 * Without a date filter, covers the last 30 days.
 */
function apiAccessLogList(int $limit, int $offset, array $filters = []): array {
    $limit  = max(1, min(200, $limit));
    $offset = max(0, $offset);

    $files = apiLogFilesInRange($filters['date_from'] ?? null, $filters['date_to'] ?? null);

    // Aggregate all filtered lines, most recent first
    $allRows = [];
    foreach ($files as $f) {
        $rows    = apiLogParseFile($f['path'], $filters);
        $allRows = array_merge($allRows, array_reverse($rows));
    }

    $page = array_slice($allRows, $offset, $limit);
    if (!$page) return [];

    $tokenMap = apiLogLoadTokenMap();
    return array_map(static fn($r) => apiLogRowToDisplay($r, $tokenMap), $page);
}

/** Total records in the date range/filter — used for pagination. */
function apiAccessLogCount(array $filters = []): int {
    $files = apiLogFilesInRange($filters['date_from'] ?? null, $filters['date_to'] ?? null);

    // If a filter requires inspecting content, parse; otherwise just count lines.
    $needsParsing = !empty($filters['resource'])
                 || !empty($filters['tier'])
                 || !empty($filters['status']);

    $total = 0;
    foreach ($files as $f) {
        $total += $needsParsing
            ? count(apiLogParseFile($f['path'], $filters))
            : apiLogCountLines($f['path']);
    }
    return $total;
}

/** Distinct resources found in recent files — populates the filter dropdown in the panel. */
function apiAccessLogDistinctResources(): array {
    $files     = apiLogFilesInRange(null, null);
    $resources = [];
    foreach ($files as $f) {
        $fh = @fopen($f['path'], 'r');
        if (!$fh) continue;
        while (($line = fgets($fh)) !== false) {
            $r = json_decode(trim($line), true);
            if (is_array($r) && !empty($r['res'])) {
                $resources[$r['res']] = true;
            }
        }
        fclose($fh);
    }
    $result = array_keys($resources);
    sort($result);
    return $result;
}

/** Quick counts for the summary cards on the "API Logs" tab. */
function apiAccessLogStats(): array {
    $dir   = apiLogDir();
    $today = gmdate('Y-m-d');

    // Grand total: count lines across all files without parsing JSON
    $total = 0;
    if (is_dir($dir)) {
        foreach (glob($dir . '/*.jsonl') ?: [] as $path) {
            $total += apiLogCountLines($path);
        }
    }

    // Today: read only today's file to count errors
    $todayCount  = 0;
    $errorsToday = 0;
    $todayFile   = apiLogFilePath($today);
    if (file_exists($todayFile)) {
        $fh = @fopen($todayFile, 'r');
        if ($fh) {
            while (($line = fgets($fh)) !== false) {
                $r = json_decode(trim($line), true);
                if (!is_array($r)) continue;
                $todayCount++;
                if ((int) ($r['st'] ?? 200) >= 400) {
                    $errorsToday++;
                }
            }
            fclose($fh);
        }
    }

    return ['total' => $total, 'today' => $todayCount, 'errors_today' => $errorsToday];
}
