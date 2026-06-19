-- ============================================================================
-- CraftTools API — schema.sql (SQLite)
-- MVP do backend que controla acesso (tiers), catálogo e biblioteca de assets
-- do CraftTools+. Todas as tabelas usam chaves substitutas (id INTEGER) e
-- identificadores públicos (uuid TEXT) para nunca expor o id sequencial
-- interno em URLs/respostas de API (evita enumeração).
-- ============================================================================

PRAGMA foreign_keys = ON;

-- ── Equipe que administra o painel (NÃO são os clientes do CraftTools+) ─────
CREATE TABLE IF NOT EXISTS admin_users (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    uuid            TEXT NOT NULL UNIQUE,
    name            TEXT NOT NULL,
    email           TEXT NOT NULL UNIQUE,
    password_hash   TEXT NOT NULL,
    role            TEXT NOT NULL DEFAULT 'admin' CHECK (role IN ('admin','editor')),
    active          INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0,1)),
    failed_attempts INTEGER NOT NULL DEFAULT 0,
    locked_until    TEXT NULL,
    last_login_at   TEXT NULL,
    last_login_ip   TEXT NULL,
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at      TEXT NOT NULL DEFAULT (datetime('now'))
);

-- ── Clientes do CraftTools+ ("cadastrar os usuários") ───────────────────────
-- Tier hierárquico: free < plus < premium. Hoje sem senha própria (login real
-- via Google OAuth é trabalho futuro — ver relatório); por enquanto o acesso
-- de cada cliente é exercido através dos tokens de API emitidos para ele.
CREATE TABLE IF NOT EXISTS app_users (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    uuid            TEXT NOT NULL UNIQUE,
    name            TEXT NOT NULL,
    email           TEXT NOT NULL UNIQUE,
    tier            TEXT NOT NULL DEFAULT 'free' CHECK (tier IN ('free','plus','premium')),
    status          TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active','suspended')),
    notes           TEXT NULL,
    google_sub      TEXT NULL UNIQUE,
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at      TEXT NOT NULL DEFAULT (datetime('now'))
);

-- ── Tokens de acesso à API pública ───────────────────────────────────────────
-- O valor em texto puro do token NUNCA é persistido — apenas o hash SHA-256.
-- token_prefix guarda só os 8 primeiros caracteres, para o admin identificar
-- o token nas listagens sem precisar do valor completo.
CREATE TABLE IF NOT EXISTS api_tokens (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    uuid            TEXT NOT NULL UNIQUE,
    user_id         INTEGER NULL REFERENCES app_users(id) ON DELETE SET NULL,
    label           TEXT NOT NULL DEFAULT 'Sem nome',
    token_hash      TEXT NOT NULL UNIQUE,
    token_prefix    TEXT NOT NULL,
    tier            TEXT NOT NULL DEFAULT 'free' CHECK (tier IN ('free','plus','premium')),
    active          INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0,1)),
    expires_at      TEXT NULL,
    last_used_at    TEXT NULL,
    created_at      TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_api_tokens_hash ON api_tokens(token_hash);
CREATE INDEX IF NOT EXISTS idx_api_tokens_user ON api_tokens(user_id);

-- ── Tamanhos de grid (motor de Grid/Photostrip) ─────────────────────────────
-- Espelha o schema hoje hardcoded em craftools/craftools/utils/GridSizes.js.
-- cell_padding/page_margin seguem o formato "topo direita baixo esquerda" (mm).
-- sizes e cell_slots são armazenados como JSON (TEXT) por serem estruturas
-- de tamanho variável específicas de cada template.
CREATE TABLE IF NOT EXISTS grid_sizes (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    uuid            TEXT NOT NULL UNIQUE,
    name            TEXT NOT NULL,
    type            TEXT NULL,
    cell_width      REAL NULL,
    cell_height     REAL NULL,
    cell_padding    TEXT NULL,
    page_margin     TEXT NULL,
    cell_gap        REAL NULL DEFAULT 0,
    cell_lines      INTEGER NULL,
    cell_columns    INTEGER NULL,
    cell_spacing    REAL NULL,
    sizes_json      TEXT NOT NULL DEFAULT '[]',
    cell_slots_json TEXT NULL,
    tier            TEXT NOT NULL DEFAULT 'free' CHECK (tier IN ('free','plus','premium')),
    sort_order      INTEGER NOT NULL DEFAULT 0,
    active          INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0,1)),
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at      TEXT NOT NULL DEFAULT (datetime('now'))
);

