# CraftTools API — Technical Documentation

This document describes the architecture, data model, security controls, and
public API contract of **CraftTools API**, the management backend for the
CraftTools suite. It is intended for developers integrating with the API,
deploying the panel, or maintaining the codebase.

For a quick start, see the [README](../README.md). This document goes
deeper into how the system is built and why.

## 1. Overview

CraftTools API replaces the legacy prototype in `api/` — a single hardcoded
manager password guarding a flat JSON file (`data.json`) and a flat token
file (`tokens.json`) — with:

* a real administrative panel, with individual accounts, password hashing,
  CSRF protection, and an audit trail;
* a SQLite-backed data model with proper entities for customers, API
  tokens, grid sizes, album templates, asset collections/images, and the
  phrase bank;
* a public, token-authenticated HTTP API (`/v1/`) exposing the catalog
  resources (grid sizes, album templates, phrases, asset collections).
  An earlier design also planned a contract-compatible `/api/` endpoint to
  ease migration from the legacy prototype, but it was never built — only
  `/v1/` ships in `public/`. See §5 for details.

The project is plain PHP 7.2+: no framework, no Composer dependencies, no
build step. It is designed to run on ordinary shared hosting with
`pdo_sqlite` and `gd` enabled.

## 2. Architecture

### 2.1 Request lifecycle (admin panel)

`public/index.php` is the single front controller for the panel. It:

1. **Install gate**: if `adminCountActive() === 0` (no active administrator
   exists yet), every panel URL — including the bare document root — is
   redirected to `install.php`, regardless of `?page=`. This check
   disappears on its own as soon as the first administrator account is
   created; there is no separate "installed" flag to maintain.
2. Resolves `?page=...` against a hardcoded whitelist (`login`, `logout`,
   `dashboard`, `users`, `tokens`, `grid_sizes`, `album_templates`,
   `assets`, `phrases`), falling back to `dashboard` for anything else.
   Visiting the bare root (no `?page=`) therefore behaves exactly like
   `?page=dashboard` once installed — which, for a logged-out visitor,
   means a redirect to the login screen (next point).
3. Handles `logout` and `login` before the auth gate (login obviously can't
   require being logged in).
4. Calls `requireAdminLogin()` — redirects to the login page if there is no
   valid session — then `applySecurityHeaders()`.
5. On `POST`, includes `public/actions.php`, which calls `requireCsrf()`
   first and then dispatches to a `switch` over `$_POST['_action']` keyed by
   page and action (e.g. `users`/`save`, `tokens`/`delete`). Every action
   path ends in a redirect (`flashRedirect()`), so the panel follows a
   strict post/redirect/get pattern with no exceptions.
6. Renders `views/_header.php`, `views/{page}.php`, `views/_footer.php`.

Note that this install gate only covers the admin panel (`public/index.php`
and everything it renders). `/v1/` is untouched by it and keeps working
independently of whether an admin account exists — it only cares about
`api_tokens`, which is a separate concern from panel access.

### 2.2 Bootstrap (`src/bootstrap.php`)

Every entry point — the panel, `/v1/`, and the `bin/*` CLI scripts —
starts with `require_once __DIR__ . '/../src/bootstrap.php'`, which:

* Defines `CRAFTOOLS_API_ROOT`, `CRAFTOOLS_API_STORAGE`, and
  `CRAFTOOLS_API_DB_PATH`.
* Implements a minimal `.env` parser (`env($key, $default)`) with no
  external dependency.
* Configures PHP error display/logging (errors are always logged to
  `storage/logs/php-error.log`; on-screen display is gated by
  `APP_DEBUG`).
* Starts a hardened session when running under a web server (`PHP_SAPI !==
  'cli'`): `httponly`, `samesite=Strict`, `secure` when HTTPS is detected,
  strict mode, a custom session name, a 2-hour idle timeout, and an 8-hour
  absolute timeout enforced in application code.
* Requires, in order: `db.php`, `security.php`, `repo.php`, `auth.php`,
  `api_auth.php`, `images.php`.
* Calls `db()` once, which lazily creates the SQLite file and applies
  `database/schema.sql` on first use.

### 2.3 Data access (`src/db.php`, `src/repo.php`)

