# Operations: production server facts and runbooks

`deploy/README.md` is the authoritative runbook (first deploy, configuration and secrets, upgrade, image rollback, backup and restore scripts, mail,
logs). This file only holds what is specific to the production server and not covered there. Decisions and history: `docs/decisions.md`.

## Server layout

| Topic | Fact |
| --- | --- |
| Host | `sidus.fr`: Ubuntu 24.04, x86_64 (same architecture as the CI image), 2 vCPU, 1.8 GiB RAM with swap. The only account is `root`. Several unrelated Compose projects run on it behind one shared Traefik. Keep an eye on `free -h` and `docker stats --no-stream`: RAM is tight, so photo reprocessing never runs here (dev machine only). |
| New forum | `/root/www/comtso-forum` (`ST` below): `compose.yaml`, `compose.traefik.yaml`, `compose.www-redirect.yaml`, `.env` (mode 600, never copied from dev), `backup.sh`, `restore.sh`, `README.md`. Compose project `comtso-forum`. After any change in `deploy/`, copy the files again: `rsync -av compose.yaml compose.traefik.yaml compose.www-redirect.yaml .env.example backup.sh restore.sh README.md sidus.fr:/root/www/comtso-forum/`. |
| Old forum | `/root/www/comtso`, Compose project `comtso` (the old Symfony forum, PHP 5.6, `nginx` + `php-fpm` + MariaDB 10.3). Do not reuse this directory or project name for anything new. Its weekly dumps and original photos are under `shared/app/data/` there. |
| Traefik | `/root/www/traefik`, owns host ports 80 and 443, Docker provider on the external network `proxy`, resolver `gandi` (HTTP-01). A global middleware `security-headers@file` adds HSTS and `X-Frame-Options: SAMEORIGIN` to every router and no longer sets a CSP (D6). |
| DNS | NS at Gandi. `comtso.fr`, `www.comtso.fr` and the wildcard `*.comtso.fr` resolve to the server (the wildcard does not cover `www.next.comtso.fr`, hence the `www` redirect is for the apex only). Mail records for Scaleway TEM: MX `blackhole.tem.scaleway.com`, SPF `include:_spf.tem.scaleway.com`, DMARC `p=none`; the DKIM record comes from the TEM console. |
| fail2ban | Jails `sshd` and `traefik-404` (20 x 404 in 60 s = 1 h ban). Check: `fail2ban-client status traefik-404`; unban: `fail2ban-client set traefik-404 unbanip <ip>`. |
| Outbound | `smtp.tem.scaleway.com:587` and `ghcr.io:443` are reachable. |

Server `ST/.env` (secrets omitted, generated on the server with `openssl rand -hex 32`, never printed): `COMTSO_IMAGE_TAG=latest` on 2026-10-19 (the weekly refresh and every green push of `master` move it; pin `sha-<short>` of a green build to freeze a release, see `deploy/README.md` "Rollback"),
`SERVER_NAME=comtso.fr`, `COMPOSE_FILE=compose.yaml:compose.traefik.yaml:compose.www-redirect.yaml`, `CADDY_SITE_ADDRESS=:80`, `TRUSTED_PROXIES=private_ranges`,
`CERT_RESOLVER=gandi`, `TRAEFIK_NETWORK=proxy`, `HSTS_ENABLED=true`, `MAILER_FROM="ComTSo <no-reply@comtso.fr>"`, `MAILER_DSN` (Scaleway TEM, `deploy/README.md`).
Validate after an edit without printing values: `cd ST && docker compose config -q && echo CONFIG_OK`.
Upgrades: `./backup.sh`, set the tag, `docker compose pull && docker compose up -d`, `docker compose logs --tail=50 migrate app`, `curl -s https://comtso.fr/health` (`deploy/README.md` "Upgrade").

