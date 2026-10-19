# Deploying ComTSo

Production runs from the **pre-built image** `ghcr.io/vincentchalnot/comtso` that GitHub Actions publishes
(`.github/workflows/ci.yml`: `latest` = default branch, `sha-<short>` = every build, `1.2.3` / `1.2` = git tag `v1.2.3`).
Nothing is built on the server: it only needs Docker with Compose v2 and the two files of this directory.

```
deploy/compose.yaml          app (FrankenPHP + Caddy + embedded Mercure hub) · migrate (one-shot) · database (MariaDB 11.4)
deploy/compose.traefik.yaml  optional override: shared Traefik instead of published ports (see "Reverse proxy options")
deploy/compose.www-redirect.yaml  optional second override (Traefik, apex domain only): permanent redirect www.<SERVER_NAME> -> <SERVER_NAME>
deploy/.env.example          the settings and the 5 secrets, to copy to deploy/.env
```

The Compose project is named `comtso-forum` (volumes `comtso-forum_photos`, `comtso-forum_database_data`, ...), so it can live next to another stack called `comtso` on the same host. Use a directory of its own on the server.

Run every command below **from `deploy/`** (on the server a directory holding `compose.yaml`, `.env`, the two scripts and, in Traefik mode, `compose.traefik.yaml` is enough).

## How configuration and secrets flow

- PHP never reads a `.env` file (there is none in the image, `symfony/dotenv` is not installed): it only sees real environment
  variables, all declared in the `environment:` block of `compose.yaml` with defaults.
- `.env` (mode 600, never committed) is read **by Compose only**. It holds the settings that vary per server (`SERVER_NAME`,
  `COMTSO_IMAGE_TAG`, proxy options) and the five secrets. Compose writes each secret to `/run/secrets/<name>` in the containers; the
  application only receives the *path* (`APP_SECRET_FILE=/run/secrets/app_secret`, ...) and reads the file
  (`%env(trim:file:APP_SECRET_FILE)%` in Symfony, `{file.*}` in the Caddyfile, `MARIADB_*_FILE` for MariaDB).
  Secrets therefore do not appear in `docker inspect` or `printenv`.
- The secrets: `APP_SECRET`, `DATABASE_PASSWORD`, `DATABASE_ROOT_PASSWORD`, `MERCURE_JWT_SECRET`, `MAILER_DSN` (the DSN embeds
  the SMTP password). Use hex/alphanumeric values for the passwords (they are placed in a database URL).
- To rotate a secret: edit `.env`, then `docker compose up -d --force-recreate app` (a secret is a file created WITH the container: `restart` keeps the old value, and `up -d` alone does not notice that only the value in `.env` changed; verified in production: after fixing `MAILER_DSN` the running container still held the old DSN). Exceptions: the database
  passwords are applied by MariaDB when the volume is created, change them with `ALTER USER` first; changing `APP_SECRET` logs everybody out.

## First deploy

