# ComTSo go-live checklist (production on `sidus.fr`)

Ordered plan to replace the Symfony 2 forum at `https://comtso.fr` with the redesigned app. Written from a **read-only recon of the server on 2026-10-18**
(nothing was written, restarted or pulled there) and from `deploy/` (`compose.yaml`, `compose.traefik.yaml`, `.env.example`, `backup.sh`, `restore.sh`).

Tags on every step:

* **`[ME]`** the assistant can do it (non-destructive); **`[YOU]`** only the owner can (secrets, DNS, GitHub/Scaleway consoles, decisions, anything posted on the real forum).
* **`[RISKY — WAIT FOR GO]`** changes the server, touches production data, switches traffic, imports data or sends real mail: nothing marked so is run without an explicit go.
* `- [x]` = already verified (how is written in the line).

Conventions: `ST=/root/www/comtso-forum` is the NEW stack directory on the server (the old forum owns `/root/www/comtso`: **do not reuse that directory**, its `compose.yaml` and `.env` would clash).
`H` = the host name being tested (`next.comtso.fr` during the rehearsal, `comtso.fr` after the switch). All server commands run as `root` over `ssh sidus.fr` (that is the only account there).
Dev-machine commands run from `/home/vincent/www/comtso` through `docker compose exec` (never PHP/composer/npm on the host, never `docker compose down -v` on the dev project).

---

## 0. Facts discovered about the server (recon, read-only)

