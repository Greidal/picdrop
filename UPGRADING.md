# Upgrading PicDrop

Steps you need to take when updating an existing installation. Releases not listed here
need nothing beyond pulling the new image (`docker compose pull && docker compose up -d`).

## 0.4.0

1. **Back up the database while it still runs MariaDB 10.11:**
   ```sh
   docker compose exec db sh -c 'mariadb-dump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --all-databases' > backup.sql
   ```
2. **Take over the new `docker-compose.yml`.** It changes:
   - the app container port from 80 to **8080** (the Traefik label is included),
   - container hardening (read-only root filesystem, no capabilities, resource limits),
   - an internal `backend` network for the database (no internet access),
   - the database image to **MariaDB 12.3 LTS**. The data directory is upgraded automatically
     on the first start (`MARIADB_AUTO_UPGRADE`).
3. **Own port mappings** must point to the new container port, e.g. `"8080:8080"` instead of `"8080:80"`.
4. Sessions are kept in memory (tmpfs): users are logged out whenever the container restarts.
5. Optional: verify the signed image before deploying:
   ```sh
   gh attestation verify oci://ghcr.io/greidal/picdrop:v0.4.0 --owner Greidal
   ```

No new required environment variables. `SMTP_SECURE=none` is now available for mail servers
without TLS. Database migrations run automatically on container start.

## 0.3.0

1. **Rotate all secrets** (database, SMTP and admin passwords): before 0.3.0, `info.php` exposed
   the environment to every logged-in user.
2. **Set a new `REGISTRATION_CODE`.** There is no built-in default anymore and the old code is
   public. Without it, only invited users can register.
3. **Set `SMTP_USER` and `SMTP_FROM_EMAIL`** – they no longer have built-in defaults, e-mails fail
   without them.
4. **Set `APP_URL`** to the public URL (e.g. `https://picdrop.example.com`); it is used for links in
   e-mails and the QR code.
5. Take over the new `docker-compose.yml` (database health check, `APP_URL`/`PAGE_TITLE`).