-- ── Templates de álbum (motor AlbumTool) ────────────────────────────────────
-- Catálogo novo (o cliente ainda não consome isso — ver relatório). layout_json
-- guarda a lista de páginas/slots em unidades relativas (%), pensado para o
-- AlbumTool renderizar sem precisar saber tamanho físico de antemão.
CREATE TABLE IF NOT EXISTS album_templates (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    uuid            TEXT NOT NULL UNIQUE,
    name            TEXT NOT NULL,
    description     TEXT NULL,
    cover_style     TEXT NULL,
    page_count      INTEGER NOT NULL DEFAULT 1,
    layout_json     TEXT NOT NULL DEFAULT '[]',
    thumbnail_url   TEXT NULL,
    tier            TEXT NOT NULL DEFAULT 'free' CHECK (tier IN ('free','plus','premium')),
    sort_order      INTEGER NOT NULL DEFAULT 0,
    active          INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0,1)),
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at      TEXT NOT NULL DEFAULT (datetime('now'))
);

-- ── Coleções de assets (overlay/bg) — equivalente às "pastas" do painel antigo
CREATE TABLE IF NOT EXISTS asset_collections (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    uuid            TEXT NOT NULL UNIQUE,
    type            TEXT NOT NULL CHECK (type IN ('background','overlay')),
    original_path   TEXT NULL,
    comment         TEXT NULL DEFAULT '',
    tier            TEXT NOT NULL DEFAULT 'free' CHECK (tier IN ('free','plus','premium')),
    sort_order      INTEGER NOT NULL DEFAULT 0,
    active          INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0,1)),
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at      TEXT NOT NULL DEFAULT (datetime('now'))
);

-- ── Imagens dentro de cada coleção ───────────────────────────────────────────
-- file_path é relativo à pasta pública de assets (ex.: "<uuid_colecao>/<uuid>.webp"),
-- nunca um caminho absoluto do servidor.
CREATE TABLE IF NOT EXISTS asset_images (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    uuid            TEXT NOT NULL UNIQUE,
    collection_id   INTEGER NOT NULL REFERENCES asset_collections(id) ON DELETE CASCADE,
    original_name   TEXT NULL,
    file_path       TEXT NOT NULL,
    width           INTEGER NULL,
    height          INTEGER NULL,
    size_bytes      INTEGER NULL,
    comment         TEXT NULL DEFAULT '',
    tier            TEXT NOT NULL DEFAULT 'free' CHECK (tier IN ('free','plus','premium')),
    sort_order      INTEGER NOT NULL DEFAULT 0,
    active          INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0,1)),
    created_at      TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_asset_images_collection ON asset_images(collection_id);

-- ── Banco de frases (motivacionais etc.) ─────────────────────────────────────
CREATE TABLE IF NOT EXISTS phrases (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    uuid            TEXT NOT NULL UNIQUE,
    phrase          TEXT NOT NULL,
    author          TEXT NULL,
    category        TEXT NULL,
    language        TEXT NOT NULL DEFAULT 'pt-br',
    tier            TEXT NOT NULL DEFAULT 'free' CHECK (tier IN ('free','plus','premium')),
    active          INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0,1)),
    created_at      TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at      TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_phrases_lang_cat ON phrases(language, category);

-- ── Log de auditoria das ações administrativas ───────────────────────────────
CREATE TABLE IF NOT EXISTS audit_log (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    admin_id        INTEGER NULL REFERENCES admin_users(id) ON DELETE SET NULL,
    action          TEXT NOT NULL,
    entity          TEXT NULL,
    entity_id       TEXT NULL,
    ip              TEXT NULL,
    details         TEXT NULL,
    created_at      TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_audit_log_created ON audit_log(created_at);

-- ── Rate limiting (substitui os arquivos .ratelimit/*.json do projeto antigo)
CREATE TABLE IF NOT EXISTS rate_limits (
    bucket_key      TEXT PRIMARY KEY,
    count           INTEGER NOT NULL DEFAULT 0,
    window_start    INTEGER NOT NULL
);

-- ── Tentativas de login (throttling adicional por IP, além do lockout em admin_users)
CREATE TABLE IF NOT EXISTS login_attempts (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    ip              TEXT NOT NULL,
    email           TEXT NULL,
    success         INTEGER NOT NULL DEFAULT 0,
    created_at      TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_login_attempts_ip_time ON login_attempts(ip, created_at);