| Topic | Finding |
|---|---|
| Host | Ubuntu 24.04.5, kernel 6.8, **x86_64** (CI builds one platform, linux/amd64 on `ubuntu-latest`: same arch). 2 vCPU, **1.8 GiB RAM (~740 MiB available, swap 2 GiB with ~650 MiB used)**, load ~0.1. Public IP `217.154.10.101` (also reachable on Tailscale for ssh). |
| Disk | `/` 77 GB, **48 GB free** (39 % used). Docker: 12 GB images, 128 volumes (1.6 GB). |
| Docker | Engine 29.8.2, Compose **v5.6.0**. Seven compose projects run: `traefik`, `comtso` (old forum), `keres`, `meuj`, `sidus`, `baby-names`, `admin-demo`. |
| **Old forum** | `/root/www/comtso`: **Symfony 2 on PHP 5.6.40, Docker**: services `php-fpm` (built from `./current/.docker/php-fpm`), `nginx:alpine`, `mysql` = **mariadb:10.3** (volume `comtso_volume_mysql`, 169 MB; its image has `mysqldump`, no `mariadb` binary). Deployed by a Makefile from `github.com/VincentChalnot/ComTSo` (`repository/` -> `current` symlink). `.env` holds only the keys `REPOSITORY_ADDRESS`, `MYSQL_PASSWORD`, `DOMAIN_NAME` (=`comtso.fr`). The **compose project is named `comtso`**. |
| Reverse proxy | **Traefik** (container `traefik`, `/root/www/traefik`), owns host ports **80, 443/tcp, 443/udp**; Docker provider on network **`proxy`** (exists, external name `proxy`), `exposedByDefault: false`. Entrypoints `web` (redirects to `websecure`) and `websecure`. Cert resolvers `gandi` and `keres`: **both use the HTTP-01 challenge** on `web` (the resolver named `gandi` stores in `letsencrypt/gandi.json`; its DNS-challenge lines are commented out). **Cert resolver to use: `gandi`**. The old forum's labels: router `comtso`, `Host(\`comtso.fr\`)`, `tls.certresolver=gandi`, no entrypoint label. |
| **Global headers middleware** | `entryPoints.websecure.http.middlewares: security-headers@file` applies to **every router**: HSTS (1 y, subdomains, preload), `X-Frame-Options: SAMEORIGIN`. It **used to add** `Content-Security-Policy: frame-ancestors 'self' https://*.sidus.fr` (removed 2026-10-19, section 11). |
| fail2ban | Active, jails `sshd` and **`traefik-404`**: 20 responses with status 404 in 60 s from one IP (read from Traefik's JSON access log) = **1 h ban**. 204 bans so far, no `ignoreip`. |
| Firewall | iptables `INPUT ACCEPT`, `FORWARD DROP` + Docker/ufw/tailscale chains. Listening on the host: 80, 443 (docker-proxy = Traefik), ssh on the Tailscale address, DNS stub. Nothing on 8080/8443 (free for a loopback bind). Outbound to `smtp.tem.scaleway.com:587` and `ghcr.io:443` works (TCP connect tested from the server). |
| DNS | `comtso.fr` and `www.comtso.fr` -> `217.154.10.101` = this server (as does `sidus.fr`); NS at **Gandi**. `comtso.fr` already has `MX 10 blackhole.tem.scaleway.com`, `TXT "v=spf1 include:_spf.tem.scaleway.com -all"`, `_dmarc "v=DMARC1; p=none"`: **the domain already looks configured for Scaleway TEM**. The DKIM record (selector unknown, TEM names it after the project id) was not found by guessing: verify in the TEM console. No CAA record. `www.comtso.fr` has no Traefik router (old forum only answers `comtso.fr`). |
| Old data | Dumps: `/root/www/comtso/shared/app/data/dumps/YYMMDD0300.sql.gz`, **one per week (Sunday 01:00 UTC), root-owned, ~4 MB each, 376 MB for ~100 files since 2024-09; latest `2610180300.sql.gz` (today)**. The job that writes them was **not found** (no crontab, no timer, no cron.d entry): ask the owner. Photos: `/root/www/comtso/shared/app/data/photos/originals` = **2 395 files, 847 MB**, owned `vincent:vincent` (uid/gid 1000, mode 664), plus `cache/{large,preview,thumbnail,tiny}` 167 MB (disposable); `uploads/` empty. Whole `shared/app/data` = 1.4 GB. Old app logs: `shared/app/logs/prod.log` 28 MB. `/root/www/comtso/comtso-php-fpm.tar` (735 MB, root-only) is an image export from 2025-03. |
| Backups | No backup script or cron on the host (host has no crontab, no `/var/backups` entry for apps). Only the weekly dumps above, **on the same disk**. No off-site copy found. |
| Image registry | `ghcr.io/vincentchalnot/comtso` **exists and is public** (anonymous token + tag list work): tags `latest`, **`sha-9148a6d` = current `master` HEAD**. So `master` was pushed and the `image` job ran; no `docker login` needed on the server. |
| Resources of the new stack | App: FrankenPHP (PHP `memory_limit` 768M, 10 MP photo pipeline) + MariaDB 11.4. Existing containers currently use ~330 MiB in total; the host has ~740 MiB available: **tight while old + new stacks run together**, and the photo reprocess must NOT run on this server (see 5.2). |

### Mismatches and risks versus `deploy/compose.yaml` assumptions

| # | Assumption in the repo | Reality on the server | Handling |
|---|---|---|---|
| M1 | Project `name: comtso` | The old forum **is** the project `comtso` (same network `comtso_default`, containers `comtso-*`; `--remove-orphans` would delete the old containers) | **Fixed in the repo**: prod project is now `comtso-forum`; use its own directory `ST`. |
| M2 | Directory with `compose.yaml` + `.env` | `/root/www/comtso` already holds the old `compose.yaml`/`.env`/`Makefile` | New directory `ST=/root/www/comtso-forum`. |
| M3 | App CSP reaches the browser | Traefik's global `security-headers@file` overwrote the CSP of every site | **Fixed 2026-10-19**: the global CSP line was removed (backup `traefik.yaml.bak-2026-10-19`); the app's own CSP now reaches the browser. Check with step 7.5/7.9. |
| M4 | Published ports 80/443 (direct mode) | Held by Traefik; the other 6 sites live behind it | Traefik mode only: `compose.traefik.yaml` publishes nothing. |
| M5 | Commented labels used resolver `letsencrypt`, router `comtso` | Resolver is **`gandi`**; router name `comtso` **is taken** by the old forum (same name + different rule = conflicting Docker-provider config) | **Fixed**: override file uses router/service `comtso-forum`, `CERT_RESOLVER=gandi` in `.env`. |
| M6 | Healthcheck from the image = Caddy metrics | n/a | **Fixed**: `app` healthcheck is now `GET /health` (verified locally healthy / unhealthy with the DB down). Traefik drops a container that is `unhealthy`. |
| M7 | Photos volume owned `www-data` | Old photos are uid 1000 / mode 664 on the host; the new app runs as `www-data` (uid 33) in a **named volume** | Photos are shipped as a tar and extracted by `restore.sh` as `www-data`: no host chown needed. Do not bind-mount the old directory. |
| M8 | Direct Caddy certificate (HTTP-01 on 80) | Impossible while Traefik owns 80/443; Traefik reuses/obtains the cert (existing `comtso.fr` cert is in `gandi.json`) | Traefik mode. |
| M9 | `.env`-less PHP, secrets as files | OK; but `MAILER_DSN=null://null` default silently drops reset mails | Step 3.6/6. |
| M10 | RAM unspecified | ~740 MiB available, 2 vCPU | Heavy jobs on the dev machine; rehearsal and old forum coexist only briefly. Watch `free -h` / `docker stats --no-stream`. |
| M11 | n/a | **fail2ban `traefik-404`** bans an IP after 20 x 404 in 60 s. Stopping the old nginx during the freeze makes Traefik answer 404 for `comtso.fr`; missing photo files (3 known lost photos, `/photos/preview/N.jpg` links) could also 404 for members | Section 5/7 notes; owner may add `ignoreip` (D9). |
| M12 | Old forum embedded elsewhere? | Old CSP allows `frame-ancestors https://*.sidus.fr` and nginx has a `proxy` network alias `comtso.fr`: another site of the server **may embed or call `comtso.fr`** | Ask the owner (D8); new app sends `frame-ancestors 'self'` (but see M3). |

---

## 1. Decisions needed from the owner

Answers received 2026-10-19 (all decided):

- [x] **[YOU]** D1 profile visibility, D13 legacy-import open questions: decided by the owner (no change requested to the code/dataset in this task).
- [x] **[YOU]** D2 password length 10 everywhere (commit `87519eb`, local, to push). **D3** login throttling: owner decided (no change recorded here).
- [x] **[YOU]** D4 rehearsal host `next.comtso.fr` (wildcard DNS, nothing to create); `www.comtso.fr` -> `comtso.fr` permanent redirect wanted: `deploy/compose.www-redirect.yaml` (enabled at cutover, 8.1.2).
- [x] **[YOU]** D5 plan: final local rehearsal and dataset (`backups/rehearsal/`, another worker), deploy to `next.comtso.fr` with that local data, owner checks, then switch `comtso.fr`.
- [x] **[YOU]** D6 every project owns its CSP: `contentSecurityPolicy` removed from the global `security-headers@file` (applied 2026-10-19, see section 11); keres sends its own (diff in section 11, owner commits/deploys); other sidus apps left without CSP (unimportant).
- [x] **[YOU]** D7 dataset from a fresh dump (no `design-tester`); **D8** no embedding; **D9** fail2ban unchanged; **D10** `MAILER_FROM="ComTSo <no-reply@comtso.fr>"`, owner types the Scaleway DSN himself (leave `null://null` until step 6.1); **D14** the old forum keeps serving during the whole rehearsal (the short freeze of 5.3.2 is still needed at the final cutover only).
- [x] **[YOU]** D11 the weekly dumps are written by the NAS script `/home/vincent/nas/home/Backups/comtso/backup.sh` (cron on the NAS side, not on the server); it now also backs up the new stack once it runs (section 11). **D12** import on the dev machine.

---

## 2. Prerequisites

### GitHub

- [x] **[YOU]** 2.1 `master` pushed (origin/master = `02d8848`, includes the deploy commits of the first task).
- [x] **[ME]** 2.2 ghcr package visibility: `ghcr.io/vincentchalnot/comtso` is **public** (anonymous `GET /v2/vincentchalnot/comtso/tags/list` returns `latest`, `sha-9148a6d`). Nothing to do; a PAT is only needed if you make it private later.
- [x] **[ME]** 2.3 CI green for `origin/master` = `02d8848` (run 39150865228: `test` + `image` success); ghcr has `sha-02d8848`. **Pinned: `COMTSO_IMAGE_TAG=sha-02d8848`.** The local commit `87519eb` (10-character password at registration, D2) is NOT in that image: after you push it, bump the tag before the cutover.
- [ ] **[ME]** 2.4 Verify locally before any deploy: `docker compose -f deploy/compose.yaml --env-file deploy/.env.example config -q && docker compose -f deploy/compose.yaml -f deploy/compose.traefik.yaml --env-file deploy/.env.example config -q` (both silent = OK; CI runs both).

### Server (all read-only checks, already done in recon: re-run them right before go-live)

- [x] **[ME]** 2.5 Docker 29.8.2 + Compose v5.6.0 present (supports `secrets: environment:` and `!reset`); `proxy` network exists; ports 80/443 owned by Traefik; ghcr.io reachable; 48 GB free; amd64.
- [ ] **[ME]** 2.6 Right before the rehearsal re-check capacity (read-only): `ssh sidus.fr 'free -h; df -h /; docker stats --no-stream --format "{{.Name}} {{.MemUsage}}" | sort -k2 -h | tail -5'` — expect >= 500 MiB available; otherwise postpone or free memory.
- [x] **[YOU]** 2.7 DNS: nothing to create (wildcard `*.comtso.fr`). Caveat: the wildcard does NOT cover `www.next.comtso.fr` (two levels), hence `compose.www-redirect.yaml` is only used for the apex.
- [x] **[ME]** 2.8 `*.comtso.fr` is a wildcard that already resolves to the server (owner, 2026-10-19): `next.comtso.fr` needs no DNS work.

---

## 3. Secrets and configuration (`ST/.env`)

- [ ] **[YOU]** 3.1 Scaleway TEM console: validate the sender domain (`comtso.fr`): SPF `v=spf1 include:_spf.tem.scaleway.com -all` (already published), **DKIM TXT record** shown in the console (add it at Gandi if missing), MX `blackhole.tem.scaleway.com` (present), DMARC (present, `p=none`; tighten later). Wait for the domain to show **Checked/Validated**.
- [ ] **[YOU]** 3.2 Scaleway IAM: create an API key (project scope, permission to send transactional e-mail). Keep the **project ID** and the **secret key**. Never paste them in chat or commit them; type them into `.env` yourself (3.6).
- [x] **[ME]** 3.3 Create the directory and copy the deploy files — **done 2026-10-19** (`/root/www/comtso-forum`: `compose.yaml`, `compose.traefik.yaml`, `compose.www-redirect.yaml`, `.env.example`, `backup.sh`, `restore.sh`, `README.md`; re-`rsync` them after any change in `deploy/`). Command used:
  ```bash
  ssh sidus.fr 'mkdir -p /root/www/comtso-forum'
  cd /home/vincent/www/comtso/deploy
  rsync -av compose.yaml compose.traefik.yaml .env.example backup.sh restore.sh README.md sidus.fr:/root/www/comtso-forum/
  ssh sidus.fr 'chmod 755 /root/www/comtso-forum/*.sh'
  ```
  Expected: the six files listed, no `.env` (never copied from dev).
- [x] **[ME]** 3.4 Generate the four random secrets **on the server** (values never printed) — **done 2026-10-19**: `.env` mode 600 root, keys listed only, each secret 64 hex chars. Command used:
  ```bash
  ssh sidus.fr 'cd /root/www/comtso-forum && cp .env.example .env && chmod 600 .env &&
    for k in APP_SECRET DATABASE_PASSWORD DATABASE_ROOT_PASSWORD MERCURE_JWT_SECRET; do
      sed -i "s|^$k=.*|$k=$(openssl rand -hex 32)|" .env; done &&
    cut -d= -f1 .env | grep -v "^#" | grep -v "^$"'
  ```
  Expected: only key names printed (`COMTSO_IMAGE_TAG`, `SERVER_NAME`, `APP_SECRET`, ..., `MAILER_DSN`). `openssl rand -hex 32` = 64 hex chars: Mercure JWT >= 32 chars OK, hex only is safe inside the database URL.
- [x] **[ME]** 3.5 `ST/.env` settings for the **rehearsal** — **done 2026-10-19** (`COMTSO_IMAGE_TAG=sha-02d8848`, `SERVER_NAME=next.comtso.fr`, `COMPOSE_FILE=compose.yaml:compose.traefik.yaml`, `CADDY_SITE_ADDRESS=:80`, `TRUSTED_PROXIES=private_ranges`, `CERT_RESOLVER=gandi`, `TRAEFIK_NETWORK=proxy`, `MAILER_FROM="ComTSo <no-reply@comtso.fr>"`, **`MAILER_DSN=null://null`**). Reference block, for the cutover edits:
  ```dotenv
  COMTSO_IMAGE_TAG=sha-<short>              # the green build from 2.3
  SERVER_NAME=next.comtso.fr                # comtso.fr at cutover (step 5.9)
  COMPOSE_FILE=compose.yaml:compose.traefik.yaml
  CADDY_SITE_ADDRESS=:80
  TRUSTED_PROXIES=private_ranges
  CERT_RESOLVER=gandi
  TRAEFIK_NETWORK=proxy
  HSTS_ENABLED=true                         # default; Traefik adds its own HSTS too
  MAILER_DSN=null://null                    # keep until step 6: no real mail during the rehearsal
  #MAILER_FROM="ComTSo <no-reply@comtso.fr>"  # set with the D10 answer
  ```
- [ ] **[YOU]** **[RISKY — WAIT FOR GO]** 3.6 Real mail (only at step 6, not before): set `MAILER_DSN=smtp://<SCW_PROJECT_ID>:<SCW_SECRET_KEY>@smtp.tem.scaleway.com:587` and `MAILER_FROM="ComTSo <no-reply@comtso.fr>"`. Verified against what I know of TEM: host `smtp.tem.scaleway.com`; ports 25 / **587 (STARTTLS)** / 2587, and 465 / 2465 for implicit TLS (then use `smtps://`); user = project ID, password = secret key (a UUID: no URL-special characters). **Re-check these against the current Scaleway TEM documentation before use**: I could not browse it. `symfony/mailer` 8.1 is in `composer.json`; `smtp://` and `smtps://` are built in (no extra package).
- [x] **[ME]** 3.7 On the server, validate the file without printing values: `cd /root/www/comtso-forum && docker compose config -q && echo CONFIG_OK` — **done 2026-10-19: CONFIG_OK**, `docker compose ps -a` empty (stack NOT started).

---

## 4. First deploy (rehearsal stack on `next.comtso.fr`, empty database)

- [ ] **[ME]** **[RISKY — WAIT FOR GO]** 4.1 Pull and start (pulls ~ the prod image + `mariadb:11.4`, already cached on the server by `admin-demo`):
  ```bash
  ssh sidus.fr 'cd /root/www/comtso-forum && docker compose pull && docker compose up -d && sleep 60 && docker compose ps'
  ```
  Expected: `database` `healthy`, `migrate` `Exited (0)`, `app` `Up ... (healthy)`; project `comtso-forum`; **`docker ps` of the old `comtso-*` containers unchanged**.
- [ ] **[ME]** 4.2 Logs and health (read-only): `docker compose logs migrate | tail` ends with `[OK] Successfully migrated to version: DoctrineMigrations\Version2026…`; `curl -s https://next.comtso.fr/health` -> `ok` (the first request may take ~10-30 s while Traefik obtains the certificate through HTTP-01; if it is the default Traefik cert, wait and retry; `docker logs traefik --since 5m | grep -i comtso-forum`).
- [ ] **[ME]** **[RISKY — WAIT FOR GO]** 4.3 Create the first administrator (data change; you choose the pseudo and a strong password, never reused anywhere):
  ```bash
  ssh sidus.fr 'cd /root/www/comtso-forum && docker compose exec app php bin/console app:user:create <pseudo> <password> --admin'
  ```
  Shell history keeps the password: prefer `HISTCONTROL=ignorespace` + a leading space, or change it afterwards on `/gens/<pseudo>/config`. In the rehearsal this account disappears with the data restore of step 5.
- [ ] **[YOU]** 4.4 Open `https://next.comtso.fr/login`: anonymous visitors land there; log in with the new admin; the home page renders.

---

## 5. Data migration: rehearsal first, then the real cutover

Principle: the **same procedure twice**. Rehearsal = latest weekly dump (zero impact on the old forum). Cutover = a final dump taken after the freeze. Photo processing (~1 h per 2.4 k photos, 768 MB PHP memory limit) and the importers run on the **dev machine**; the server only receives two archives and runs `restore.sh`.

### 5.1 Build the dataset on the dev machine (rehearsal data = `2610180300.sql.gz`)

- [ ] **[ME]** 5.1.1 Pull the inputs from the server (read-only on the server side; creates local files under git-ignored `var/`):
  ```bash
  cd /home/vincent/www/comtso && mkdir -p var/legacy-final var/photos/legacy-files
  rsync -av sidus.fr:/root/www/comtso/shared/app/data/dumps/2610180300.sql.gz var/legacy-final/
  rsync -a --info=progress2 sidus.fr:/root/www/comtso/shared/app/data/photos/originals/ var/photos/legacy-files/   # 847 MB, uid ignored
  ```
  (`var/photos` is the bind-mounted directory: the container sees `/app/var/photos/legacy-files`; an arbitrary `var/` subdirectory is NOT visible, see AGENTS.md "Gotchas".) `ls var/photos/legacy-files | wc -l` -> 2395.
- [ ] **[ME]** 5.1.2 Safety copy of the current dev DB, then side database + migrations:
  ```bash
  docker compose exec -T database mariadb-dump -uroot -proot --single-transaction comtso > var/backup-before-final-import.sql
  docker compose exec -T database mariadb -uroot -proot -e 'DROP DATABASE IF EXISTS comtso_legacy; CREATE DATABASE comtso_legacy CHARACTER SET utf8mb4; GRANT SELECT ON comtso_legacy.* TO "comtso"@"%";'
  zcat var/legacy-final/2610180300.sql.gz | docker compose exec -T database mariadb -uroot -proot comtso_legacy
  docker compose exec -T -e XDEBUG_MODE=off php php bin/console doctrine:migrations:migrate -n --no-debug
  ```
- [ ] **[ME]** 5.1.3 Import (wipes the dev content tables, then relinks/processes the photos). Run in `tmux`, note the duration:
  ```bash
  docker compose exec -T -e XDEBUG_MODE=off php php bin/console app:legacy:import --no-debug --force --source=comtso_legacy --photos-source=/app/var/photos/legacy-files --copy-photos
  ```
  Expected: ends with `Legacy data imported.`, a `NNNN photo(s) processed`, a warning listing the ~3 photo rows without file (#302, #400, #401 are known lost).
- [ ] **[ME]** 5.1.4 Re-import the older platforms (**`app:legacy:import --force` wiped the rows `app:legacy:reimport` had added**): start the legacy databases and run the dry run, then the real run exactly as in `docs/legacy-import.md` steps 1-4 (mounts `/home/vincent/www/comtso.old`, `/home/vincent/www/comtso2.old`). Expected: the real run imports ~3 903 comments / 134 topics / 177 messages (see the table there), the next dry run imports 0.
- [ ] **[ME]** 5.1.5 Photos and content (idempotent, `-e XDEBUG_MODE=off --no-debug` or it is 10x slower):
  ```bash
  docker compose exec -T -e XDEBUG_MODE=off php php bin/console app:photos:reprocess --no-debug --dry-run     # expect ~0 to migrate after the import
  docker compose exec -T -e XDEBUG_MODE=off php php bin/console app:photos:reprocess --no-debug
  docker compose exec -T -e XDEBUG_MODE=off php php bin/console app:content:sanitize --no-debug --dry-run
  docker compose exec -T -e XDEBUG_MODE=off php php bin/console app:content:sanitize --no-debug
  ```
- [ ] **[ME]** 5.1.6 Check the result on dev: `https://comtso.local.sidus.fr` login with one imported account (pre-FOSUserBundle hashes upgrade at first login; **only `vincent` has been verified end to end so far: test at least one old-style member account, ask the member for a temporary reset via `app:user:reset-password` on DEV**), counts (users/topics/comments/photos) against the table of `docs/legacy-import.md`, `doctrine:schema:validate`.
- [ ] **[ME]** 5.1.7 Produce the two archives in the format `restore.sh` expects (DB without the dev-only `comtso_legacy` side DB; photo originals only, caches are rebuilt):
  ```bash
  docker compose exec -T database mariadb-dump -uroot -proot --single-transaction --routines --events comtso | gzip > var/legacy-final/db-rehearsal.sql.gz
  docker compose exec -T php tar czf - -C /app/var/photos originals > var/legacy-final/photos-rehearsal.tar.gz
  gzip -t var/legacy-final/db-rehearsal.sql.gz && gzip -t var/legacy-final/photos-rehearsal.tar.gz && ls -lh var/legacy-final/
  ```
  Expected: DB dump a few MB gzipped; photos archive ~ 850 MB (WebP originals). The dump holds password hashes and private messages: treat as a secret, delete after use.

### 5.2 Load the rehearsal data on the server

- [ ] **[ME]** **[RISKY — WAIT FOR GO]** 5.2.1 Upload and restore (overwrites the rehearsal DB; app is stopped during the load; the tar is extracted as `www-data`):
  ```bash
  rsync -av --info=progress2 var/legacy-final/db-rehearsal.sql.gz var/legacy-final/photos-rehearsal.tar.gz sidus.fr:/root/www/comtso-forum/
  ssh sidus.fr 'cd /root/www/comtso-forum && ./restore.sh --yes db-rehearsal.sql.gz photos-rehearsal.tar.gz && docker compose ps'
  ```
  Expected: `Restore done.`, `app` healthy again, `docker compose exec -T app ls /app/var/photos/originals | wc -l` ~ 2390.
- [ ] **[ME]** **[RISKY — WAIT FOR GO]** 5.2.2 `app:cutover:prepare` dry run, then real run **once** (rehearsal copy only: sets every member's `previous_login` = now and `chat_last_read_id`; `--delete-user=design-tester` if D7 and the account still exists):
  ```bash
  docker compose exec -T -e XDEBUG_MODE=off app php bin/console app:cutover:prepare --no-debug --dry-run
  docker compose exec -T -e XDEBUG_MODE=off app php bin/console app:cutover:prepare --no-debug [--delete-user=design-tester]
  ```
  Expected: report listing ROLE_ADMIN, disabled and mail-less accounts.
- [ ] **[ME]** **[RISKY — WAIT FOR GO]** 5.2.3 Warm the thumbnails (optional, thumbnails are otherwise lazy). RAM is tight (768 MB memory limit per PHP process vs ~740 MiB available): run alone, off-peak, in `tmux`: `docker compose exec -T -e XDEBUG_MODE=off app php bin/console app:photos:warm --no-debug --variants=avatar,thumb,medium,hero`; watch `free -h` from another ssh session and stop (Ctrl-C, it is resumable) if the host starts swapping heavily. Do NOT run `app:photos:reprocess` here: it was done on the dev machine.
- [ ] **[YOU]** 5.2.4 Walk through section 7 on `https://next.comtso.fr` with the rehearsal data (log in with your real account), and note timings: dump size, upload time, restore time, total import duration (5.1.3-5.1.7). This is the length of the freeze.

### 5.3 Real cutover (after the rehearsal is accepted and the owner says GO)

- [ ] **[YOU]** 5.3.1 Announce the downtime on the old forum (you post it, the assistant never does) and pick the slot (D14).
- [ ] **[ME]** **[RISKY — WAIT FOR GO]** 5.3.2 **Freeze the old forum** (stop only the web container; php-fpm and mysql keep running, nothing is deleted, rollback = `start nginx`):
  ```bash
  ssh sidus.fr 'cd /root/www/comtso && docker compose stop nginx && docker compose ps'
  ```
  From now on Traefik answers `404` for `comtso.fr` (fail2ban `traefik-404` can ban a member who reloads 20 times in a minute: tell people not to, or see D9 / `fail2ban-client set traefik-404 unbanip <ip>`).
- [ ] **[ME]** **[RISKY — WAIT FOR GO]** 5.3.3 Final dump of the old DB (read-only on the DB, writes a file on the server; the password is expanded inside the container, never printed):
  ```bash
  ssh sidus.fr 'cd /root/www/comtso && docker compose exec -T mysql sh -c "mysqldump --single-transaction --routines -ucomtso -p\"\$MYSQL_PASSWORD\" comtso" | gzip > /root/comtso-final-$(date +%Y%m%d-%H%M).sql.gz && gzip -t /root/comtso-final-*.sql.gz && ls -l /root/comtso-final-*.sql.gz'
  ```
  Expected: a ~4 MB file. Keep it (it is also your absolute rollback). Photo originals are static files: re-sync `rsync -a sidus.fr:/root/www/comtso/shared/app/data/photos/originals/ var/photos/legacy-files/` (only the delta travels).
- [ ] **[ME]** 5.3.4 Repeat 5.1.2 to 5.1.7 on the dev machine with the final dump (name the files `db-final.sql.gz`, `photos-final.tar.gz`; use `--force` for the import again and re-run `app:legacy:reimport`). Compare counts with the rehearsal (+ whatever was posted since).
- [ ] **[ME]** **[RISKY — WAIT FOR GO]** 5.3.5 Wipe the rehearsal photos (otherwise stale originals and cache stay in the volume), upload and restore the final data:
  ```bash
  rsync -av --info=progress2 var/legacy-final/db-final.sql.gz var/legacy-final/photos-final.tar.gz sidus.fr:/root/www/comtso-forum/
  ssh sidus.fr 'cd /root/www/comtso-forum && docker compose exec -T app sh -c "rm -rf /app/var/photos/originals /app/var/photos/cache" && ./restore.sh --yes db-final.sql.gz photos-final.tar.gz && docker compose ps'
  ```
- [ ] **[ME]** **[RISKY — WAIT FOR GO]** 5.3.6 `app:cutover:prepare` **once** on the final data (dry run first, as in 5.2.2, with `--delete-user=design-tester` if D7). Never run it twice (it resets everyone's "what's new" pointers).
- [ ] **[ME]** **[RISKY — WAIT FOR GO]** 5.3.7 Admin accounts: `docker compose exec app php bin/console app:user:promote <pseudo>` for every administrator that the listing of 5.3.6 does not show; reset-password for anyone who must get in before mail works.
- [ ] **[ME]** **[RISKY — WAIT FOR GO]** 5.3.8 Warm (optional) as in 5.2.3, or leave lazy.
- [ ] **[ME]** **[RISKY — WAIT FOR GO]** 5.3.9 **Switch traffic** (section 8): set `SERVER_NAME=comtso.fr` in `ST/.env`, `docker compose up -d`, check, smoke test.

---

## 6. Mail test (after the real `MAILER_DSN`, step 3.6)

- [ ] **[YOU]** **[RISKY — WAIT FOR GO]** 6.1 Put the real DSN/sender in `ST/.env` (3.6) and apply: `cd /root/www/comtso-forum && docker compose up -d` (the `app` container is recreated; `migrate` is a no-op). Do it on the rehearsal host first.
- [ ] **[YOU]** **[RISKY — WAIT FOR GO]** 6.2 **Sends a real mail**: on `/login` -> "Mot de passe oublié ?" with **your own** address (the rehearsal data holds real members' addresses: never test with someone else's). The page answers neutrally whatever happens (no enumeration); the mail is sent after the response. Expected within a minute: a mail from the `MAILER_FROM` sender with a 1 h link; the link opens the reset form on `https://H/...`; set a new password; old sessions die.
- [ ] **[ME]** 6.3 If nothing arrives (read-only): `docker compose logs --tail=100 app | grep -iE "mailer|smtp|transport"`; Scaleway console > TEM > Logs; spam folder. Typical causes: sender domain not validated, wrong secret, port blocked (outbound 587 verified open from the server).
- [ ] **[YOU]** 6.4 SPF/DKIM/DMARC: open the received mail > "show original": `spf=pass`, `dkim=pass`, `dmarc=pass`; or send once to a mail-tester.com address (that is a second real mail: only with the go).
- [ ] **[YOU]** 6.5 Throttling sanity: the 4th request for the same account within an hour is silently ignored (3/h per account, 10/h per IP), the page still answers neutrally.

---

## 7. Smoke test list (run on `next.comtso.fr` with rehearsal data, again on `comtso.fr` after the switch)

Replace `H`. `curl` checks are `[ME]` (read-only); browser checks are `[YOU]` (or `[ME]` through Playwright MCP on a non-production host, which needs a login: ask).

- [ ] **[ME]** 7.1 `curl -s https://H/health` -> `ok`; `curl -sI https://H/health | grep -i cache-control` -> contains `no-store`.
- [ ] **[ME]** 7.2 `curl -s https://H/robots.txt` -> `User-agent: *` / `Disallow: /` (no login needed).
- [ ] **[ME]** 7.3 `curl -sI https://H/` -> `302` to `https://H/login` (private forum), `Set-Cookie: PHPSESSID=...; secure; httponly; samesite=lax`.
- [ ] **[ME]** 7.4 `curl -sI https://H/photos/1/thumb` and `https://H/forums/` -> `302` to `/login` (no photo or content for anonymous visitors); `curl -s -o /dev/null -w '%{http_code}\n' 'https://H/.well-known/mercure?topic=chat'` -> an error status (401/403 expected), **not** 200 (no anonymous subscriber).
- [ ] **[ME]** 7.5 Security headers on `https://H/login`: `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin`, `X-Frame-Options: SAMEORIGIN`, `Permissions-Policy`, `X-Robots-Tag: noindex...`, `Strict-Transport-Security`, and `Content-Security-Policy` (see 7.9): `curl -sI https://H/login | grep -iE '^(content-security|x-|referrer|permissions|strict)'`.
- [ ] **[YOU]** 7.6 Login with an imported (old-hash) member and with the admin: OK. Throttling: submit six wrong passwords for a **non-existent pseudo** (e.g. `throttle-test`; the limiter counts per typed username + IP, so no real member is affected) -> from the 6th attempt the French "too many attempts" message appears, for 15 minutes. Never run this with a real pseudo.
- [ ] **[YOU]** 7.7 Browse: home (what's new, hero), a forum, a topic with several pages and an old (2004-2007) topic, `/photos`, `/gens/<pseudo>`, search, private messages page, the legacy link `/photos/preview/N.jpg` (301 to the medium variant).
- [ ] **[YOU]** **[RISKY — WAIT FOR GO]** 7.8 Writes (on the rehearsal host only; on `comtso.fr` they are real posts): reply to a topic (422 keeps the typed text on a refused reply), upload a **10 MB** photo to a message (WebP result, EXIF shown to its owner), like a photo then check it can become the home hero, theme toggle persists (light/dark), change-password form (min 10).
- [ ] **[YOU]** 7.9 **CSP in the browser** (never tested behind a proxy): DevTools console on home, topic with editor, chat, photo lightbox: **no CSP violation**. `curl` shows which CSP is actually delivered: the full policy (`default-src 'self'; script-src 'self' 'sha256-...'`) or only `frame-ancestors 'self' https://*.sidus.fr` (= Traefik overrides it, M3/D6).
- [ ] **[YOU]** 7.10 Chat realtime through the proxy: two browsers/accounts on `/chat`: a message appears on the other side **without reload**, the nav badge and `document.title` count unread; DevTools > Network: `/.well-known/mercure` is an open `text/event-stream` (HTTP 200, stays open, no buffering; `private` updates only reach logged-in members). If messages only appear on reload, Traefik/another layer is buffering or the Mercure cookie is not sent: check `MERCURE_PUBLIC_URL` in `docker compose config | grep MERCURE`.
- [ ] **[ME]** 7.11 Container logs after the session (read-only): `docker compose logs --since 30m app | grep -iE "error|critical|emergency"` -> nothing unexpected; `docker compose ps` all healthy; `free -h` still has headroom.
- [ ] **[ME]** 7.12 fail2ban side effect (read-only): `ssh sidus.fr 'fail2ban-client status traefik-404 | grep -E "Currently banned|Banned IP"'` -> no member IP banned because of missing thumbnails.

---

## 8. Traffic switch and rollback

### 8.1 Switch (no DNS change: `comtso.fr` already resolves to the server)

- [ ] **[ME]** **[RISKY — WAIT FOR GO]** 8.1.1 Precondition: old nginx stopped (5.3.2), final data restored (5.3.5), `cutover:prepare` done (5.3.6), 7.1-7.5 green on `next.comtso.fr`.
- [ ] **[ME]** **[RISKY — WAIT FOR GO]** 8.1.2 Point the stack at the real name and recreate `app` (Traefik reads the new `Host(\`comtso.fr\`)` label; the `comtso.fr` certificate already exists in `gandi.json`). Also enable the permanent `www.comtso.fr -> comtso.fr` redirect by adding `compose.www-redirect.yaml` to `COMPOSE_FILE` (`compose.yaml:compose.traefik.yaml:compose.www-redirect.yaml`; only valid for the apex `SERVER_NAME`; Traefik requests a certificate for `www.comtso.fr` by HTTP-01, DNS already points here). Test afterwards: `curl -sI 'https://www.comtso.fr/login?x=1' | grep -iE "^(HTTP|location)"` -> `301` + `location: https://comtso.fr/login?x=1`:
  ```bash
  ssh sidus.fr 'cd /root/www/comtso-forum && sed -i "s|^SERVER_NAME=.*|SERVER_NAME=comtso.fr|; s|^COMPOSE_FILE=.*|COMPOSE_FILE=compose.yaml:compose.traefik.yaml:compose.www-redirect.yaml|" .env && docker compose up -d && sleep 30 && docker compose ps && curl -s https://comtso.fr/health'
  ```
  Expected: `app` recreated and `healthy`, `curl` prints `ok`. `curl -sI https://comtso.fr/login | head -1` -> `HTTP/2 200`. Redirects (`/login`) and the chat now use `https://comtso.fr` (`DEFAULT_URI`, `MERCURE_PUBLIC_URL` are derived from `SERVER_NAME`).
- [ ] **[YOU]** 8.1.3 Run section 7 on `comtso.fr`, announce the reopening yourself, tell members to log in again (all sessions are new) and that the "Mot de passe oublié ?" link works.
- [ ] **[YOU]** **[RISKY — WAIT FOR GO]** 8.1.4 Optional: remove `next.comtso.fr` (DNS record and, in `.env`, nothing else) once nobody uses it.

### 8.2 Rollback to the old forum (target: under 5 minutes)

The old stack is **never deleted or modified** before the whole go-live is accepted: its containers `php-fpm` and `mysql` keep running, only `nginx` is stopped.

- [ ] **[ME]** **[RISKY — WAIT FOR GO]** 8.2.1 Back to the old forum (frees the host name, then restarts the old web container; its labels still claim `comtso.fr`):
  ```bash
  ssh sidus.fr 'cd /root/www/comtso-forum && docker compose stop app && cd /root/www/comtso && docker compose start nginx && sleep 5 && curl -sI https://comtso.fr | head -3'
  ```
  Expected: `HTTP/2 302` to `/login`, header `x-powered-by: PHP/5.6.40`. Anything posted on the new forum since the switch is NOT in the old database (export it by hand if needed: the new DB is untouched in `comtso-forum_database_data`).
- [ ] **[ME]** **[RISKY — WAIT FOR GO]** 8.2.2 Back to a known new-forum state without touching the old one: `./restore.sh --yes db-final.sql.gz photos-final.tar.gz` (reloads the DB; photos extract over the volume) or `backups/db-<stamp>.sql.gz` from `backup.sh`; or run an older image: `COMTSO_IMAGE_TAG=sha-<previous>` + `docker compose pull && docker compose up -d` (restore the DB backup first if the bad release added a migration, see `deploy/README.md` "Rollback").
- [ ] **[YOU]** 8.2.3 Worst case (old MySQL volume damaged): `/root/comtso-final-*.sql.gz` (5.3.3) or the weekly `…0300.sql.gz` loads into a new `mariadb:10.3`/`11.4`; the old image is also exported in `/root/www/comtso/comtso-php-fpm.tar`. Keep all of it until step 9.9.

---

## 9. Post go-live

- [ ] **[ME]** **[RISKY — WAIT FOR GO]** 9.1 Backup cron on the server (writes a cron entry; runs `backup.sh` daily; each run ~4 MB DB + ~850 MB photos, so `BACKUP_KEEP_DAYS=7` = ~6 GB of the 48 GB free):
  ```cron
  30 3 * * * cd /root/www/comtso-forum && BACKUP_DIR=/var/backups/comtso BACKUP_KEEP_DAYS=7 ./backup.sh >/dev/null
  ```
  (`ssh sidus.fr`, `crontab -e` as root; cron mails the output when it fails if a local MTA exists, otherwise check `/var/log/syslog` or add a `|| echo failed | logger -t comtso-backup`).
- [ ] **[ME]** **[RISKY — WAIT FOR GO]** 9.2 `backup.sh` dry run: `cd /root/www/comtso-forum && BACKUP_DIR=/var/backups/comtso ./backup.sh && ls -lh /var/backups/comtso` -> `Backup written: .../db-<stamp>.sql.gz .../photos-<stamp>.tar.gz`, both `gzip -t` clean. (There is no `--dry-run` flag: this really writes the two files.)
- [ ] **[ME]** **[RISKY — WAIT FOR GO]** 9.3 **Restore test on a scratch DB** (never on the live one): load the fresh dump into a throwaway database and compare counts:
  ```bash
  cd /root/www/comtso-forum && R='mariadb -uroot -p"$(cat /run/secrets/database_root_password)"'
  docker compose exec -T database sh -c "$R -e 'CREATE DATABASE comtso_restore_test CHARACTER SET utf8mb4'"
  gunzip -c /var/backups/comtso/db-<stamp>.sql.gz | docker compose exec -T database sh -c "$R comtso_restore_test"
  docker compose exec -T database sh -c "$R -N -e 'SELECT (SELECT COUNT(*) FROM comtso.ctso_comment), (SELECT COUNT(*) FROM comtso_restore_test.ctso_comment)'"
  docker compose exec -T database sh -c "$R -e 'DROP DATABASE comtso_restore_test'"
  ```
  Expected: two identical numbers. Also `tar tzf /var/backups/comtso/photos-<stamp>.tar.gz | head`.
- [ ] **[YOU]** 9.4 Off-site copy of `/var/backups/comtso` (D11) and of `ST/.env` (password manager), e.g. a nightly `rsync` or Scaleway Object Storage; the dump contains password hashes and private messages.
- [ ] **[YOU]** 9.5 Uptime monitoring on `https://comtso.fr/health` (expects `200` and body `ok`; `503` = database down): UptimeRobot/Scaleway Cockpit/other. Optional Sentry: not wired in this stack (not required).
- [ ] **[ME]** **[RISKY — WAIT FOR GO]** 9.6 Test accounts that survived (`design-tester`, throwaway pseudos): `app:cutover:prepare` must NOT be run a second time (5.3.6 was "the once": it resets everybody's pointers). Tell me the pseudos and I will remove them with a one-off call of `App\Forum\UserRemover` (there is no dedicated CLI), after you confirm what they wrote may disappear. Check the list in the browser (`/gens`) or with the dry run of 5.3.6.
- [ ] **[YOU]** 9.7 Rotate every secret that was shared in any chat, ticket or terminal log (Scaleway API key, any password typed on a command line, the admin password) and the ghcr token if you ever created one. After changing `ST/.env` run `docker compose up -d` (changing `APP_SECRET` logs everybody out; database passwords need `ALTER USER` first, see `deploy/README.md`).
- [ ] **[YOU]** 9.8 Tighten DMARC from `p=none` to `p=quarantine` once SPF/DKIM pass in real mails (Gandi DNS); consider an HSTS preload decision (Traefik already sends `preload`).
- [ ] **[YOU]** **[RISKY — WAIT FOR GO]** 9.9 After a stable week: decommission the old stack **in this order, with a fresh dump kept off-server**: `cd /root/www/comtso && docker compose stop` (not `down -v`), then much later remove the folder. Never run the old `make deploy` again (it rebuilds the stack under project `comtso`).
- [ ] **[YOU]** 9.10 Remove the one-shot tooling when the import is accepted (`docs/legacy-import.md` "Removing the temporary tooling") and, in `ST`, delete `db-*.sql.gz`, `photos-*.tar.gz` uploads (they hold hashes and private messages).
- [ ] **[ME]** 9.11 Upgrade routine afterwards: `./backup.sh`, set `COMTSO_IMAGE_TAG` to the new green `sha-<short>`, `docker compose pull && docker compose up -d`, `docker compose logs --tail=50 migrate app`, `curl -s https://comtso.fr/health`.

---

## 10. Known untested items (be aware, verify during the rehearsal)

* **A real GitHub Actions run of the current `ci.yml`**: the image `sha-9148a6d` exists, so the `image` job did run once, but the new `compose.traefik.yaml` validation step added to `test` has not run on GitHub yet.
* **Traefik labels / Let's Encrypt for the new router** (`compose.traefik.yaml` validated with `docker compose config` only, never against this Traefik); HTTP-01 for `next.comtso.fr` depends on the DNS record 2.7; Traefik dropping an `unhealthy` container is expected behaviour, not observed.
* **Production CSP in a browser behind the proxy**: tested only with `curl` and headers locally; the Traefik override (M3) means the policy that browsers actually enforce on this server is not the one tested in `CaddyCspTest`.
* **Real SMTP**: no mail was ever sent through Scaleway TEM from this app (only `null://` and the dev mailer); TEM host/port/credentials format to be confirmed against the current TEM documentation; DKIM selector not verified.
* **Mercure through Traefik** (SSE, HTTP/2 + HTTP/3 advertised): only exercised locally against Caddy directly.
* **`PhotoImporter` / `ImportLegacyCommand` have no unit tests** (their idempotence was verified by hand on the dev data; `tests/Legacy` covers converters and the reimport matcher). The **old-style password hashes** (pre-FOSUserBundle double-sha1) are covered by tests but only the `vincent` account was verified end to end; the final import must be spot-checked with other members.
* **First full run of the procedure on the server**: timings of restore/warm under 740 MiB of free RAM; swap use during `app:photos:warm`.
* The unknown writer of the weekly old dumps (D11): the freeze/final dump of 5.3.3 does not depend on it.

---

## 11. Done on 2026-10-19: CSP per project, NAS backup, server prepared

### 11.1 Traefik: global CSP removed (APPLIED on the server)

`/root/www/traefik/traefik.yaml` (backup `traefik.yaml.bak-2026-10-19`, same directory): the single line
`contentSecurityPolicy: "frame-ancestors 'self' https://*.sidus.fr"` was removed from `http.middlewares.security-headers.headers`; HSTS, `X-Frame-Options`, nosniff, `sslRedirect`, `browserXssFilter` stay. The file was edited **in place** (same inode: it is a single-file bind mount, `sed -i` would have detached it) and Traefik hot-reloaded it (no restart). Rollback: `cp -p traefik.yaml.bak-2026-10-19 traefik.yaml` is NOT enough (new inode): use `cat traefik.yaml.bak-2026-10-19 > traefik.yaml`.

Before/after `curl -sI https://<host>/` (status unchanged everywhere, the CSP header vanished, HSTS and `X-Frame-Options` still present):

| Host | before | after | `Content-Security-Policy` after | HSTS / XFO after |
|---|---|---|---|---|
| app.playkeres.com | 200 | 200 | none (**keres must ship its own: 11.2**) | yes / yes |
| admin-demo.sidus.fr | 200 | 200 | none | yes / yes |
| meuj.sidus.fr | 302 | 302 | none | yes / yes |
| sidus.fr | 200 | 200 | none | yes / yes |
| comtso.fr (old forum) | 302 | 302 | none | yes / yes |
| baby-names.sidus.fr | 302 | 302 | none | yes / yes |

The old `frame-ancestors 'self' https://*.sidus.fr` is gone for sidus/meuj/baby-names/admin-demo/old comtso (they keep `X-Frame-Options: SAMEORIGIN`; the owner said they are unimportant, no edit made to their compose files). The new forum sends `frame-ancestors 'self'` itself.

### 11.2 Keres: own CSP (PREPARED in `/home/vincent/www/keres-platform/deploy/compose.yaml`, NOT committed, NOT deployed)

Keres sets no CSP of its own (its `frankenphp/Caddyfile` only adds a Permissions-Policy), so until this is deployed `app.playkeres.com` has none. Inspection: 2 inline `<script>` blocks (`templates/actions/new_local_game.html.twig`, `_choice_cards.html.twig`), ~58 inline `style=` attributes, Mercure through `EventSource` on the same origin, no web workers, WebAssembly, iframes or third-party scripts/fonts; hence `'unsafe-inline'` for script/style. Diff, labels of `php` (apply on the server by `docker compose up -d` after committing, Traefik picks the middleware up from the labels):

```diff
       traefik.http.routers.keres-https.service: "keres-https-svc"
+
+      # Content-Security-Policy of this app (the shared Traefik no longer sets one globally) ...
+      traefik.http.routers.keres-https.middlewares: "keres-csp"
+      traefik.http.middlewares.keres-csp.headers.contentsecuritypolicy: "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https:; font-src 'self' data:; connect-src 'self'; frame-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'self' https://*.sidus.fr"
```

Validated with `docker compose config` (temporary env, removed). After deploying, check with the browser console on the lobby, a game and settings (CSP violations), and that live updates still arrive (Mercure). If something breaks, relax only the directive named in the console message. A stricter policy (nonces for the two inline scripts) is a later step. `OIDC` login redirects are top-level navigations and are not restricted (no `form-action`).

### 11.3 NAS backup script (ADAPTED)

`/home/vincent/nas/home/Backups/comtso/backup.sh` (original kept as `backup.sh.orig-2026-10-19` next to it) is the job that writes the weekly `…0300.sql.gz` of the OLD forum (ssh to `root@100.93.187.57`, `mysqldump` through the `mysql` container, then `rsync` of `shared/app/data` into `data/`). The old part is unchanged. New: when `/root/www/comtso-forum/.env` exists and the `app` service of the new stack is running, it runs `deploy/backup.sh` on the server (`BACKUP_DIR=/var/backups/comtso`, 7 days kept on the server) and mirrors `/var/backups/comtso/` into `new-forum/` next to the script (no `--delete`; prune by hand). Exit status is non-zero if either part failed. Until the stack is started the new part is silently skipped, so nothing changes today. No secret appears in the script (the DB root password is read inside the container by `backup.sh`). Note: this makes step 9.1 (server-side cron) optional; keep one of the two, not both, or the 850 MB photo tar is made twice a day.

### 11.4 Server: `/root/www/comtso-forum` prepared (stack NOT started, no data)

Files copied, `.env` generated (mode 600, four 64-hex secrets generated on the server and never displayed), rehearsal settings in place (3.3-3.5), `docker compose config -q` OK, `docker compose ps -a` empty. **Waiting for the dataset** (`backups/rehearsal/`, 5.1.7), then 4.1 (start), 5.2 (restore). `MAILER_DSN` is still `null://null`: **set the Scaleway DSN yourself in `/root/www/comtso-forum/.env` at step 6.1 (after the rehearsal data is loaded and checked), then `docker compose up -d`.**

### 11.5 Rehearsal deployed on `https://next.comtso.fr` (2026-10-19)

Pre-check: no difference in `migrations/`, `src/Entity` or `config/packages/doctrine.yaml` between `02d8848` (pinned image) and local `HEAD` (only `87519eb`, a form constraint): the image has every migration the dump needs. Dataset `backups/rehearsal/` (`db-20261019-080838.sql.gz` 4.2 MB, `photos-20261019-080838.tar.gz` 331 MB; SHA256 verified locally and on the server).

- [x] 4.1 `docker compose pull && up -d`: `migrate` exited 0, `database` + `app` healthy (project `comtso-forum`, old `comtso-*` containers untouched, `https://comtso.fr` still 302 from the old forum).
- [x] 4.2 `https://next.comtso.fr/health` -> `ok`; Let's Encrypt certificate (issuer YR2, CN `next.comtso.fr`, valid to 2027-01-17) issued by Traefik through the `gandi` resolver.
- [x] 5.2.1 `./restore.sh --yes db-... photos-...` -> `Restore done.`, `app` healthy again; 2425 files in `originals`, volume 336 MB. Counts: 18 users (8 enabled), 501 topics, 25 981 comments, 2 425 photos.
- [x] 7.1-7.5 (curl): `/health` ok, `/login` 200, `/robots.txt` 200, `/` 302; headers on `/login`: the app's own CSP (`default-src 'self'; script-src 'self' 'sha256-...'`) reaches the client, plus HSTS, nosniff, `Referrer-Policy: same-origin`, `X-Frame-Options: SAMEORIGIN`, Permissions-Policy, X-Robots-Tag; anonymous `/photos/1/thumb` -> 302.
- Resources after restore: `app` 157 MiB, `database` 114 MiB, host ~780 MiB available (swap untouched by the restore).
- **No account has `ROLE_ADMIN` in this dataset** (all `roles` are `[]`, including `Vincent`, id 5, last login today). Nothing was promoted or created. Owner logs in with his legacy pseudo/password (hash upgraded at first login); to get moderation rights: `docker compose exec app php bin/console app:user:promote Vincent`. 5.2.2 (`app:cutover:prepare`, once) and the browser checks 4.4, 5.2.4, 7.6-7.10 are still open.
