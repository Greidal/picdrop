# Changelog

Release notes for versions after 0.4.0 are published as
[GitHub Releases](https://github.com/Greidal/picdrop/releases) only.
Steps required when updating an installation are listed in [UPGRADING.md](UPGRADING.md).

## [0.4.0](https://github.com/Greidal/picdrop/compare/v0.3.0...v0.4.0) (2026-09-27)

### ⚠ BREAKING CHANGES

Deployment changes – see [UPGRADING.md](UPGRADING.md#040) for the full steps:

* **Back up the database before updating.** MariaDB is upgraded from 10.11 to 12.3 LTS automatically on the first start.
* **Take over the new `docker-compose.yml`** (container port 80 → 8080, hardening, internal database network).
* **Own port mappings** must target container port **8080**.
* Users are logged out when the container restarts (sessions in tmpfs).

### Features

* **auth:** password reset and re-sending of verification mails ([23240a8](https://github.com/Greidal/picdrop/commit/23240a889f132763adaf3bcc4a5a2acb4fe29af0))
* **docker:** run the container hardened and without root ([8c3280c](https://github.com/Greidal/picdrop/commit/8c3280c77a6d0ca9753cc82a0aff1d3cab009323))
* **privacy:** optionally remove GPS location data from uploaded photos ([270dc01](https://github.com/Greidal/picdrop/commit/270dc017edf224ea24c0b6adc763cf3b96f54d7a))

### Dependencies

* **deps:** stop Dependabot from proposing MariaDB version jumps ([281416f](https://github.com/Greidal/picdrop/commit/281416f153b062d6186c9897d231d240d05ebe68))
* **deps:** upgrade MariaDB to 12.3 LTS ([b29ce43](https://github.com/Greidal/picdrop/commit/b29ce43607fe0b0e6237f1aac892513ba34eae90))

### Bug Fixes

* **ci:** quote Dependabot update types ([a3a9ee1](https://github.com/Greidal/picdrop/commit/a3a9ee16b3fa6ee8b971efce574250aaf3f80252))

# [0.3.0](https://github.com/Greidal/picdrop/compare/v0.2.3...v0.3.0) (2026-09-26)


### ⚠ Upgrade notes

* **Rotate all secrets** (database, SMTP, admin password): `info.php` exposed the environment to every logged-in user.
* Set a **new `REGISTRATION_CODE`** – there is no built-in default anymore; without it only invited users can register.
* `SMTP_USER` and `SMTP_FROM_EMAIL` must be set; set `APP_URL` to the public URL.
* See [UPGRADING.md](UPGRADING.md#030) for details.


### Bug Fixes

* **security:** harden app against RCE, SQL injection, XSS and CSRF ([4f25807](https://github.com/Greidal/picdrop/commit/4f258073d3d05264b1d12519ae13fb85db629ff1))


### Features

* **gallery:** serve thumbnails and display-size images instead of ([1cde1d9](https://github.com/Greidal/picdrop/commit/1cde1d9269b3990c1ecde0cabb17d3ccdf8cbc47))

## [0.2.3](https://github.com/Greidal/picdrop/compare/v0.2.2...v0.2.3) (2026-06-23)


### Bug Fixes

* parameterize email subject and refine user-facing error messages (remove arsch) ([4df8001](https://github.com/Greidal/picdrop/commit/4df80014280c0c9e19c7d77625381c2e51af9c15))
