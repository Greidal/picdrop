# AGENTS.md

Guidance for AI coding agents **and** human contributors working on PicDrop. Read this before
changing code. Workflow and setup for humans: [CONTRIBUTING.md](CONTRIBUTING.md).

## Project overview

PicDrop is a photo box web app for events: guests upload photos from their phones (no login),
organizers manage events, see a live slideshow, a drinks leaderboard and a gallery.

- **Stack:** plain PHP 8.5 (no framework, procedural pages), Apache + mod_php, MariaDB 12.3,
  Composer (PHPMailer, chillerlan/php-qrcode). No JS build step – JavaScript is inline in the pages.
- **UI language is German**; code, comments, commit messages and docs are English.
- Runs as a single hardened container (see "Runtime constraints"); deployed via `docker-compose.yml`
  behind Traefik.

## Repository layout

| Path | Contents |
| --- | --- |
| `src/*.php` | Web root: one file per page/endpoint (`index.php` = guest upload, `admin.php` = dashboard, …) |
| `src/lib/` | Internal includes, **never served over HTTP** (blocked in `docker/apache.conf`) |
| `src/lib/auth.php` | Session, login, access checks, flash messages – include this first in every page |
| `src/lib/helpers.php` | `e()`, CSRF, UUIDs, upload validation, `appBaseUrl()` |
| `src/lib/images.php` / `metadata.php` | Thumbnail/display variants, GPS removal |
| `src/lib/account.php` / `mail.php` | Verification + password reset tokens, e-mails |
| `src/lib/config.php` | All configuration, read from environment variables |
| `db/migrations/` | Versioned schema migrations `NNNN_name.sql` / `.php` |
| `docker/` | `php.ini`, `apache.conf`, entrypoint (runs migrations) |
| `tests/` | PHPUnit tests; `tests/e2e/` = Playwright end-to-end tests (own `package.json`) |
| `UPGRADING.md` | Steps operators must take per release |

## Commands

```sh
composer install          # PHP dependencies incl. dev tools
composer lint             # php -l
composer analyse          # PHPStan (level 5) – must stay at 0 errors
composer test             # PHPUnit
docker build -t picdrop:e2e .
cd tests/e2e && npm ci && npx playwright install chromium webkit
npm run stack:up          # production compose + Mailpit → http://localhost:18080 (mail UI :18025)
npm test                  # Playwright; npm run stack:down afterwards
```

All of these run in CI (`.github/workflows/ci.yml`); a PR can only be merged when they pass.

## Security rules (non-negotiable)

This app is exposed to anonymous guests. Every change must keep these invariants:

1. **Escape all output** with `e()` (HTML text and attributes). In JavaScript contexts use
   `json_encode()`; pass values to inline handlers via `data-*` attributes, never by string
   concatenation into `onclick="…"`.
2. **SQL only via prepared statements** (`$conn->prepare()` + `bind_param`). Never interpolate
   variables into SQL strings.
3. **Every state-changing request is POST and CSRF-protected:** `requireCsrf()` in the handler,
   `<?php echo csrfField(); ?>` in every form, `csrf_token` field or `X-CSRF-Token` header for `fetch`.
   (Exception by design: guest uploads and emoji reactions on the public event page.)
4. **Access control:** pages for organizers call `requireLogin()` and `checkEventAccess($conn, $uuid)`
   before touching event data. Validate event IDs with `isValidUuid()` / `getEventOrDie()`.
5. **Uploads** only through `storeUploadedImage()` (content-based type check, random file name).
   Never use client-supplied file names or extensions for paths; use `basename()` for any file name
   coming from the request or database.
6. **Tokens and secrets:** use `random_bytes()`; store reset tokens hashed; compare with
   `hash_equals()`. Account mail flows must not reveal whether an address exists and must respect
   `isAccountMailThrottled()`.
7. **Links in e-mails** are built with `appBaseUrl()` (uses `APP_URL`, never the Host header).
8. No new external scripts/CDNs, no shell execution (`exec` & co. are disabled in `php.ini`),
   no `allow_url_fopen`.

## Conventions

- **New page:** start with `require_once __DIR__ . '/lib/auth.php';`, render the layout with
  `require __DIR__ . '/lib/header.php';`, show messages with `renderMessage()` / flash messages.
- **Database changes:** add the next numbered file in `db/migrations/`. Migrations must be
  idempotent (`IF NOT EXISTS`, …) because MariaDB can't roll back DDL, and must work on existing
  data. Data migrations are `.php` files returning `function (mysqli $conn): void`. Never edit a
  migration that is already released.
- **Configuration:** new settings are environment variables defined in `src/lib/config.php`, with a
  safe default, and documented in `README.md` (table), `example.env` and `docker-compose.yml`.
- **Style:** 4 spaces, `camelCase` functions, early returns, keep functions small. Follow the
  surrounding code; match its comment density (comments explain *why*).
- **Tests:** new `lib/` logic gets PHPUnit tests (`tests/`); new or changed user flows get a
  Playwright test (`tests/e2e/specs/`). Security fixes get a regression test.

## Runtime constraints (container)

- Runs as `www-data` (UID 33) on port **8080** with a **read-only root filesystem**. The only
  writable places are `src/uploads/` (volume) and `/tmp` (small RAM-backed tmpfs – don't put large
  files there; use `uploads/.tmp`).
- `open_basedir` is `/var/www:/tmp`; code outside that can't be read.
- Migrations run on container start (`src/lib/migrate.php`), not per request.

## Commits, PRs and releases

- **Conventional Commits** (`feat(scope): …`, `fix: …`, `docs: …`, `test: …`, `ci: …`,
  `chore(deps): …`). The PR title must follow the same format – it becomes the commit message on
  squash merges, and semantic-release derives the next version from it (`feat` → minor,
  `fix` → patch).
- Changes operators must act on (new required env var, compose/port changes, DB upgrades): add a
  `BREAKING CHANGE:` footer **and** a section in `UPGRADING.md`. Before 1.0 this bumps the minor version.
- Don't edit `CHANGELOG.md` for new versions – release notes are generated as GitHub Releases.
- Never commit `.env`, real credentials or production data. `tests/e2e/e2e.env` holds test-only values.
- `main` is protected: all changes go through pull requests with green CI.
- Dependabot PRs: a **major** `@types/node` update must be merged together with raising
  `node-version` in `.github/workflows/ci.yml` to the same major (push that change to the
  Dependabot branch); the types must match the Node.js version the tests run on.