1. DNS: `SERVER_NAME` (e.g. `forum.example.com`) must resolve to the server; ports 80 and 443 (and 443/udp) must be reachable
   for the automatic HTTPS certificate (Let's Encrypt, stored in the `caddy_data` volume).
2. The image is private by default on GHCR. Either make the package public (GitHub > your profile/org > Packages > `comtso` >
   Package settings > Change visibility), or log in on the server with a personal access token that has `read:packages`:
   `echo "$TOKEN" | docker login ghcr.io -u <github-user> --password-stdin`.
3. Configure and start:

   ```bash
   cp .env.example .env && chmod 600 .env
   # SERVER_NAME=...   and for each of the 5 secrets:  openssl rand -hex 32
   $EDITOR .env
   docker compose config -q        # fails loudly if a required value is missing
   docker compose up -d            # database -> migrate (one-shot) -> app
   docker compose ps               # app must become "healthy"; migrate is "exited (0)"
   docker compose logs migrate     # the applied migrations
   ```

4. Create the first administrator (the forum is private and registration is off, accounts are created by the owner):

   ```bash
   docker compose exec app php bin/console app:user:create <pseudo> <password> --admin
   # more accounts: same command without --admin; promote/revoke: app:user:promote <pseudo> [--revoke]
   ```

5. Open `https://$SERVER_NAME/login`. Anonymous visitors are always redirected there.

`REGISTRATION_ENABLED=true` in `.env` opens `/register` to anybody: leave it off.

## Security headers

Caddy (`frankenphp/Caddyfile`) sends `Content-Security-Policy`, `X-Content-Type-Options`, `Referrer-Policy`, `X-Frame-Options`, `Permissions-Policy`,
`X-Robots-Tag` and serves `/robots.txt` (`Disallow: /`) without login. `Strict-Transport-Security` is added when `HSTS_ENABLED=true` (default in this compose;
set `HSTS_ENABLED=false` in `.env` only if the site is served over plain HTTP on purpose). If your own proxy sets the same headers, make sure it does not replace
the CSP with a weaker one. The CSP whitelists the one inline theme script of `templates/base.html.twig` by hash; `tests/Security/CaddyCspTest.php` fails when they diverge.

## Reverse proxy options

| Mode | `.env` | Notes |
|---|---|---|
| **Direct HTTPS** (default) | `SERVER_NAME=forum.example.com` | Caddy listens on 80/443 and gets the certificate itself. `TRUSTED_PROXIES` stays empty. |
| **Behind your own proxy** (nginx, Caddy, HAProxy...) | `CADDY_SITE_ADDRESS=:80`, `TRUSTED_PROXIES=<proxy IP/CIDR or private_ranges>`, `HTTP_BIND=127.0.0.1:8080`, `HTTPS_BIND=127.0.0.1:8443` | The proxy terminates TLS and forwards to `127.0.0.1:8080`, keeping `Host` and sending `X-Forwarded-For/Proto/Host`. Mercure needs streaming: disable response buffering for `/.well-known/mercure` (nginx: `proxy_buffering off; proxy_read_timeout 1h;`). |
| **Traefik** on a shared Docker network | `COMPOSE_FILE=compose.yaml:compose.traefik.yaml`, `CADDY_SITE_ADDRESS=:80`, `TRUSTED_PROXIES=private_ranges`, `CERT_RESOLVER=<your resolver name>`, optional `TRAEFIK_NETWORK` | The override file drops the published ports, joins the external network (default `proxy`) and adds the labels (router `comtso-forum`, entrypoint `websecure`, Traefik forwards to port 80 of `app`). Traefik's own entrypoint middlewares (e.g. a global `headers` middleware) apply on top: check the final headers with `curl -I`, a global `Content-Security-Policy` there **replaces** the one of Caddy. |

For the `www.` redirect add `compose.www-redirect.yaml` to `COMPOSE_FILE` (`compose.yaml:compose.traefik.yaml:compose.www-redirect.yaml`); use it only with an apex `SERVER_NAME` such as `comtso.fr` (for `next.comtso.fr` Traefik would request a certificate for `www.next.comtso.fr`).

Whatever the mode, the Symfony app publishes chat updates to the embedded Mercure hub over an internal plain-HTTP listener
(`http://app:8080`, not published); browsers subscribe through `https://$SERVER_NAME/.well-known/mercure` with a private,
per-member JWT cookie.

## Upgrade

```bash
docker compose exec -T database sh -c 'mariadb-dump -uroot -p"$(cat /run/secrets/database_root_password)" --single-transaction --routines comtso' | gzip > ../backup-$(date +%F-%H%M).sql.gz   # always first
docker compose pull                  # new image for COMTSO_IMAGE_TAG (latest, or the tag you set)
docker compose up -d                 # recreates app on the new image; migrate (one-shot) runs first, app only starts once it succeeded
docker compose ps && docker compose logs --tail=50 migrate app
```

To pin a version instead of following `latest`, set `COMTSO_IMAGE_TAG=1.2.3` (or `sha-abc1234`) in `.env`.
The `migrate` service runs on every `docker compose up -d` (a no-op when the schema is current) and is a dependency of `app`:
if a migration fails, `app` is **not started** (read `docker compose logs migrate`, then roll back, see below). Expect a short downtime during the upgrade.
Never run more than one `app` container (the Mercure hub state lives in it) and never run `migrate` by hand while `app` is being replaced.

## Rollback

```bash
# .env: COMTSO_IMAGE_TAG=<previous tag, e.g. sha-abc1234 or 1.2.2>
docker compose pull && docker compose up -d
```

Migrations only go forward. If the faulty release added a migration, the previous code may not match the new schema:
restore the database backup taken before the upgrade (next section) **before** starting the old image, or write and ship a fix-forward release.

## Backup and restore

State = the `database_data` volume (MariaDB) and the `photos` volume (`/app/var/photos`; thumbnails under `cache/` are regenerated on demand,
`originals/` is irreplaceable). Certificates (`caddy_data`) are re-issued automatically.

```bash
./backup.sh      # db-<timestamp>.sql.gz + photos-<timestamp>.tar.gz in ${BACKUP_DIR:-./backups}, keeps ${BACKUP_KEEP_DAYS:-14} days, exit != 0 on failure
./restore.sh --yes backups/db-20260101-030000.sql.gz [backups/photos-20260101-030000.tar.gz]   # DESTRUCTIVE: stops app, reloads the DB, restarts
```

Daily cron (adjust the path; cron mails you the output when it fails):

```cron
30 3 * * * cd /opt/comtso/deploy && BACKUP_DIR=/var/backups/comtso ./backup.sh >/dev/null
```

Both scripts need the stack running (`docker compose up -d`). Copy the backups off the server: the DB dump holds password hashes and private
messages, treat it as a secret.

## Legacy import (history before 2012)

The history of the old platforms was imported once on the development machine (`app:legacy:import` for the Symfony 2 dump, then the
single-use `app:legacy:reimport` for the older phpBB/PHP forums, see `docs/legacy-import.md`). **The recommended way to populate production is to
ship that result**: take a DB dump and a `originals/` archive on the dev stack (same commands as in "Backup and restore", with the dev compose file), then restore them on the server as above.

The full chain was rehearsed on the dev stack on 2026-10-19 (timings, counts and the list of recovered phpBB 3 attachments in `docs/legacy-import.md`): the result is a pair `db-<stamp>.sql.gz` +
`photos-<stamp>.tar.gz` in exactly the format of `backup.sh` (`photos-*.tar.gz` holds a top-level `originals/`), so `./restore.sh` takes it as is; the same order applies to a new run — dump → `comtso_legacy`
→ `app:legacy:import --force` (repairs the double-encoded text, converts the photos) → `app:legacy:reimport` (phpBB 2/3, old PHP forum, attachments) → `app:photos:reprocess` → `app:content:sanitize`.
Imported accounts stay disabled (their real e-mail address is kept when the old platform knew it): members who want back in use "forgotten password".

`app:legacy:reimport` needs two throwaway databases (`legacy-mysql`, `legacy-postgres`) that only exist in the dev `compose.yaml`; do not run it in production.

To run the Symfony 2 importer directly in production instead (empty database only, `--force` wipes the content tables):

```bash
# 1. a side database on the same server, readable by the application user
docker compose exec -T database sh -c 'mariadb -uroot -p"$(cat /run/secrets/database_root_password)" -e "CREATE DATABASE comtso_legacy CHARACTER SET utf8mb4; GRANT SELECT ON comtso_legacy.* TO \"comtso\"@\"%\";"'
# 2. load the dump (it stays on the host, e.g. ./legacy/2610110300.sql.gz)
zcat legacy/2610110300.sql.gz | docker compose exec -T database sh -c 'mariadb -uroot -p"$(cat /run/secrets/database_root_password)" comtso_legacy'
# 3. legacy photo files into a scratch directory of the photos volume (the importer moves/copies them to originals/)
docker compose exec -T app mkdir -p /app/var/photos/legacy-files
docker compose cp legacy/photos/. app:/app/var/photos/legacy-files/
docker compose exec -u root app chown -R www-data:root /app/var/photos
# 4. import (add --force if the target already holds users), then drop the side database and the scratch files
docker compose exec app php bin/console app:legacy:import --source=comtso_legacy --photos-source=/app/var/photos/legacy-files --copy-photos
docker compose exec -T database sh -c 'mariadb -uroot -p"$(cat /run/secrets/database_root_password)" -e "DROP DATABASE comtso_legacy"'
docker compose exec -T app rm -rf /app/var/photos/legacy-files
```

## Operations

```bash
docker compose logs -f app                                  # Caddy access log + PHP errors
docker compose exec app php bin/console app:photos:warm     # pre-generate thumbnails (lazy otherwise)
docker compose exec app php bin/console app:content:sanitize --dry-run   # after changing the HTML allow-list
docker compose exec app php bin/console app:user:reset-password <pseudo>   # lost password and no usable e-mail: prints a one-time password to hand over
docker compose exec -T -e XDEBUG_MODE=off app php bin/console app:cutover:prepare --no-debug --dry-run   # once, right after the final import; then without --dry-run (--delete-user=<pseudo> for test accounts)
docker compose down                                          # stop (volumes are kept); NEVER add -v unless you want to destroy the data
```

Password-reset e-mails go out through `MAILER_DSN` with the sender `MAILER_FROM` (e.g. `"ComTSo <forum@example.com>"`, default `ComTSo <no-reply@localhost>`: set a real address on the server, in `.env` or the shell) and link to `https://$SERVER_NAME`.
`GET /health` answers `200 ok` while the database is reachable; the `app` container's healthcheck (`docker compose ps`) calls it through loopback on the internal listener, so `healthy` means Caddy + PHP + MariaDB.

### Outgoing mail: Scaleway Transactional Email (recommended)

`symfony/mailer` (already required) handles `smtp://` natively, nothing to install. With Scaleway TEM:

```bash
MAILER_DSN=smtp://<SCW_PROJECT_ID>:<SCW_SECRET_KEY>@smtp.tem.scaleway.com:587   # STARTTLS; 465 = implicit TLS (smtps://)
MAILER_FROM="ComTSo <no-reply@your-validated-domain>"
```

The username is the Scaleway project ID, the password an API secret key (both from the Scaleway console); the sender domain must be validated in the TEM console
(SPF + DKIM records, MX + DMARC recommended) before the first mail. Apply with `docker compose up -d --force-recreate app` (the secret is a file created with the container), then request a reset from `/login`
with one real address and check the headers (`spf=pass dkim=pass`).

## Go-live checklist

The ordered, step-by-step plan to take the redesigned forum to production on the sidus.fr server (decisions, secrets, rehearsal, cutover, rollback, smoke tests): [`docs/deployment-checklist.md`](../docs/deployment-checklist.md).

## Logs

- **Application** (Symfony/Monolog, JSON on stderr, `docker compose logs app`): silent until an error occurs, then the last 50 records (info and above) that led to it are written. A separate **`audit`** channel is always written (notice and above): failed logins and throttling (`Login failed`: pseudo as typed, client IP, reason class, never the password) and the password-reset mail trail (`Password reset requested, no e-mail sent: <reason>`, `... handed to the mail transport`, `Password reset e-mail NOT sent` with the recipient *domain*, the exception class and the masked reason). Deprecations are dropped in production.
- **Caddy access log is off** by default (the healthcheck alone would add a line every 30 s and Caddy cannot filter on status). `CADDY_ACCESS_LOG=on` in `.env` writes every request except `/health` (the Mercure `authorization` query parameter is redacted). Caddy's own runtime messages stay on.
- **Docker** rotates every service's log (json-file, 5 x 10 MB).
- Quick looks: `docker compose logs --since 1h app | grep -E '"channel":"audit"'`, `... | grep '"level":"ERROR"'`.