**`www.comtso.fr` redirect** (`compose.www-redirect.yaml`, service `www-redirect`). Diagnosis of 2026-10-19, why it did not work: (1) the server's `ST/.env` had `COMPOSE_FILE=compose.yaml:compose.traefik.yaml` (the docs said the third file was listed, the server never had it), so the labels were never created: Traefik had no router for `www.comtso.fr`, answered its default 404 (`curl -skI https://www.comtso.fr/` = `404`), and with no certificate for the name it served its self-signed default one (`curl` without `-k` failed, exit 60); `http://www…` only got the entrypoint's `308` to `https://www…`, i.e. a dead end. DNS was fine (apex and `www` both A `217.154.10.101`, plus the wildcard). (2) The old file also put the labels on `app`: Docker labels cannot change on a running container, so enabling it meant recreating the forum. (3) Once on its own service, Traefik logged `service "www-redirect-comtso-forum" error: port is missing` and dropped the container: a `loadbalancer.server.port` is needed even though the router answers through `noop@internal`. No router clash: the `www` router matches another host, and HSTS (`security-headers@file` on `websecure`) is sent on the redirect too, which is harmless. The redirect is therefore two hops from plain HTTP (`http://www…` → `https://www…` by the entrypoint, then → `https://comtso.fr/…`).
Applied on the server without touching `app` (its start time and health unchanged): copy `compose.www-redirect.yaml`, put `compose.www-redirect.yaml` in `COMPOSE_FILE` (in place: `cat`, not `sed -i`, on the 600 `.env`), `docker compose up -d --no-deps www-redirect`; Traefik then obtained the Let's Encrypt certificate for `www.comtso.fr` by itself (about 5 s). Check from anywhere: `for u in http://www.comtso.fr/ https://www.comtso.fr/ 'https://www.comtso.fr/forums/x?a=1&b=2'; do curl -sI "$u" | grep -iE '^(http|location)'; done` must show `308` and `location: https://comtso.fr/…` (path and query kept; the plain-HTTP one shows the intermediate `https://www…` hop, `curl -sIL -o /dev/null -w '%{http_code} %{url_effective}\n'` ends on `200 https://comtso.fr/login`). Never `docker compose down` for this: only `up -d --no-deps www-redirect`.

## Rollback to the old forum (target: under 5 minutes)

The old stack is not deleted until it is retired on purpose (see below): only its `nginx` was stopped, `php-fpm` and `mysql` keep running.

```bash
ssh sidus.fr 'cd /root/www/comtso-forum && docker compose stop app && cd /root/www/comtso && docker compose start nginx && sleep 5 && curl -sI https://comtso.fr | head -3'
```

Expected: `HTTP/2 302` to `/login` and `x-powered-by: PHP/5.6.40`. Anything posted on the new forum since the switch is NOT in the old database; the new DB stays untouched in the volume `comtso-forum_database_data` and can be exported by hand.
Worst case (old MySQL volume damaged): the weekly `…0300.sql.gz` dumps of `shared/app/data/dumps/` (and the final dump taken before the cutover, if one was kept) load into a new `mariadb:10.3`/`11.4`.
Rolling back only a bad release of the NEW app (image tag, backup restore when a migration was added): `deploy/README.md` "Rollback".
Traefik global file: it is a single-file bind mount, so restore it IN PLACE with `cat /root/www/traefik/traefik.yaml.bak-precutover-2026-10-19 > /root/www/traefik/traefik.yaml` (never `cp` or `sed -i`, which create a new inode and detach the mount; Traefik hot-reloads). The earlier backup `traefik.yaml.bak-2026-10-19` is the state before the global CSP was removed.

## Retiring the old stack

After a stable period, with a fresh dump kept off the server: `cd /root/www/comtso && docker compose stop` (never `down -v`), and much later remove the folder. Never run its `make deploy` again. Then delete the `db-*.sql.gz` / `photos-*.tar.gz` uploads left in `ST` (they hold password hashes and private messages).

## Backups and restore checks