`db.php` opens a single PDO/SQLite connection (`PRAGMA foreign_keys=ON`,
`journal_mode=WAL`, `busy_timeout=5000`) and exposes five generic helpers —
`repoList`, `repoFind`, `repoFindByUuid`, `repoInsert`, `repoUpdate`,
`repoDelete` — that every entity builds on. Table and column names passed to
these helpers always come from fixed literals in the code, never from user
input; only bound values go through prepared statements.

`repo.php` layers entity-specific functions on top: validation/shaping of
input (`*RowFromInput`), tier-filtered listing for the public API
(`*ListActiveForTier`), and the shape conversions each API response needs
(e.g. `gridSizeToApiShape`, `assetCollectionsForApi`).

### 2.4 Security layer

Split across three modules so each concern has one place to live:

* **`src/security.php`** — `applySecurityHeaders()`, CSRF token
  generation/verification, a SQLite-backed fixed-window rate limiter
  (`rateLimitCheck()`), `clientIp()`, and shared input helpers like
  `intInput()`.
* **`src/auth.php`** — admin panel authentication: login attempt throttling,
  account lockout, audit logging, session bootstrap on login.
* **`src/api_auth.php`** — public API token resolution and tier comparison
  (`resolveApiToken()`, `tierAtLeast()`).

