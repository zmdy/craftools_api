<div align="center">
  <img src="assets/logo-craftools.svg" alt="CraftTools Logo" width="150" />

  <p><strong>Management API & Admin Backend for the CraftTools Suite</strong></p>

  <!-- Badges -->
  <img src="https://img.shields.io/badge/PHP-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP" />
  <img src="https://img.shields.io/badge/SQLite-07405E?style=for-the-badge&logo=sqlite&logoColor=white" alt="SQLite" />
  <img src="https://img.shields.io/badge/GD-8892BF?style=for-the-badge" alt="GD" />
  <img src="https://img.shields.io/badge/License-GPL_v3-blue?style=for-the-badge" alt="License" />
</div>

---

**CraftTools API** is the management backend that powers the CraftTools suite. It
replaces the legacy single-password prototype in `api/` with a real
administrative panel and a versioned public API: user accounts and access
tiers, the grid-size and album-template catalogs, the overlay/background
image library, and the author/phrase/category/language quote bank — all
served through an HTTP API that the CraftTools PWA (or any other client)
can consume without modification.

## Key Features

### User & Access Management
*   **Tiered accounts**: customers are registered with a plan tier
    (`free` / `plus` / `premium`) that gates which grids, templates, and
    assets the public API returns.
*   **API tokens**: per-user tokens are issued from the panel and stored only
    as SHA-256 hashes — the plain value is shown once, at creation time, and
    never again.
*   **Admin accounts**: panel logins are real accounts with hashed passwords,
    account lockout after repeated failures, and a login audit trail —
    there is no shared/default password.

### Catalog Management
*   **Grid sizes**: register the dimensions, margins, spacing, and slot
    layout of every grid the album editor can offer, per tier.
*   **Album templates**: manage cover/page templates and their layout
    metadata.
*   **Overlay & background library**: upload images through the panel; every
    upload is validated by real MIME type and re-encoded to WebP, which
    strips EXIF/metadata and any hidden payload from the original file.

### Phrase Bank
*   Author/phrase/category/language records that feed the quote-of-the-day
    style features of the PWA, filterable by category and language and
    gated by tier like every other catalog resource.

### Admin Panel
*   A self-contained panel — same visual language as the CraftTools PWA
    (DM Sans/DM Serif typography, Material Symbols, light/dark theme) — for
    managing every resource above without touching the database directly.

## Technical Architecture

CraftTools API is plain, dependency-free PHP (no Composer, no build step),
organized around a small set of consistent layers.

### Front Controller (`public/index.php`)
A single entry point resolves `?page=...` against a whitelist, enforces the
admin-login gate, applies security headers, and routes POSTs through
`actions.php` before rendering the matching view — a strict
post/redirect/get flow with no exceptions.

### Repository Layer (`src/repo.php`)
All persistence goes through a small repository layer over SQLite
(`src/db.php`), giving every entity (users, tokens, grid sizes, templates,
assets, phrases, admins) the same CRUD shape and the same place to enforce
invariants.