- **Mechanism**: the NAS script (`Backups/comtso/backup.sh` on the NAS, original kept next to it as `backup.sh.orig-2026-10-19`) backs up the old forum (dump + `rsync` of `shared/app/data`) and, when `ST/.env` exists and the `app` service is running, runs `ST/backup.sh` with `BACKUP_DIR=/var/backups/comtso`, `BACKUP_KEEP_DAYS=7`, then mirrors that directory into `new-forum/` (no deletion: prune by hand). Exit status is non-zero if either part failed. A server-side cron of `backup.sh` would be redundant: keep one of the two, or the photo archive is made twice. Sizing: about 4 MB DB + 850 MB photos per run, so 7 days is about 6 GB.
- **Manual run**: `cd ST && BACKUP_DIR=/var/backups/comtso ./backup.sh && ls -lh /var/backups/comtso` writes `db-<stamp>.sql.gz` + `photos-<stamp>.tar.gz` (there is no dry-run flag); both must pass `gzip -t`.
- **Restore test on a scratch database** (never on the live one), then compare counts:

  ```bash
  cd ST && R='mariadb -uroot -p"$(cat /run/secrets/database_root_password)"'
  docker compose exec -T database sh -c "$R -e 'CREATE DATABASE comtso_restore_test CHARACTER SET utf8mb4'"
  gunzip -c /var/backups/comtso/db-<stamp>.sql.gz | docker compose exec -T database sh -c "$R comtso_restore_test"
  docker compose exec -T database sh -c "$R -N -e 'SELECT (SELECT COUNT(*) FROM comtso.ctso_comment), (SELECT COUNT(*) FROM comtso_restore_test.ctso_comment)'"
  docker compose exec -T database sh -c "$R -e 'DROP DATABASE comtso_restore_test'"
  ```

  Expected: two identical numbers; also `tar tzf /var/backups/comtso/photos-<stamp>.tar.gz | head`.
- **Off-site**: the dump holds password hashes and private messages (treat as a secret). Keep a copy of `ST/.env` in the password manager.
- **Photos**: `restore.sh` extracts the archive as `www-data` into the named volume; do not bind-mount the old photo directory (uid 1000 on the host). To replace the data completely, wipe `/app/var/photos/originals` and `/app/var/photos/cache` in the `app` container first, otherwise stale files stay.
- **Checked on 2026-10-19**: (a) a restore test of a full dump of the dev database (it holds the production data) into a scratch database restored cleanly and `fos_user` / `ctso_comment` / `ctso_topic` / `ctso_photo` row counts were identical (18 / 25 981 / 501 / 2 425); (b) on the server `/var/backups/comtso` holds one manual run (`db-20261019-113347.sql.gz`, 4.4 MB, and `photos-…tar.gz`, 347 MB, 2 426 entries), both `gzip -t` clean; (c) **the NAS mirror `Backups/comtso/new-forum/` did not exist yet**: the NAS job has not run since the new stack went live (last NAS dump of the old forum: 2026-10-19 07:39). Owner: run `Backups/comtso/backup.sh` on the NAS once by hand (or wait for the Sunday 03:00 run), then check that `new-forum/` holds a `db-*.sql.gz` and a `photos-*.tar.gz` of the day and that `ls -l` sizes match the server's; repeat the scratch restore of the NAS copy once (procedure above).

## GitHub repository, CI and ghcr

- **Settings applied on 2026-10-19** (`gh api repos/vincentchalnot/comtso`): secret scanning and push protection on, Dependabot alerts and security updates on, the default workflow token read-only (`default_workflow_permissions=read`, Actions cannot approve pull requests; every workflow also declares `permissions:`), wiki and projects off, branches deleted after merge. Unchanged: public repository, default branch `master`, issues on, forks allowed (one fork exists, `freshy969/ComTSo`, last pushed 2022: it carries the old, pre-rewrite history; ask GitHub Support to purge it together with the cached views, see `docs/decisions.md`), all three merge methods allowed.
- **Branch protection is NOT on yet**, deliberately: it would reject the one force-push of `master` that follows the rebase-signing of the latest commits. Once that push is done, apply (classic protection: no force push, no deletion, linear history, the CI jobs must be green, admins keep a bypass so the owner can still push directly; a push of unchecked commits then needs the checks to have run, so the normal flow becomes branch + pull request):

  ```bash
  gh api -X PUT repos/vincentchalnot/comtso/branches/master/protection --input - <<'EOF'
  {"required_status_checks":{"strict":false,"contexts":["test","frontend","image","gitleaks"]},"enforce_admins":false,
   "required_pull_request_reviews":null,"restrictions":null,"required_linear_history":true,"allow_force_pushes":false,"allow_deletions":false}
  EOF
  gh api -X POST repos/vincentchalnot/comtso/branches/master/protection/required_signatures
  ```

  Remove it temporarily (`gh api -X DELETE repos/vincentchalnot/comtso/branches/master/protection`) before any intentional force-push.
