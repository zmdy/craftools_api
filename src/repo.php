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

function phraseList(?string $filterCategory = null, ?string $filterAuthor = null, ?string $filterCollection = null): array {
    // LEFT JOIN só para trazer o nome da coleção (se houver) junto de cada
    // frase -- exibido na listagem do painel admin.
    $sql = "SELECT phrases.*, pc.name AS collection_name
            FROM phrases
            LEFT JOIN phrase_collection_links l ON l.phrase_id = phrases.id
            LEFT JOIN phrase_collections pc ON pc.id = l.collection_id
            WHERE 1=1";
    $params = [];
    if ($filterCategory !== null && $filterCategory !== '') {
        // category é CSV: "amor,motivacional" — busca categoria exata dentro do vetor
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
        // suporta múltiplas categorias armazenadas como CSV
        $sql .= " AND (',' || category || ',' LIKE ?)";
        $params[] = '%,' . $category . ',%';
    }
    if ($language !== null && $language !== '') {
        $sql .= ' AND language = ?';
        $params[] = $language;
    }
    if ($collection !== null && $collection !== '') {
        // Coleção é o filtro de "1º nível" (tema/conjunto); category/author
        // continuam sendo o filtro de "2º nível", aplicado sobre este resultado.
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
 * Aplica alterações em massa a um conjunto de frases (tier, idioma, categoria
 * e/ou coleção) -- usado pela ação "Aplicar" da seleção múltipla no painel
 * admin. Cada campo em $changes é opcional; só os informados são alterados
 * (mesmo valor para todas as frases dos $ids informados).
 * @param int[] $ids
 * @param array{tier?:string, language?:string, category?:string, collection?:string} $changes
 * @return int quantidade de frases efetivamente atualizadas
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

    // Coleção é tratada à parte (tabela de vínculo, não coluna de phrases).
    // '' explícito = remove a coleção; ausência da chave = não mexe na coleção.
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
// phrase_collections / phrase_collection_links — agrupamento de frases por
// tema/conjunto (ex.: "Ano Novo 2026", "Poemas de Amor"), independente de
// category/author (que são atributos livres da própria frase).
// ============================================================================

/** Lista coleções (com contagem de frases vinculadas) -- usado pelo painel admin. */
function phraseCollectionList(): array {
    return db()->query(
        "SELECT pc.*, (SELECT COUNT(*) FROM phrase_collection_links l WHERE l.collection_id = pc.id) AS phrase_count
         FROM phrase_collections pc
         ORDER BY pc.sort_order ASC, pc.name ASC"
    )->fetchAll();
}

/** Só os nomes das coleções ativas -- usado para popular selects/datalists (admin e API pública). */
function phraseCollectionNames(): array {
    $rows = db()->query("SELECT name FROM phrase_collections WHERE active = 1 ORDER BY sort_order ASC, name ASC")->fetchAll();
    return array_column($rows, 'name');
}

function phraseCollectionFind(int $id): ?array {
    return repoFind('phrase_collections', $id);
}

/** Busca uma coleção pelo nome (case-insensitive). */
function phraseCollectionFindByName(string $name): ?array {
    $stmt = db()->prepare('SELECT * FROM phrase_collections WHERE name = ? COLLATE NOCASE LIMIT 1');
    $stmt->execute([trim($name)]);
    $row = $stmt->fetch();
    return $row !== false ? $row : null;
}

/**
 * Retorna o id da coleção com este nome, criando-a se ainda não existir.
 * Usado pelo import CSV e pelo cadastro manual de frases -- o usuário só
 * "escolhe ou digita" o nome, sem precisar de uma tela de CRUD separada.
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

/** Cria uma coleção com nome + descrição -- usado pela tela de gerenciamento de coleções. */
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
    repoDelete('phrase_collections', $id); // ON DELETE CASCADE remove só os vínculos, não as frases
}

/** Substitui o vínculo de coleção de uma frase (hoje usado como 0..1 coleção por frase). */
function phraseSetCollection(int $phraseId, ?int $collectionId): void {
    db()->prepare('DELETE FROM phrase_collection_links WHERE phrase_id = ?')->execute([$phraseId]);
    if ($collectionId !== null) {
        db()->prepare('INSERT OR IGNORE INTO phrase_collection_links (phrase_id, collection_id) VALUES (?, ?)')
            ->execute([$phraseId, $collectionId]);
    }
}

/** Retorna a coleção vinculada a uma frase (ou null se nenhuma). */
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
            // Item malformado isolado não deve derrubar o lote inteiro.
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

/** Emojis que têm ao menos 1 combo real cadastrado com o emoji dado (em qualquer ordem). */
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
 * Lista paginada de combos cadastrados -- usada pelo painel admin (tabela de
 * navegação) e pela API pública (?resource=emoji-kitchen&mode=list).
 * $filterEmoji, quando informado, restringe aos combos em que o emoji aparece
 * de qualquer lado (left_emoji OU right_emoji).
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

/** Total de combos que respeitam o mesmo filtro de emojiKitchenList() -- usado para paginação. */
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

// ============================================================================
// api_access_logs (JSON-Lines) — 1 arquivo por dia em storage/logs/api/.
// Cada linha é um objeto JSON com os campos da requisição. O banco de dados
// NÃO é mais usado para logs de acesso — a tabela api_access_logs para de
// crescer e pode ser removida manualmente quando conveniente:
//   sqlite3 craftools.db "DROP TABLE IF EXISTS api_access_logs;"
// ============================================================================

/**
 * Remove o valor do parâmetro "token" de uma query string antes de guardá-la
 * no log -- o token dá acesso à API, então nunca deve ser persistido em texto
 * puro em lugar nenhum (mesma cautela já aplicada a api_tokens/upload_links,
 * que só guardam o hash SHA-256).
 */
function redactApiQueryString(string $queryString): string {
    if ($queryString === '') {
        return '';
    }
    return preg_replace('/(^|&)token=[^&]*/i', '$1token=***', $queryString) ?? $queryString;
}

// ── Helpers internos de arquivo ───────────────────────────────────────────────

/** Diretório base dos arquivos de log diários. */
function apiLogDir(): string {
    return CRAFTOOLS_API_ROOT . '/storage/logs/api';
}

/** Caminho completo para o .jsonl de uma data (YYYY-MM-DD). */
function apiLogFilePath(string $date): string {
    return apiLogDir() . '/' . $date . '.jsonl';
}

/**
 * Conta linhas de um arquivo sem parsear JSON — rápido para totais globais.
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
 * Retorna os arquivos .jsonl existentes no intervalo de datas, do mais recente
 * para o mais antigo. Sem filtro de data, cobre os últimos 30 dias.
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
 * Lê um .jsonl e retorna as linhas que passam nos filtros resource/tier/status.
 * Resultado em ordem cronológica ascendente (como está no arquivo).
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

// ── Funções públicas (mesma assinatura de antes — api_logs.php não precisa mudar) ──

/**
 * Grava 1 acesso à API pública no arquivo JSON-Lines do dia (UTC).
 * Nunca deixa uma falha de log derrubar a resposta real da API.
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
 * Lista paginada de acessos — lê os arquivos JSON-Lines do intervalo de datas.
 * Sem filtro de data, cobre os últimos 30 dias.
 */
function apiAccessLogList(int $limit, int $offset, array $filters = []): array {
    $limit  = max(1, min(200, $limit));
    $offset = max(0, $offset);

    $files = apiLogFilesInRange($filters['date_from'] ?? null, $filters['date_to'] ?? null);

    // Agrega todas as linhas filtradas, mais recentes primeiro
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

/** Total de registros no intervalo/filtro — usado para paginação. */
function apiAccessLogCount(array $filters = []): int {
    $files = apiLogFilesInRange($filters['date_from'] ?? null, $filters['date_to'] ?? null);

    // Se há filtro que exige inspecionar conteúdo, parseia; senão conta linhas.
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

/** Recursos distintos encontrados nos arquivos recentes — popula o filtro no painel. */
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

/** Contagens rápidas para os cartões de resumo da aba "Logs de API". */
function apiAccessLogStats(): array {
    $dir   = apiLogDir();
    $today = gmdate('Y-m-d');

    // Total geral: conta linhas em todos os arquivos sem parsear JSON
    $total = 0;
    if (is_dir($dir)) {
        foreach (glob($dir . '/*.jsonl') ?: [] as $path) {
            $total += apiLogCountLines($path);
        }
    }

    // Hoje: lê só o arquivo do dia para contar erros
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

// ============================================================================
// calendar_entries — feriados / comemorações / santos / eventos históricos
// ============================================================================

const CALENDAR_ENTRY_CATEGORIES = ['holiday', 'commemoration', 'saint', 'event'];
const CALENDAR_ENTRY_SCOPES     = ['national', 'state', 'municipal'];

/** Mapa categoria interna (EN) -> chave usada na resposta agrupada da API pública (PT-BR, como pedido). */
const CALENDAR_ENTRY_API_GROUPS = [
    'holiday'       => 'feriados',
    'commemoration' => 'comemoracoes',
    'saint'         => 'santos',
    'event'         => 'eventos',
];

function calendarEntryList(?string $filterCategory = null, ?int $filterMonth = null, ?int $filterDay = null, ?string $filterSource = null): array {
    $sql = 'SELECT * FROM calendar_entries WHERE 1=1';
    $params = [];
    if ($filterCategory !== null && $filterCategory !== '') {
        $sql .= ' AND category = ?';
        $params[] = $filterCategory;
    }
    if ($filterMonth !== null) {
        $sql .= ' AND month = ?';
        $params[] = $filterMonth;
    }
    if ($filterDay !== null) {
        $sql .= ' AND day = ?';
        $params[] = $filterDay;
    }
    if ($filterSource !== null && $filterSource !== '') {
        $sql .= ' AND source = ?';
        $params[] = $filterSource;
    }
    $sql .= ' ORDER BY month ASC, day ASC, category ASC, sort_order ASC, id DESC';
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function calendarEntryFind(int $id): ?array {
    return repoFind('calendar_entries', $id);
}

function calendarEntryCreate(array $d): int {
    return repoInsert('calendar_entries', calendarEntryRowFromInput($d) + ['created_at' => nowSql(), 'updated_at' => nowSql()]);
}

function calendarEntryUpdate(int $id, array $d): void {
    $row = calendarEntryRowFromInput($d);
    unset($row['uuid']);
    repoUpdate('calendar_entries', $id, $row + ['updated_at' => nowSql()]);
}

function calendarEntryDelete(int $id): void {
    repoDelete('calendar_entries', $id);
}

/**
 * Normaliza o input do form/CSV/importador em uma linha pronta para
 * repoInsert/repoUpdate. Campos que não se aplicam à categoria informada
 * (ex.: year para holiday, holiday_scope para saint) são sempre gravados
 * como NULL -- evita lixo de categoria trocada ficar "fantasma" na linha
 * depois de uma edição que mude a categoria.
 */
function calendarEntryRowFromInput(array $d): array {
    $category = in_array($d['category'] ?? '', CALENDAR_ENTRY_CATEGORIES, true) ? $d['category'] : 'commemoration';
    $month = max(1, min(12, (int) ($d['month'] ?? 0)));
    $day   = max(1, min(31, (int) ($d['day'] ?? 0)));

    $year = null;
    if ($category === 'event' && isset($d['year']) && $d['year'] !== '') {
        $year = (int) $d['year'];
    }

    $link = null;
    if (in_array($category, ['saint'], true) && !empty($d['link'])) {
        $link = trim((string) $d['link']);
    }

    $scope = null;
    $uf = null;
    $city = null;
    if ($category === 'holiday') {
        $scope = in_array($d['holiday_scope'] ?? '', CALENDAR_ENTRY_SCOPES, true) ? $d['holiday_scope'] : 'national';
        if ($scope === 'state' || $scope === 'municipal') {
            $uf = strtoupper(trim((string) ($d['uf'] ?? ''))) ?: null;
        }
        if ($scope === 'municipal') {
            $city = trim((string) ($d['city'] ?? '')) ?: null;
        }
    }

    return [
        'uuid'          => $d['uuid'] ?? uuidv4(),
        'category'      => $category,
        'month'         => $month,
        'day'           => $day,
        'year'          => $year,
        'title'         => trim((string) ($d['title'] ?? '')),
        'description'   => trim((string) ($d['description'] ?? '')) ?: null,
        'link'          => $link,
        'holiday_scope' => $scope,
        'uf'            => $uf,
        'city'          => $city,
        'source'        => trim((string) ($d['source'] ?? '')) ?: null,
        'tier'          => in_array($d['tier'] ?? '', ['free', 'plus', 'premium'], true) ? $d['tier'] : 'free',
        'sort_order'    => (int) ($d['sort_order'] ?? 0),
        'active'        => !empty($d['active']) ? 1 : 0,
    ];
}

/** Formato exposto pela API pública -- nunca inclui id interno, só uuid. */
function calendarEntryToApiShape(array $row): array {
    $shape = [
        'id'    => $row['uuid'],
        'title' => $row['title'],
    ];
    if (!empty($row['description'])) {
        $shape['description'] = $row['description'];
    }
    if ($row['category'] === 'event' && $row['year'] !== null) {
        $shape['year'] = (int) $row['year'];
    }
    if ($row['category'] === 'saint' && !empty($row['link'])) {
        $shape['link'] = $row['link'];
    }
    if ($row['category'] === 'holiday') {
        $shape['scope'] = $row['holiday_scope'] ?? 'national';
        if (!empty($row['uf'])) {
            $shape['uf'] = $row['uf'];
        }
        if (!empty($row['city'])) {
            $shape['city'] = $row['city'];
        }
    }
    return $shape;
}

/**
 * Consulta principal da API pública: tudo cadastrado para um mês/dia,
 * filtrado por tier e agrupado nas 4 categorias pedidas (chaves em PT-BR,
 * já que é o vocabulário do domínio -- feriados/comemoracoes/santos/eventos).
 */
function calendarEntryForDate(string $tier, int $month, int $day): array {
    $stmt = db()->prepare(
        'SELECT * FROM calendar_entries WHERE active = 1 AND month = ? AND day = ?
         ORDER BY category ASC, sort_order ASC, id ASC'
    );
    $stmt->execute([$month, $day]);

    $out = ['feriados' => [], 'comemoracoes' => [], 'santos' => [], 'eventos' => []];
    foreach ($stmt->fetchAll() as $row) {
        if (!tierAtLeast($tier, $row['tier'])) {
            continue;
        }
        $group = CALENDAR_ENTRY_API_GROUPS[$row['category']] ?? null;
        if ($group === null) {
            continue;
        }
        $out[$group][] = calendarEntryToApiShape($row);
    }
    return $out;
}

/**
 * Substitui em bloco todos os registros de uma categoria+data vindos de uma
 * fonte específica (ex.: "apicdata.biduinfo.com.br") -- usada pelo importador
 * de dados de exemplo da API externa, que pode ser rodado mais de uma vez
 * para a mesma data sem acumular duplicatas. Cadastros manuais/CSV (source
 * diferente) nunca são tocados por esta função.
 * @param array<int,array{title:string,year?:int|null,link?:string|null}> $items
 * @return int quantidade de itens inseridos
 */
function calendarEntryReplaceFromSource(string $category, int $month, int $day, string $source, array $items): int {
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $del = $pdo->prepare('DELETE FROM calendar_entries WHERE category = ? AND month = ? AND day = ? AND source = ?');
        $del->execute([$category, $month, $day, $source]);

        $count = 0;
        foreach ($items as $item) {
            $title = trim((string) ($item['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            calendarEntryCreate([
                'category' => $category,
                'month'    => $month,
                'day'      => $day,
                'year'     => $item['year'] ?? null,
                'title'    => $title,
                'link'     => $item['link'] ?? null,
                'source'   => $source,
                'tier'     => 'free',
                'active'   => 1,
            ]);
            $count++;
        }

        $pdo->commit();
        return $count;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Distinct sources cadastradas -- usado pelo painel admin para filtro/feedback do importador externo. */
function calendarEntrySources(): array {
    $rows = db()->query("SELECT DISTINCT source FROM calendar_entries WHERE source IS NOT NULL AND source <> '' ORDER BY source")->fetchAll();
    return array_column($rows, 'source');
}