### Security Layer (`src/security.php`, `src/auth.php`, `src/api_auth.php`)
CSRF protection, security headers (including a `script-src 'self'` CSP on
panel pages), session hardening, fixed-window rate limiting, and public-API
token resolution are each isolated in their own module rather than scattered
across views — see [Security](#security) below.

## Public API

### `GET /v1/?resource=grid-sizes|album-templates|phrases|assets|backgrounds|overlays|collection`
Tokens accepted, in order of precedence: `?token=...` → `X-API-Token` header
→ `Authorization: Bearer ...`. An absent token is treated as anonymous
`free`-tier access, since a tiered plan model needs a no-login free tier;
tokens that are present but invalid, inactive, or expired are rejected.
`phrases` additionally accepts `category`, `language`, and `limit` (capped
at 200). The PWA's `ApiPicker.js`/`ApiDataLoader.js` consume this endpoint
exclusively.

Response: `{"status":"success","access_level":"free|plus|premium","data":[...]}`

> **Note:** an earlier design also planned a second, legacy-compatible
> endpoint at `/api/?route=...`. It was never implemented — `public/` only
> ships `v1/` — and nothing in the current PWA calls it. See
> [`docs/DOCUMENTATION.md`](docs/DOCUMENTATION.md#5-public-api-reference)
> for details.

## Security

*   API tokens stored only as SHA-256 hashes — never in plain text.
*   Admin passwords hashed with Argon2id (bcrypt-12 fallback); no default
    password.
*   Account lockout after 5 failed logins (15 min) plus per-IP rate limiting
    on login.
*   CSRF protection on every panel form (`hash_equals`, session-bound token).
*   Hardened sessions: `httponly`, `samesite=Strict`, `secure` over HTTPS,
    2h idle / 8h absolute timeout, session ID regenerated on login.
*   Rate limiting on the public API, per IP, configurable via `.env`.
*   Uploads validated by real MIME type (`finfo`), capped by size and
    megapixels, and always re-encoded to WebP via GD.
*   Path-traversal guards on every asset read/write.
*   Internal sequential IDs are never exposed — every URL/response uses a
    `uuid`.
*   Administrative actions are recorded in an `audit_log` table.

See [`docs/DOCUMENTATION.md`](docs/DOCUMENTATION.md) for the full technical
reference, and the project's delivery report for the list of improvements
recommended before a production rollout.

## Installation

1.  Point your (sub)domain's document root at this project's `public/`
    folder. If your host doesn't allow changing the document root, the
    `.htaccess` at the project root already blocks direct access to `src/`,
    `database/`, and `bin/` even if the whole folder is exposed — but
    `public/` as the document root is still the correct setup.
2.  Make sure `storage/` is writable by PHP. It is populated automatically
    on first run, including the SQLite database created from
    `database/schema.sql`.
3.  Open `/install.php` in a browser and follow the 3-step wizard: it
    checks server requirements (PHP version, extensions, `storage/`
    permissions), writes `.env` for you, and creates your administrator
    account (there is no default password). The installer locks itself out
    automatically once that account exists — delete `install.php` from the
    server afterward as an extra precaution.

    Prefer the command line, or don't have web access yet? Skip
    `install.php` entirely:
    ```
    cp .env.example .env   # then edit the values by hand
    php bin/create_admin.php
    ```
4.  *(Optional)* Migrate data from the legacy system
    (`api/api/data.json` and `api/api/tokens.json`):
    ```
    php bin/migrate_legacy.php
    ```
    This preserves the original collection/image IDs and copies the
    physical files to `public/v1/assets/<id>/<id>.webp` — the same path
    `ApiPicker.js` already requests via `/v1/`, so no client change is
    needed — and converts the API token already in production to a hash,
    without ever exposing the plain value. Read the summary printed at the
    end: images whose physical file isn't found on disk will need to be
    re-uploaded manually through the panel.
5.  Sign in at `/index.php?page=login` with the e-mail/password created in
    step 3.

Before deploying to production, run `php -l` on every `.php` file and do a
full functional test locally — this project was developed without access to
a PHP interpreter in the environment it was generated in (see
`docs/DOCUMENTATION.md` for the full list of limitations).

## Project Structure

```
craftools_api/
├── public/                 ← document root (point your vhost here)
│   ├── install.php           3-step web installer (env + admin account)
│   ├── index.php             admin panel front controller
│   ├── actions.php           handles every panel POST
│   ├── views/                one view per panel section
│   ├── assets/                admin.css / admin.js (same look as the PWA)
│   └── v1/
│       ├── index.php          public API (grids, templates, phrases, asset collections)
│       └── assets/            generated .webp files, served statically
├── src/                     application logic (not web-accessible)
│   ├── bootstrap.php          env, session, requires
│   ├── db.php                 SQLite connection + generic CRUD helpers
│   ├── security.php           CSRF, headers, rate limiting, sanitization
│   ├── auth.php                panel login (admin_users)
│   ├── api_auth.php           public API token/tier resolution
│   ├── images.php             secure upload + WebP conversion
│   └── repo.php                per-entity CRUD functions
├── database/schema.sql      SQLite schema (applied automatically on first run)
├── bin/create_admin.php     creates/updates a panel account (CLI)
├── bin/migrate_legacy.php   imports data.json/tokens.json from the legacy project
└── docs/                    English technical documentation
```

## Development Guide

*   **Language**: plain PHP 7.2+ (no framework, no Composer, no build step).
*   **Storage**: SQLite via PDO, schema-versioned in `database/schema.sql`.
*   **UI Assets**: Material Symbols for icons, DM Sans/DM Serif typography —
    matching the CraftTools PWA.
*   **Images**: GD for validation and WebP re-encoding on every upload.

## License

This program is free software distributed under the terms of the
**GNU General Public License v3**. See the [`LICENSE`](LICENSE) file for
more details.

---
<div align="center">
  <i>CraftTools API - The backend behind the suite.</i>
</div>