- **Security checks of CI**: `gitleaks` scans the whole history on every run (blocking, required by `publish`; `.gitleaks.toml` allows only the reviewed development findings, by path and line so that a rebase does not invalidate it), `audit` is advisory, `.github/dependabot.yml` opens weekly grouped pull requests (composer, npm, docker, docker-compose, github-actions; FrankenPHP, the MariaDB line and Symfony majors are ignored on purpose, see the comments in that file). The `image` job also boots the freshly built production image through `deploy/compose.yaml` with throwaway secrets (`/health` = `ok`, `/` redirects to `/login`); it is loaded into the runner only, never pushed.
- **ghcr**: `comtso` and `comtso-base` are both public (anonymous pull token works). `comtso-base` keeps one tag, the pin of `compose.yaml`: never delete it. Do not change package visibility through the API (not possible), only in the package settings page. Orphaned application tags (their commits no longer exist after the history rewrite; the server runs the digest of `sha-74d6881`, which is NOT orphaned): `sha-9148a6d sha-02d8848 sha-7df1a52 sha-85c15d7 sha-8270580 sha-18efd24`. Deleting a tag needs the token scopes `read:packages` and `delete:packages` (the stock `gh` token has neither):

  ```bash
  gh auth refresh -h github.com -s read:packages,delete:packages
  for t in sha-9148a6d sha-02d8848 sha-7df1a52 sha-85c15d7 sha-8270580 sha-18efd24; do
    id=$(gh api --paginate /user/packages/container/comtso/versions --jq ".[] | select(.metadata.container.tags | index(\"$t\")) | .id")
    [ -n "$id" ] && gh api -X DELETE "/user/packages/container/comtso/versions/$id"
  done
  ```

## Secret rotation

Rotate every secret that was pasted in a chat, ticket or terminal log (Scaleway API key, passwords typed on a command line, the admin password) and any ghcr token. Edit `ST/.env`, then `docker compose up -d --force-recreate app`
(secrets are files created WITH the container). `APP_SECRET` logs everybody out; database passwords need `ALTER USER` first (`deploy/README.md` "How configuration and secrets flow").

## Monitoring

- Uptime check on `https://comtso.fr/health` (`200` and body `ok`; `503` = database down): UptimeRobot, Scaleway Cockpit or similar.
- After an upgrade: `docker compose logs --since 30m app | grep -iE "error|critical|emergency"`, `docker compose ps` all healthy, `free -h` still has headroom, no member IP in `fail2ban-client status traefik-404`.
- Sentry is not installed: see the plan below.

## Mail troubleshooting

- Compose secrets are files written when the container is created: after fixing `MAILER_DSN`, `docker compose up -d --force-recreate app` is required (a plain `up -d` or `restart` keeps the old DSN; this caused the first failures in production, an AUTH error 535).
- The reset form is silent by design (neutral answer, 3 requests per hour per account, 10 per hour per IP): after three failed attempts the fourth logs nothing and sends nothing. The `audit` log channel records every skip reason and transport failure (`deploy/README.md` "Logs"); look there first: `docker compose logs --tail=100 app | grep -iE "mailer|smtp|transport"`, then the Scaleway console, TEM, Logs, then the spam folder.
- Test with ONE reset request for the owner's own address only (the data holds real members' addresses). Check the received headers: `spf=pass dkim=pass dmarc=pass`.
- Tighten DMARC from `p=none` to `p=quarantine` at Gandi once SPF and DKIM pass in real mails.

## Admin panel (`/admin`)

Administrators only (anonymous = `302 /login`, a member = 403); the page list and the rules are in `AGENTS.md` ("Admin panel"). Operations it covers: enable/disable a member and grant/remove `ROLE_ADMIN` (what `app:user:promote` does), delete a member with everything they wrote (`UserRemover`, a confirmation page lists what goes), create/rename/reorder forums and delete EMPTY ones, delete a topic (moderation), manage the random quotes, delete a photo with its files.
It never shows e-mail addresses, never changes a password (`app:user:reset-password` stays the fallback) and cannot disable, demote or remove its own account or the last active administrator; if no administrator is left (SQL accident): `app:user:promote <pseudo>`. Destructive actions are real on production: try them on a rehearsal host with throwaway members only (create with `app:user:create`, remove from the panel). After a release, `/admin/users/` answering 200 twice in a row proves the worker-mode fix is in the image.

