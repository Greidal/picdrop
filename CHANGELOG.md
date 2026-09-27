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
