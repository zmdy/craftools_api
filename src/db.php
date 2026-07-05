<?php
/**
 * db.php — conexão PDO (SQLite) + auto-inicialização do schema.
 *
 * SQLite foi escolhido para o MVP por ser zero-config (sem servidor de banco
 * separado para configurar no host), suficiente para o volume de uma API de
 * catálogo + biblioteca de assets. Migrar para MySQL/Postgres no futuro exige
 * apenas troca da DSN aqui e pequenos ajustes de sintaxe no schema.sql.
 */

function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $dbPath = CRAFTOOLS_API_DB_PATH;
    $isNew = !is_file($dbPath);

    if (!is_dir(dirname($dbPath))) {
        mkdir(dirname($dbPath), 0775, true);
    }

    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON;');
    $pdo->exec('PRAGMA journal_mode = WAL;');
    $pdo->exec('PRAGMA busy_timeout = 5000;');

    if ($isNew) {
        $schema = file_get_contents(CRAFTOOLS_API_ROOT . '/database/schema.sql');
        $pdo->exec($schema);
        @chmod($dbPath, 0660);
    } else {
        // Bancos já existentes (criados antes de uma tabela nova ser
        // adicionada ao schema.sql) não re-executam o schema inteiro -- só
        // tabelas/índices novos são garantidos aqui, via CREATE ... IF NOT
        // EXISTS (idempotente e barato, seguro de rodar em toda requisição).
        ensureAdditiveSchema($pdo);
    }

    return $pdo;
}

/**
 * Garante que tabelas adicionadas ao schema DEPOIS do banco já existir
 * também sejam criadas, sem precisar de uma migration formal. Só use
 * CREATE TABLE/INDEX IF NOT EXISTS aqui -- nunca ALTER/DROP.
 */
function ensureAdditiveSchema(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS emoji_kitchen_combos (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid            TEXT NOT NULL UNIQUE,
            left_emoji      TEXT NOT NULL,
            right_emoji     TEXT NOT NULL,
            left_codepoint  TEXT NOT NULL,
            right_codepoint TEXT NOT NULL,
            image_url       TEXT NOT NULL,
            is_latest       INTEGER NOT NULL DEFAULT 1 CHECK (is_latest IN (0,1)),
            tier            TEXT NOT NULL DEFAULT 'free' CHECK (tier IN ('free','plus','premium')),
            active          INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0,1)),
            created_at      TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE UNIQUE INDEX IF NOT EXISTS idx_emoji_kitchen_pair ON emoji_kitchen_combos(left_codepoint, right_codepoint);
        CREATE INDEX IF NOT EXISTS idx_emoji_kitchen_left ON emoji_kitchen_combos(left_codepoint);
        CREATE INDEX IF NOT EXISTS idx_emoji_kitchen_right ON emoji_kitchen_combos(right_codepoint);

        CREATE TABLE IF NOT EXISTS phrase_collections (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            uuid            TEXT NOT NULL UNIQUE,
            name            TEXT NOT NULL UNIQUE,
            description     TEXT NOT NULL DEFAULT '',
            sort_order      INTEGER NOT NULL DEFAULT 0,
            active          INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0,1)),
            created_at      TEXT NOT NULL DEFAULT (datetime('now')),
            updated_at      TEXT NOT NULL DEFAULT (datetime('now'))
        );
        CREATE TABLE IF NOT EXISTS phrase_collection_links (
            phrase_id       INTEGER NOT NULL REFERENCES phrases(id) ON DELETE CASCADE,
            collection_id   INTEGER NOT NULL REFERENCES phrase_collections(id) ON DELETE CASCADE,
            PRIMARY KEY (phrase_id, collection_id)
        );
        CREATE INDEX IF NOT EXISTS idx_phrase_collection_links_collection ON phrase_collection_links(collection_id);
    ");

    // phrase_collections pode já existir (criada por uma versão anterior
    // desta função, antes da coluna description existir) -- ALTER TABLE ADD
    // COLUMN é uma operação leve e segura em SQLite (não reescreve a tabela),
    // então é usada aqui, mas só depois de checar via PRAGMA que a coluna
    // realmente falta (idempotente).
    $cols = $pdo->query('PRAGMA table_info(phrase_collections)')->fetchAll(PDO::FETCH_ASSOC);
    $hasDescription = false;
    foreach ($cols as $col) {
        if ($col['name'] === 'description') {
            $hasDescription = true;
            break;
        }
    }
    if (!$hasDescription) {
        $pdo->exec("ALTER TABLE phrase_collections ADD COLUMN description TEXT NOT NULL DEFAULT ''");
    }
}

/** Gera um UUID v4 (usado como identificador público de todas as entidades). */
function uuidv4(): string {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    $hex = bin2hex($data);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-'
        . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
}

/** Timestamp UTC no formato usado pelas colunas TEXT do schema. */
function nowSql(): string {
    return gmdate('Y-m-d H:i:s');
}

// ---------------------------------------------------------------------------
// Helpers genéricos de acesso a dados. $table/$column SEMPRE vêm de literais
// fixados no próprio código (nunca de input do usuário) — apenas os VALORES
// percorrem prepared statements.
// ---------------------------------------------------------------------------

function repoList(string $table, string $orderBy = 'id ASC', array $where = []): array {
    $sql = "SELECT * FROM {$table}";
    $params = [];
    if ($where) {
        $clauses = [];
        foreach ($where as $col => $val) {
            $clauses[] = "{$col} = ?";
            $params[] = $val;
        }
        $sql .= ' WHERE ' . implode(' AND ', $clauses);
    }
    $sql .= " ORDER BY {$orderBy}";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function repoFind(string $table, int $id): ?array {
    $stmt = db()->prepare("SELECT * FROM {$table} WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row !== false ? $row : null;
}

function repoFindByUuid(string $table, string $uuid): ?array {
    $stmt = db()->prepare("SELECT * FROM {$table} WHERE uuid = ? LIMIT 1");
    $stmt->execute([$uuid]);
    $row = $stmt->fetch();
    return $row !== false ? $row : null;
}

function repoInsert(string $table, array $data): int {
    $cols = array_keys($data);
    $placeholders = array_fill(0, count($cols), '?');
    $sql = "INSERT INTO {$table} (" . implode(',', $cols) . ') VALUES (' . implode(',', $placeholders) . ')';
    db()->prepare($sql)->execute(array_values($data));
    return (int) db()->lastInsertId();
}

function repoUpdate(string $table, int $id, array $data): void {
    $sets = [];
    foreach (array_keys($data) as $col) {
        $sets[] = "{$col} = ?";
    }
    $sql = "UPDATE {$table} SET " . implode(',', $sets) . ' WHERE id = ?';
    $values = array_values($data);
    $values[] = $id;
    db()->prepare($sql)->execute($values);
}

function repoDelete(string $table, int $id): void {
    db()->prepare("DELETE FROM {$table} WHERE id = ?")->execute([$id]);
}
