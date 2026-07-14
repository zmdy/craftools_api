<?php
/**
 * db.php — PDO (SQLite) connection + automatic schema initialisation.
 *
 * SQLite was chosen for the MVP because it is zero-config (no separate
 * database server to set up on the host) and sufficient for the volume of a
 * catalogue + asset-library API. Migrating to MySQL/Postgres in the future
 * only requires changing the DSN here and minor syntax adjustments in schema.sql.
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
        // Existing databases (created before a new table was added to
        // schema.sql) do not re-run the full schema — only new tables/indexes
        // are guaranteed here via CREATE ... IF NOT EXISTS (idempotent and
        // cheap, safe to run on every request).
        ensureAdditiveSchema($pdo);
    }

    return $pdo;
}

/**
 * Ensures tables added to the schema AFTER the database already exists are
 * also created, without requiring a formal migration. Only use
 * CREATE TABLE/INDEX IF NOT EXISTS here — never ALTER/DROP.
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

        -- api_access_logs removed: logs are written to storage/logs/api/YYYY-MM-DD.jsonl
        -- Existing instances: DROP TABLE IF EXISTS api_access_logs; (optional)
    ");

    // phrase_collections may already exist (created by an earlier version of
    // this function, before the description column was added) — ALTER TABLE ADD
    // COLUMN is a lightweight, safe operation in SQLite (does not rewrite the
    // table), so it is used here but only after checking via PRAGMA that the
    // column is actually missing (idempotent).
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

/** Generates a UUID v4 (used as the public identifier for all entities). */
function uuidv4(): string {
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    $hex = bin2hex($data);
    return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-'
        . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
}

/** UTC timestamp in the format used by TEXT columns in the schema. */
function nowSql(): string {
    return gmdate('Y-m-d H:i:s');
}

// ---------------------------------------------------------------------------
// Generic data-access helpers. $table/$column ALWAYS come from literals
// hard-coded in the source (never from user input) — only VALUES travel
// through prepared statements.
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
