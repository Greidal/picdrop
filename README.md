# PicDrop Photo Gallery

A web-based photo gallery and event management system built with PHP and MySQL/MariaDB. This project allows users to register, log in, upload and view images, manage events, and participate in leaderboards. It also includes an admin interface and email notifications using PHPMailer.

## Features

- **User Registration & Authentication**: Secure user registration, login, and verification.
- **Photo Gallery**: Upload, view, and download images. Gallery and slideshow views available.
- **Event Management**: Admins can create and manage events.
- **Leaderboard**: Track and display top users or event participants.
- **Admin Panel**: Manage users, events, and gallery content.
- **Email Notifications**: Uses PHPMailer for sending emails (e.g., verification, notifications).
- **Download as ZIP**: Download all images of an event (plus a CSV export) as a ZIP archive.
- **Configurable via Docker**: Includes Docker and Docker Compose setup for easy deployment.

## Project Structure

```
├── Dockerfile                 # Multi-stage build (Composer deps + PHP/Apache runtime)
├── docker-compose.yml         # Production-style stack (app + MariaDB, Traefik labels)
├── composer.json / .lock      # PHP dependencies (PHPMailer, QR code generator, PHPStan, PHPUnit)
├── tests/                     # PHPUnit tests
├── docker/
│   ├── apache.conf            # Security headers, blocks lib/ and script execution in uploads/
│   ├── php.ini                # Upload limits, session hardening, OPcache
│   └── entrypoint.sh          # Runs DB migrations, then starts Apache
├── db/migrations/             # Versioned schema migrations (NNNN_name.sql / .php)
└── src/                       # Web root
    ├── lib/                   # Internal includes – never served over HTTP
    │   ├── auth.php           # Sessions, login throttling, access checks
    │   ├── config.php         # Configuration from environment variables
    │   ├── db.php             # Database connection
    │   ├── helpers.php        # Escaping, CSRF, UUIDs, safe image uploads
    │   ├── images.php         # Thumbnail / display-size variants
    │   ├── mail.php           # E-mails via PHPMailer
    │   ├── metadata.php       # Lossless removal of GPS/location data from photos
    │   ├── migrate.php        # CLI migration runner
    │   └── migrations.php     # Migration logic + bootstrap admin
    ├── index.php              # Guest upload page (per event)
    ├── admin.php              # Dashboard
    ├── manage_event.php       # Event settings, drinks, invites
    ├── manage_guests.php      # Block devices / remove spam
    ├── gallery.php, slideshow.php, leaderboard.php
    ├── image.php              # Serves (and lazily creates) resized images
    └── healthz.php            # Container health check
```

## Getting Started

### Prerequisites
- [Docker](https://www.docker.com/get-started) with Docker Compose

### Setup & Run

1. Copy `example.env` to `.env` and fill in real values (DB passwords, SMTP, `APP_URL`, admin user).
2. Start the stack:
   ```sh
   docker compose up -d
   ```
   The provided `docker-compose.yml` expects an external `traefik` network. For a local test without
   Traefik, add `ports: ["8080:8080"]` to the `picdrop` service and open http://localhost:8080.
   The container listens on port **8080** (it runs without root).

### Configuration

| Variable | Description |
| --- | --- |
| `APP_URL` | Public base URL, e.g. `https://picdrop.example.com`. Used for e-mail links and the QR code. |
| `PAGE_TITLE` | Name shown in the browser title and e-mails. |
| `REGISTRATION_CODE` | Code required for open sign-ups. **Empty = only invited users can register.** |
| `ADMIN_USERNAME` / `ADMIN_PASSWORD` / `ADMIN_EMAIL` | Creates an admin account on first start if all three are set. |
| `DB_HOST` / `DB_USER` / `DB_PASS` / `DB_NAME` | Database connection. |
| `SMTP_*` | Mail server settings (see `example.env`). |
| `SKIP_MIGRATIONS=1` | Don't run migrations on container start. |

### Database migrations
- Migrations in `db/migrations` are applied automatically when the container starts
  (`php src/lib/migrate.php`), guarded by a DB lock so parallel starts are safe.
- New change? Add the next file, e.g. `db/migrations/0005_add_something.sql`. Migrations must be
  idempotent (`IF NOT EXISTS`, …) because MariaDB can't roll back DDL. For data migrations use a
  `.php` file that returns `function (mysqli $conn): void { … }`.

## Development

```sh
composer install      # dependencies incl. PHPStan
composer lint         # php -l on all files
composer analyse      # PHPStan
composer test         # PHPUnit
composer migrate      # apply migrations against DB_* from the environment
```

## CI/CD
- `.github/workflows/ci.yml` (pull requests): Composer validate/audit, PHP lint, PHPStan, PHPUnit,
  migration test against MariaDB, Hadolint, a Docker build and a smoke test of the hardened container.
- `.github/workflows/release.yml` (push to `main`): runs CI, then semantic-release (version + changelog)
  and publishes a multi-arch image (`linux/amd64`, `linux/arm64`) to `ghcr.io/<owner>/<repo>`.
  Every image carries an SBOM and SLSA build provenance, and the provenance is signed keylessly
  via GitHub/Sigstore. Verify an image before deploying:
  ```sh
  gh attestation verify oci://ghcr.io/greidal/picdrop:v0.4.0 --owner Greidal
  docker buildx imagetools inspect ghcr.io/greidal/picdrop:v0.4.0 --format '{{json .SBOM}}'
  ```
- Dependabot keeps Composer packages, Docker images and GitHub Actions up to date (weekly).
  MariaDB major/minor upgrades are excluded on purpose — upgrade between LTS versions deliberately.

## Security Notes
- Use strong, unique values for all passwords and the registration code; never commit `.env`.
- Uploaded files are validated by content, stored under random names and can't be executed.
- All state-changing forms are CSRF-protected; logins are throttled per account.
- Optional per event: GPS/location data is removed from uploaded photos (lossless; JPEG, PNG, WebP).

### Container hardening
- Apache/PHP run as `www-data` (UID 33) on port 8080; application code is owned by root and read-only.
- No setuid/setgid binaries; PHP has shell functions and remote file access disabled and is
  restricted to `/var/www` and `/tmp` (`open_basedir`).
- `docker-compose.yml` runs the app with a read-only root filesystem (tmpfs for `/tmp`), all Linux
  capabilities dropped, `no-new-privileges`, PID/memory limits and rotated logs.
- The database only sits on an internal network without internet access and keeps just the
  capabilities its entrypoint needs.

## License
Brought to you by [Klimarschanlage Vertrieb Ltd](https://klimarschanlage.de). Contact our [team via mail](mailto:vertrieb@klimarschanlage.de) for licensing information, help or to thank them for their incredible work.

## Credits
- [PHPMailer](https://github.com/PHPMailer/PHPMailer) for email functionality.