## Smoke test after a release (host `H`)

Read-only, `curl`: `https://H/health` is `ok` with `Cache-Control: no-store`; `/robots.txt` is `Disallow: /`; `/` answers `302` to `/login` with a `secure; httponly; samesite=lax` session cookie;
anonymous `/photos/1/thumb` and `/forums/` answer `302` to `/login`; anonymous `/.well-known/mercure?topic=chat` answers 401/403, never 200; `/login` carries `nosniff`, `Referrer-Policy: same-origin`, `X-Frame-Options`, `Permissions-Policy`, `X-Robots-Tag`, HSTS and the app's own full CSP (`default-src 'self'; script-src 'self' 'sha256-...'`; if only `frame-ancestors` shows, a proxy is overriding it).
In a browser: log in with an old-hash member and an admin; the throttle only with a non-existent pseudo (six wrong passwords, never a real member); browse home, a multi-page topic, an old topic, `/photos`, a profile, search, private messages, the legacy link `/photos/preview/N.jpg` (301);
no CSP violation in the console (home, topic with editor, chat, lightbox); chat realtime through the proxy (a message appears on a second session without reload; `/.well-known/mercure` stays open as `text/event-stream`; if not, check buffering and `MERCURE_PUBLIC_URL`).
Writes (posts, uploads) are real on production: do them on a rehearsal host only. The results of the browser checks (CSP behind Traefik, chat realtime, login of old-hash members other than the owner) were not recorded at go-live.

## Traefik, CSP and Keres

D6 (each project owns its CSP): on 2026-10-19 the line `contentSecurityPolicy: "frame-ancestors 'self' https://*.sidus.fr"` was removed from the `security-headers` middleware of `/root/www/traefik/traefik.yaml`; HSTS, `X-Frame-Options`, nosniff and the redirect stayed. Sites behind Traefik that set no CSP of their own now have none.
Keres (`app.playkeres.com`) sets none itself: a per-router CSP is prepared in its own repository as Traefik labels (`traefik.http.middlewares.keres-csp.headers.contentsecuritypolicy`), with `'unsafe-inline'` for script and style because it has 2 inline `<script>` blocks and about 58 inline `style=` attributes, Mercure through a same-origin `EventSource`, no third-party scripts or fonts, `frame-ancestors 'self' https://*.sidus.fr`.
After it is deployed, check the lobby, a game and the settings for CSP violations and that live updates arrive; relax only the directive named in a console message. A nonce-based policy is a later step.

## Sentry plan (not installed; `TODO.md` wave 2)

- **Hosting**: sentry.io free/developer plan, EU region (member data). Self-hosted Sentry needs 16 GB+, far beyond this server.
- **SDK**: `composer require sentry/sentry-symfony`; `config/packages/sentry.yaml` under `when@prod`; DSN through `%env(trim:file:SENTRY_DSN_FILE)%` (a `*_FILE` secret like the others: `.env.example`, `secrets:` entries, `SENTRY_DSN_FILE`); `release: '%env(APP_RELEASE)%'` set from the image tag (`COMTSO_IMAGE_TAG`); `environment: prod`.
- **Privacy** (private forum, no message content in events): `send_default_pii: false`, tracing and profiling off (`traces_sample_rate: 0`), a `before_send` that drops request bodies, cookies and `Authorization` and scrubs `password`, `_password`, `plainPassword` and the reset-link parameters (`u=`, `k=`).
- **Monolog**: a `sentry` handler at level `error` on the `main` fingers_crossed chain, not on the `audit` channel (which stays in the logs).
- **Browser SDK**: optional, later; it would need `connect-src 'self' https://*.ingest.sentry.io` (or a tunnel route) in the Caddyfile CSP. Server-side errors only at first.
- **Order**: install on a branch, deploy to a rehearsal host, trigger a test exception, check the scrubbing in the event JSON, then enable in production.