See [Section 4](#4-security) for the full list of controls.

### 2.5 Image handling (`src/images.php`)

All image uploads (overlay/background assets) go through a single pipeline:
real MIME-type detection via `finfo` (never trusting the client-supplied
`Content-Type` or file extension), size and megapixel ceilings, and
re-encoding to WebP via GD. Re-encoding is not just a format conversion —
it discards all EXIF/metadata and any payload hidden in the original file
that isn't actual pixel data. Destination paths are validated with
`assertPathInsideBase()` before any write, as a defense-in-depth guard
against path traversal even though current callers only ever pass
internally generated UUIDs.

## 3. Data Model

SQLite, defined in `database/schema.sql`, applied automatically on first
run. Every table uses an internal auto-increment `id` for joins/foreign
keys, and a separate `uuid` column for anything exposed externally (URLs,
API responses) — internal sequential IDs are never exposed, which avoids
trivial enumeration of records.

| Table | Purpose |
|---|---|
| `admin_users` | Panel operators (not CraftTools+ customers). Hashed password, role (`admin`/`editor`), lockout fields, last-login audit fields. |
| `app_users` | CraftTools+ customers. Plan tier (`free`/`plus`/`premium`), status (`active`/`suspended`), optional `google_sub` for a future OAuth login. Customers don't have their own password today — they're identified through the API tokens issued to them. |
| `api_tokens` | Public API credentials. Stores only `token_hash` (SHA-256) and an 8-character `token_prefix` for display; the raw value is returned exactly once, at creation. Each token has its own tier, optional expiry, and optional owning user. |
| `grid_sizes` | Catalog of grid/photostrip layouts for the album editor, mirroring the shape currently hardcoded in `craftools/craftools/utils/GridSizes.js`. Variable-shape fields (`sizes`, `cellSlots`) are stored as JSON text. |
| `album_templates` | Cover/page templates for the album engine (`layout_json` holds page/slot geometry in relative units). New catalog — not yet consumed by the PWA client. |
| `asset_collections` | Groupings of overlay/background images (the "folders" concept from the legacy panel), typed `background` or `overlay`. |
| `asset_images` | Individual images within a collection. `file_path` is always relative to the public assets folder, never an absolute server path. |
| `phrases` | The quote bank: `phrase`, `author`, `category`, `language`, plus the same `tier`/`active` gating as every other catalog table. |
| `audit_log` | Append-only log of administrative actions (`admin_id`, `action`, `entity`, `entity_id`, `ip`, `details`). |
| `rate_limits` | Fixed-window counters keyed by an arbitrary bucket string; backs `rateLimitCheck()` for both the login form and the public API. |
| `login_attempts` | Per-IP/email login attempt history, used for additional throttling beyond the per-account lockout in `admin_users`. |

Every catalog table (`grid_sizes`, `album_templates`, `asset_collections`,
`asset_images`, `phrases`) shares the same `tier` + `active` + `sort_order`
shape, so tier-gating and listing logic is consistent across resources
rather than reinvented per table.

## 4. Security

* **Token storage** — API tokens are stored only as SHA-256 hashes; the
  plain value exists only in the HTTP response at creation time.
* **Password hashing** — Argon2id where available, bcrypt (cost 12) as a
  fallback on PHP builds without Argon2id support. No default/shared
  password exists anywhere in the system.
* **Brute-force protection** — admin accounts lock for 15 minutes after 5
  failed attempts; logins are additionally rate-limited per IP (10 attempts
  / 5 minutes) independent of the account-level lockout.
* **CSRF** — every panel form includes a session-bound token verified with
  `hash_equals()`; failed verification returns HTTP 419 and a flash message
  rather than silently proceeding.
* **Session hardening** — `httponly`, `samesite=Strict`, `secure` when
  HTTPS is detected, strict mode, a non-default session name, ID
  regeneration on login (mitigates session fixation), 2-hour idle timeout,
  8-hour absolute timeout.
* **Public API rate limiting** — per-IP, fixed-window, configurable via
  `.env` (`API_RATE_LIMIT_PER_IP`, `API_RATE_LIMIT_WINDOW_SECONDS`);
  responses include `Retry-After` on `429`.
* **Upload validation** — real MIME-type detection (`finfo`), size and
  megapixel ceilings, mandatory re-encoding to WebP via GD (drops
  EXIF/metadata and any non-pixel payload from the original file).
* **Path traversal guards** — every asset read/write path is checked
  against its expected base directory before use.
* **No sequential-ID leakage** — every externally visible identifier is a
  `uuid`, never the internal auto-increment `id`.
* **Audit trail** — administrative actions (login, logout, and
  create/update/delete on every entity) are recorded with admin ID, IP, and
  a timestamp.
* **Response headers** — `X-Content-Type-Options: nosniff`,
  `X-Frame-Options: DENY`, `Referrer-Policy: no-referrer`, a restrictive
  `Permissions-Policy`, a `script-src 'self'` Content-Security-Policy on
  panel HTML responses (so all panel JavaScript lives in `admin.js` and is
  wired up with `addEventListener`/`data-*` attributes — never inline
  `on*=` handlers, which the CSP would silently block), and HSTS when served
  over HTTPS.
* **Filesystem defense in depth** — two `.htaccess` layers (project root and
  `public/`) deny direct access to `src/`, `database/`, `bin/`, and any
  `.php`/`.sql`/`.json`/`.env`/`.md` file outside `public/`, in case a host
  doesn't allow pointing the document root directly at `public/`.

### Intentional behavior change vs. the legacy system

The legacy backend rejected any request without a token (`401`). Here, a
request with **no token** is treated as anonymous `free`-tier access — a
deliberate product decision, since a tiered plan model needs a free tier
that doesn't require login. A token that **is present** but invalid,
inactive, or expired is still rejected, exactly as before.

## 5. Public API Reference

> **About the legacy `/api/?route=...` endpoint.** Earlier drafts of this
> document (and of the README) described a second public endpoint,
> contract-compatible with the legacy prototype's `api/api/index.php`,
> reachable at `/api/?route=all|backgrounds|overlays|collection`. **That
> endpoint was never implemented** — `public/` only ships `index.php`
> (admin panel) and `v1/index.php`. There is no `public/api/` directory in
> this codebase. The PWA's `ApiPicker.js` and `ApiDataLoader.js` have always
> called `/v1/?resource=...` exclusively, so this has no effect on the
> shipped frontend; it only matters if some external client still expects
> the old `/api/` contract — point it at `/v1/` instead (the token scheme is
> identical), or extend `bin/migrate_legacy.php`'s approach if a literal
> `/api/` shim is genuinely needed.

### 5.1 `GET /v1/` — catalog & asset API

Tokens are accepted, in order of precedence, as: `?token=...` query
parameter, `X-API-Token` header, or `Authorization: Bearer ...` header.
CORS origin is controlled by `API_ALLOWED_ORIGIN` in `.env`.

| `resource` | Extra parameters | Notes |
|---|---|---|
| `grid-sizes` | — | Returns all active grids visible at the caller's tier, shaped for `GridSizes.js` (camelCase keys: `cellWidth`, `cellHeight`, `cellPadding`, `pageMargin`, `cellGap`, `cellLines`, `cellColumns`, `cellSpacing`, `cellSlots`). |
| `album-templates` | — | Returns all active templates visible at the caller's tier. |
| `phrases` | `category`, `language`, `limit` (default 50, max 200) | Filters before tier-gating and limiting; `category`/`language` are optional. |
| `assets` / `backgrounds` / `overlays` / `collection` | `id` (required for `collection`) | Asset collections/images (overlays & backgrounds library). |

Response: `{"status":"success","access_level":"free|plus|premium","data":[...]}`.
For asset collections, each image's `api_url` is the file's real public
path, e.g. `/v1/assets/<collection-uuid>/<image-uuid>.webp`.

Error responses use `{"status":"error","message":"..."}` with the
appropriate HTTP status: `400` invalid/unknown resource or missing `id`,
`401` malformed token, `403` token rejected (not found / inactive /
expired) or tier too low, `404` collection not found, `405` non-GET
method, `429` rate limit exceeded.

## 6. Admin Panel

Reachable at `/index.php?page=...`. Visual language matches the CraftTools
PWA: DM Sans/DM Serif Display typography, Material Symbols icons, and a
light/dark theme toggle persisted client-side.

| Page | Manages |
|---|---|
| `dashboard` | Row counts across every entity (`dashboardCounts()`). |
| `users` | `app_users` — CraftTools+ customer accounts and their tier/status. |
| `tokens` | `api_tokens` — issuing, disabling, and deleting public API credentials. |
| `grid_sizes` | `grid_sizes` — the grid/photostrip catalog. |
| `album_templates` | `album_templates` — cover/page templates. |
| `assets` | `asset_collections` / `asset_images` — overlay/background library, including upload. |
| `phrases` | `phrases` — the author/phrase/category/language quote bank, with a category filter. |

Every list page follows the same create/edit/delete pattern: a form posts
to `actions.php` with a CSRF token and an `_action` field, the controller
validates and writes, then redirects back to the same page with a flash
message.

## 7. Installation & Deployment

See the [README](../README.md#installation) for the step-by-step guide.
Summary: point the document root at `public/`, make `storage/` writable,
then either open `public/install.php` in a browser (recommended) or do it
by hand — copy `.env.example` to `.env` and create an admin account with
`bin/create_admin.php`. Optionally run `bin/migrate_legacy.php` afterward.

### `public/install.php` — web installer

A single self-contained file, reachable at `/install.php`, that walks
through the same setup `bin/create_admin.php` and a hand-edited `.env`
would otherwise require, for hosts where running PHP from the CLI isn't
convenient (or isn't possible at all on some shared-hosting plans):

1. **Requirements check** — runs entirely without `bootstrap.php` (PHP
   version, `pdo_sqlite`/`gd`/`fileinfo`/`mbstring`/`json` extensions,
   `storage/` writability), so a missing extension or bad permission shows
   a clear diagnostic instead of a fatal error. `bootstrap.php` is only
   required once these checks pass — by that point it's safe for it to
   auto-create the SQLite database.
2. **Environment configuration** — a form for `APP_TIMEZONE`,
   `API_ALLOWED_ORIGIN`, `API_RATE_LIMIT_PER_IP`,
   `API_RATE_LIMIT_WINDOW_SECONDS`, and `APP_DEBUG`, pre-filled from any
   existing `.env` (or the same defaults as `.env.example`). Writes `.env`
   directly; CSRF-protected via `csrfField()`/`verifyCsrf()` like every
   other panel form.
3. **Administrator account** — name/e-mail/password form using the exact
   validation rules as `bin/create_admin.php` (valid e-mail, password ≥ 10
   characters, confirmation match), hashed with `passwordHashNew()` and
   inserted as the `admin` role.
4. **Done** — confirms success and links to the login page.

Self-locking: at the top of every request, the script checks
`adminCountActive() > 0`. If any administrator account already exists, it
skips straight to the "done" screen regardless of which step was
requested — the installer cannot create a second account or rewrite
`.env` once the system is live. This is the only safeguard; there is no
secret token gating the page before that point, so the file should be
removed from the server right after use, the same way a WordPress-style
installer would be.

Environment variables (`.env`, parsed by `env()` in `bootstrap.php`):

| Variable | Default | Purpose |
|---|---|---|
| `APP_DEBUG` | `0` | Show PHP errors on screen. Keep `0` in production; errors are always logged regardless. |
| `APP_TIMEZONE` | `America/Sao_Paulo` | Timezone for generated timestamps. |
| `API_ALLOWED_ORIGIN` | _(none — must be set)_ | CORS origin allowed on `/v1/`. Set to the PWA's real production origin; avoid `*` outside local development. |
| `API_RATE_LIMIT_PER_IP` | `120` | Requests allowed per window, per IP, on the public APIs. |
| `API_RATE_LIMIT_WINDOW_SECONDS` | `60` | Window size, in seconds, for the above. |

## 8. CLI Scripts (`bin/`)

### `bin/create_admin.php`
Creates or updates a panel administrator account. Interactive by default
(password is read with terminal echo disabled where supported); also
accepts `--name=`, `--email=`, `--password=`, `--role=admin|editor` for
non-interactive use (e.g. deploy scripts). Updates an existing account by
e-mail instead of creating a duplicate. Refuses to run outside the CLI
SAPI.

### `bin/migrate_legacy.php`
One-time import of the legacy system's `api/api/data.json` and
`api/api/tokens.json` into the new SQLite database.

* Collection and image IDs from the legacy JSON are preserved as the
  `uuid` column, and physical files are copied to `public/v1/assets/<id>/<id>.webp`
  — the same path scheme `ApiPicker.js` already requests via `/v1/`, so no
  client change is needed.
* The token already in production is migrated as a SHA-256 hash; the plain
  value is never written to the new database.
* Physical `.webp` files are copied only if found on disk, trying first the
  legacy converted path and then the original pre-conversion file. Any
  image whose file can't be located either way is still recorded in the
  database, but its public URL will 404 until the file is re-uploaded
  through the panel (Overlays & Fundos → collection → upload).
* Prints a summary table of new/existing/copied/converted/missing counts at
  the end; refuses to run outside the CLI SAPI.

## 9. Known Limitations & Recommendations

This MVP was developed without access to a PHP interpreter in the
environment it was built in — every file was written and manually
cross-checked for contract consistency, but **`php -l` and a full
functional test pass are required before any production deployment.**

Beyond that baseline check, recommended next steps:

* **Automated tests** — there is currently no test suite. At minimum,
  contract tests for `/v1/` (status codes, response shape, tier-gating) and
  unit tests for the security helpers (`rateLimitCheck`, `tierAtLeast`,
  CSRF) would catch regressions early.
* **Token rotation** — tokens have no built-in rotation workflow; consider
  adding a "reissue" action that retires the old hash and returns a new raw
  value in one step, so customers aren't left without access during
  rotation.
* **Customer-facing account management** — `app_users` currently has no
  self-service login (it's admin-managed only); `google_sub` exists in the
  schema for a future OAuth-based customer login, but that flow isn't
  implemented yet.
* **`album_templates` is not yet consumed by any client** — the resource
  exists and is served by `/v1/`, but the PWA doesn't call it yet; treat it
  as forward-looking until the client integration lands.
* **Legacy image migration is metadata-only in this environment** — the
  legacy asset folders present during development were empty, so
  `bin/migrate_legacy.php` could create the database records but not copy
  any physical files. Re-running the migration against the real legacy
  asset folders (or manually re-uploading through the panel) is required
  to make those collections actually servable.
* **Admin panel is single-tenant** — there is currently no UI for an
  `admin` to manage other admin accounts (creation/deactivation is
  CLI-only via `bin/create_admin.php`); consider adding a panel page for
  this if the team managing it grows beyond CLI-comfortable operators.
* **`install.php` has no secret-token gate** — it relies solely on the
  "no admin account yet" check to lock itself out, so on a slow or
  unattended deployment it's briefly reachable by anyone who finds the
  URL first. Run it immediately after upload and delete the file right
  after; it is not designed to be left on the server long-term.
