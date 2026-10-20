# Operations: production server facts and runbooks

`deploy/README.md` is the authoritative runbook (first deploy, configuration and secrets, upgrade, image rollback, backup and restore scripts, mail,
logs). This file only holds what is specific to the production server and not covered there. Decisions and history: `docs/decisions.md`.

## Server layout

| Topic | Fact |
| --- | --- |
| Host | `sidus.fr`: Ubuntu 24.04, x86_64 (same architecture as the CI image), 2 vCPU, 1.8 GiB RAM with swap. The only account is `root`. Several unrelated Compose projects run on it behind one shared Traefik. Keep an eye on `free -h` and `docker stats --no-stream`: RAM is tight, so photo reprocessing never runs here (dev machine only). |
| New forum | `/root/www/comtso-forum` (`ST` below): `compose.yaml`, `compose.traefik.yaml`, `compose.www-redirect.yaml`, `.env` (mode 600, never copied from dev), `backup.sh`, `restore.sh`, `README.md`. Compose project `comtso-forum`. After any change in `deploy/`, copy the files again: `rsync -av compose.yaml compose.traefik.yaml compose.www-redirect.yaml .env.example backup.sh restore.sh README.md sidus.fr:/root/www/comtso-forum/`. |
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

## Rollback

The previous Symfony stack was retired on 2026-10-20 and there is no rollback to it any more (containers, image, MariaDB volume and `/root/www/comtso` are gone). Its last dump (`data/dumps/2610192354.sql.gz` and older), its original photos and `current.sql` (`old-forum-archive/`) are archived on the NAS under `Backups/comtso/`; the NAS copies are the only ones.
What remains: a bad release of the app = image tag rollback (`deploy/README.md` "Rollback"); a damaged database = restore a `db-*.sql.gz` from `/var/backups/comtso` or the NAS `new-forum/` with `restore.sh` (`deploy/README.md` "Backup and restore").
Traefik global file: it is a single-file bind mount, so restore it IN PLACE with `cat /root/www/traefik/traefik.yaml.bak-precutover-2026-10-19 > /root/www/traefik/traefik.yaml` (never `cp` or `sed -i`, which create a new inode and detach the mount; Traefik hot-reloads). The earlier backup `traefik.yaml.bak-2026-10-19` is the state before the global CSP was removed.
Retirement record (2026-10-20): `docker stop/rm` of `comtso-{nginx,php-fpm,mysql}-1`, `docker network rm comtso_default`, `docker volume rm comtso_volume_mysql`, `docker image rm comtso-php-fpm:latest mariadb:10.3` (`nginx:alpine` is shared with another app and stays), `rm -rf /root/www/comtso`, about 3 GB of disk freed, live forum unaffected. The project name `comtso` is free again, but `comtso-forum` stays.

## Backups and restore checks

- **Mechanism** (NAS script `Backups/comtso/backup.sh`, runs as the NAS user, ssh as `root` to the server over Tailscale; copies kept next to it: `backup.sh.orig-2026-10-19` = original, `backup.sh.bak-2026-10-19` = before the rsync change). The old-forum part (dump over ssh + `rsync` of `shared/app/data`) was disabled on 2026-10-20 when the old stack was retired (`backup.sh.bak-2026-10-20` = before; the archives already on the NAS, `data/` and `old-forum-archive/`, are untouched). New forum part, only when `ST/.env` exists and the `app` service is running: (1) `ssh` runs `ST/backup.sh` with `BACKUP_DIR=/var/backups/comtso`, `BACKUP_KEEP_DAYS=7` (server-side `db-*.sql.gz` + `photos-*.tar.gz`, 7 days); (2) `rsync -aqz --no-owner --no-group --include 'db-*.sql.gz' --exclude '*'` pulls ONLY the dated dumps into `new-forum/` (4 MB each, kept forever on the NAS, no retention there: prune by hand), the photo tarballs are deliberately NOT pulled; (3) `rsync -aq --no-owner --no-group --exclude 'cache/'` mirrors the Docker volume `/var/lib/docker/volumes/comtso-forum_photos/_data/` (readable by `root` over ssh, files owned by `www-data`) into `new-forum/photos/` (so `new-forum/photos/originals/`): only new or changed files travel, there is NO `--delete` anywhere, a photo removed on the server stays on the NAS; the regenerable `cache/` is excluded; rsync status 24 (file vanished during the run) counts as success. Exit status is non-zero if any part failed. The NAS shows Synology `@eaDir` folders in the mirror: ignore them (they are not on the server). The first ssh needs the server's host key in the NAS user's `known_hosts` (ed25519 fingerprint `SHA256:3q97BNp8…`, checked on 2026-10-19 against `/etc/ssh/ssh_host_ed25519_key.pub`). A server-side cron of `backup.sh` would be redundant for the dump (the NAS job already triggers it).
- **Redundant server tarball**: with the rsync mirror the server's own `photos-*.tar.gz` (350 MB per run, 7 days = about 2.4 GB on the server disk) is only a convenient input for `restore.sh`; it is not needed for the NAS copy. `backup.sh` was left unchanged; drop the tarball (or its retention) only if the disk gets tight, and then restore photos from `new-forum/photos/originals/` by `rsync` into the volume as `www-data`.
- **Manual run**: `cd ST && BACKUP_DIR=/var/backups/comtso ./backup.sh && ls -lh /var/backups/comtso` writes `db-<stamp>.sql.gz` + `photos-<stamp>.tar.gz` (there is no dry-run flag); both must pass `gzip -t`. NAS check: `gzip -t new-forum/db-*.sql.gz`, same file list and byte total for `new-forum/photos/originals` and the server's `originals/` (`find . -type f -printf '%P\n' | sort`, ignore `@eaDir`), a few `sha256sum` equal, and a second run of the photo `rsync -a --stats` reports `Number of regular files transferred: 0`.
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
- **Checked on 2026-10-19**: (a) a dev-database dump restored cleanly into a scratch database (row counts identical); (b) NAS job run once by hand with the rsync logic: `new-forum/` holds the three dated dumps (all `gzip -t` OK) and `photos/originals/` with 2 425 files and 346 744 802 bytes = exactly the server's `originals/` (identical sorted file lists, 12 random `sha256sum` equal, no `cache/` copied); a second photo `rsync` transferred 0 files; (c) the NAS dump `db-20261019-215412.sql.gz` restored into a scratch database `comtso_restore_check` of the DEV stack (dropped afterwards) with the same counts as the live tables (members 14, comments 25 982, topics 502, photos 2 425, chat messages 356, private messages 558). The two older `photos-*.tar.gz` pulled by the previous script version stay on the NAS (350 MB each): delete them by hand once you are happy with `photos/`.

## MariaDB upgrade (11.4 to 12.3)

Rehearsed on 2026-10-20 (never applied to production by that work): a restore of the production dump `db-20261020-073748.sql.gz` into `mariadb:11.4` (11.4.13, the version of the production volume, created 2026-10-19), clean stop, then `mariadb:12.3` (12.3.3) on the same data directory. Everything below was run end to end on scratch Compose projects with throwaway secrets (including the rollback), then torn down.

**Facts** (checked online on 2026-10-20): 12.3 is the current LTS (GA 2026-05-28, 12.3.3 of 2026-08-24, community support until 2029-06-12); 11.4 is LTS until 2029-05-29, 11.8 (the LTS in between) until 2028-06-04, so the upgrade is about staying on the newest LTS and its fixes, nothing is urgent. A direct 11.4 -> 12.3 jump is supported: MariaDB's policy is that `mariadb-upgrade` and the data files of any older release can be upgraded ("trivially upgrade from ANY earlier MariaDB version to the latest"), and the short-term series 12.0 to 12.2 (already EOL) need not be stepped through. There is no 11.4 -> 12.3 page; the documented hops are 11.4 -> 11.8 and 11.8 -> 12.3, so read both lists of incompatible changes. Sources: <https://mariadb.org/about/> (release table and policy), <https://mariadb.com/docs/release-notes/community-server/12.3/mariadb-12.3-changes-and-improvements>, <https://mariadb.com/docs/server/server-management/install-and-upgrade-mariadb/upgrading/mariadb-community-server-upgrade-paths/upgrading-from-mariadb-11-8-to-mariadb-12-3>, <https://mariadb.com/docs/server/server-management/install-and-upgrade-mariadb/upgrading/mariadb-community-server-upgrade-paths/upgrading-from-mariadb-11-4-to-mariadb-11-8>, <https://mariadb.com/docs/server/server-management/install-and-upgrade-mariadb/upgrading/platform-specific-upgrade-guides/upgrading-on-linux/upgrading-between-major-mariadb-versions>, <https://github.com/docker-library/docs/blob/master/mariadb/README.md> and <https://github.com/MariaDB/mariadb-docker/blob/master/docker-entrypoint.sh> (the `MARIADB_AUTO_UPGRADE` behaviour), the Docker Hub `mariadb` tag list (tags `12.3` and `12.3.3` exist).

**What the official image does with an old volume**: the entrypoint compares `mariadb_upgrade_info` of the data directory (`11.4.13-MariaDB` in production) with the new `major.minor`. It never refuses to start. With `MARIADB_AUTO_UPGRADE` set (already `"1"` in `deploy/compose.yaml`, and now in the dev `compose.yaml`) it starts a temporary server, dumps the `mysql` system schema to `<datadir>/system_mysql_backup_<old version>.sql.zst` (about 0.6 MB; `MARIADB_DISABLE_UPGRADE_BACKUP=1` would skip it, we do not) and runs `mariadb-upgrade --upgrade-system-tables` (user tables are NOT touched, phase 6 is "Skipped"). Without the variable the server starts anyway and only logs "MariaDB upgrade ... required, but skipped". That backup covers the system tables only: **the real rollback is the SQL dump**. Disk: the volume is 199 MB, the server has 47 GB free, no concern.

**Tag**: `mariadb:12.3` (rolling patch tag of the LTS series, same convention as the former `mariadb:11.4`): patch releases only carry security and bug fixes, a `docker compose pull` brings them and `MARIADB_AUTO_UPGRADE` handles the system tables. An exact `12.3.3` would freeze the fixes until someone remembers to bump it; `lts`/`latest` are never used (they would jump to the next series, 13.x, silently). Dependabot ignores MariaDB minor/major bumps on purpose. `serverVersion=mariadb-12.3.0` in `DATABASE_URL` (both Compose files and the Dockerfile placeholder) selects DBAL's `MariaDB120300Platform` (DBAL 4.5.0: threshold 12.3.0; 11.7+ only differ by the reserved-word list), schema diffs are unchanged.

**Results of the rehearsal**: the container started and was `healthy` 12 s later; log lines `Backing up system database to system_mysql_backup_11.4.13-MariaDB.sql.zst`, `Major version upgrade detected from 11.4.13-MariaDB to 12.3.3-MariaDB. Check required!`, `Finished mariadb-upgrade`; `mariadb-check --check-upgrade --databases comtso` all `OK`; counts identical before and after (members 14, comments 25 982, topics 502, photos 2 425, chat messages 356, private messages 558, quotes 348, photo-topic links 2 275, starred topics 14, forums 10) and `CHECKSUM TABLE` equal for the 13 tables; engine, row format, charset and collation (`utf8mb4_uca1400_ai_ci`) and the md5 of the column and index definitions unchanged; a second start logs `MariaDB upgrade not required`. The dump of 11.4 loads into a fresh 12.3 and a fresh 12.3 migrates all migrations; `doctrine:schema:validate` is green on `comtso` and `comtso_test`; the whole PHPUnit suite (873 tests) passes on 12.3.3; the production compose (`migrate` one-shot, `app` health check through `/health`, redirect to `/login`) works on top of the upgraded volume with the published `ghcr.io/vincentchalnot/comtso:latest`, and the latest migration (`Version20261020073627`, `ctso_photo.rotation`) applies on the restored data. Memory: a freshly started 12.3 container showed 111 MiB in `docker stats` against 49 MiB for the long-running 11.4 in production (not comparable, cache warmth): the server has 1.8 GiB, check `free -h` afterwards.

**Behaviour changes to know** (11.4 -> 12.3): `innodb_snapshot_isolation` is ON since 11.8 (11.4 in production: OFF): a REPEATABLE READ transaction that read a row and then updates/deletes a row changed by another transaction since its snapshot fails with `ERROR 1020 Record has changed since last read`. The suite passes, Doctrine flushes only write inside their transaction and `TopicRemover` only deletes; the one read-then-write transaction is `UserRemover::removeComments` (admin deleting a member, narrow window): if a 1020 ever shows in the logs, repeat the action; `command: ["--innodb-snapshot-isolation=OFF"]` on the `database` service restores the old behaviour if needed. New reserved words `CONVERSION` and `TO_DATE` (not used as identifiers here), removed variables `big_tables`, `storage_engine`, `large_page_size` (we set none: no custom `my.cnf`). Not relevant here: replication, Galera.

### Production steps (owner, on `sidus.fr`, in `ST`; about 3 minutes of downtime of the forum)

Do NOT run a plain `docker compose up -d` before step 5: it would recreate the database on 12.3 without a fresh dump.

1. From the repository, copy the new Compose file only (`rsync -av deploy/compose.yaml sidus.fr:/root/www/comtso-forum/compose.yaml`; nothing runs yet), then on the server: `cd /root/www/comtso-forum && docker compose config -q && docker compose config | grep -E 'image: mariadb|serverVersion' | sed 's/%env.*@/@/'` must show `mariadb:12.3` and `mariadb-12.3.0`. Keep the old file for the rollback (`git show <commit before the bump>:deploy/compose.yaml`).
2. `docker compose pull database` (downloads the 12.3 image while the forum keeps running).
3. Full backup and off-site copy: `BACKUP_DIR=/var/backups/comtso ./backup.sh`, then the NAS job (or by hand `scp root@sidus.fr:/var/backups/comtso/db-<stamp>.sql.gz` to `Backups/comtso/new-forum/`), `gzip -t` on both copies.
4. Freeze the writes and take the final dump, which is the rollback point (its name does not start with `db-`, so the 7-day retention of `backup.sh` and the NAS rsync pattern do not touch it: copy it to the NAS by hand and keep it for a few weeks):

   ```bash
   docker compose stop app                       # the forum is down from here
   S=$(date +%Y%m%d-%H%M%S)
   docker compose exec -T database sh -c 'mariadb-dump -uroot -p"$(cat /run/secrets/database_root_password)" --single-transaction --routines --events comtso' | (umask 077; gzip > /var/backups/comtso/preupgrade-11.4-$S.sql.gz)
   gzip -t /var/backups/comtso/preupgrade-11.4-$S.sql.gz && ls -l /var/backups/comtso/preupgrade-11.4-$S.sql.gz
   docker compose exec -T database sh -c 'mariadb -uroot -p"$(cat /run/secrets/database_root_password)" -N -e "SELECT (SELECT COUNT(*) FROM comtso.fos_user),(SELECT COUNT(*) FROM comtso.ctso_comment),(SELECT COUNT(*) FROM comtso.ctso_topic),(SELECT COUNT(*) FROM comtso.ctso_photo),(SELECT COUNT(*) FROM comtso.ctso_chat_message),(SELECT COUNT(*) FROM comtso.ctso_message)"'   # note the six numbers
   ```

5. Clean shutdown of 11.4 (the recovery log must not be replayed by another major version), then start 12.3 on the same volume:

   ```bash
   docker compose stop -t 120 database && docker compose logs --tail=3 database    # must end with "Shutdown complete"
   docker compose up -d database && docker compose logs -f database                 # Ctrl-C when "ready for connections" shows twice
   ```

   Expected: `Entrypoint script for MariaDB Server 1:12.3.x ... started`, `Starting temporary server`, `Backing up system database to system_mysql_backup_11.4.13-MariaDB.sql.zst` (the version printed is the one of the volume, `11.4.x`), `Backing up complete`, `Starting mariadb-upgrade`, `Major version upgrade detected from 11.4.x-MariaDB to 12.3.x-MariaDB. Check required!`, `Phase 1/8 ... Phase 8/8`, `Finished mariadb-upgrade`, `Temporary server stopped`, then `Version: '12.3.x-MariaDB-ubu2404' ... port: 3306`. Any `[ERROR]` or a restart loop: go to the rollback.
6. Check the data: `docker compose ps database` is `healthy`; the same six counts as in step 4; `docker compose exec -T database sh -c 'mariadb-check -uroot -p"$(cat /run/secrets/database_root_password)" --check-upgrade --databases comtso'` answers `OK` for the 13 tables; `SELECT VERSION(), @@collation_server` gives `12.3.x-MariaDB-ubu2404`, `utf8mb4_uca1400_ai_ci`.
7. Start the rest: `docker compose up -d` (`migrate` and `app` are recreated for the new `serverVersion`; `migrate` must exit 0), `docker compose ps` all healthy, `curl -s https://comtso.fr/health` = `ok`, then the smoke test of this file and `docker compose logs --since 30m app migrate | grep -iE 'error|critical|SQLSTATE'` (empty), `free -h`.
8. Done when a day passes without errors; delete the pre-upgrade dump after a few weeks. Update `ST/compose.yaml` on the server only through the repository file (step 1), never by hand.

### Rollback

Restoring the dump into a fresh 11.4 volume is the supported way back (a data directory opened by a newer major is not guaranteed to open on the older one; in the rehearsal `mariadb:11.4` did start on a cleanly stopped 12.3 directory, but do not rely on it). Everything written since step 4 is lost, so decide before the forum is reopened; after that prefer a fix forward. Sequence (run end to end in the rehearsal, with `restore.sh`):

```bash
# 1. put the OLD deploy/compose.yaml back (mariadb:11.4 and serverVersion=mariadb-11.4.0), then:
docker compose config -q && docker compose stop app migrate && docker compose stop -t 120 database
docker run --rm -v comtso-forum_database_data:/from:ro -v comtso-forum_database_data_12.3:/to alpine cp -a /from/. /to/   # keep the 12.3 volume for the post-mortem
docker compose rm -f database && docker volume rm comtso-forum_database_data
docker compose up -d database                                                            # fresh 11.4 volume, empty `comtso` database and the app user
./restore.sh --yes /var/backups/comtso/preupgrade-11.4-<stamp>.sql.gz                    # loads the dump, restarts app (photos are untouched)
curl -s https://comtso.fr/health                                                         # ok; the six counts of step 4 again
```

Delete the `comtso-forum_database_data_12.3` volume once the cause is understood (`docker volume rm`). If instead the data is damaged but 12.3 itself is fine, keep the new compose file and restore the same dump into the fresh 12.3 volume the same way (the 11.4 dump loads into 12.3, tested).

### Dev stack

`compose.yaml` (dev) now runs `mariadb:12.3` with `MARIADB_AUTO_UPGRADE=1`: the first `docker compose up -d database` after pulling the bump upgrades the system tables of the existing `comtso_database_data` volume in place. Dump first if the dev data matters: `docker compose exec -T database sh -c 'mariadb-dump -uroot -p"$(cat /run/secrets/database_root_password)" --single-transaction --routines --events --all-databases' | gzip > ../comtso-dev-before-12.3.sql.gz`. CI starts empty databases, so it needs nothing.

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
- Errors reach Sentry when `SENTRY_DSN` is set: see "Sentry" below.

## Mail troubleshooting

- Compose secrets are files written when the container is created: after fixing `MAILER_DSN`, `docker compose up -d --force-recreate app` is required (a plain `up -d` or `restart` keeps the old DSN; this caused the first failures in production, an AUTH error 535).
- The reset form is silent by design (neutral answer, 3 requests per hour per account, 10 per hour per IP): after three failed attempts the fourth logs nothing and sends nothing. The `audit` log channel records every skip reason and transport failure (`deploy/README.md` "Logs"); look there first: `docker compose logs --tail=100 app | grep -iE "mailer|smtp|transport"`, then the Scaleway console, TEM, Logs, then the spam folder.
- Test with ONE reset request for the owner's own address only (the data holds real members' addresses). Check the received headers: `spf=pass dkim=pass dmarc=pass`.
- **DMARC** (read with `dig` on 2026-10-19, no DNS change made): SPF `v=spf1 include:_spf.tem.scaleway.com -all`, DKIM published at `<Scaleway project id>._domainkey.comtso.fr` (the selector is the TEM project id, the first part of the SMTP user; RSA, `h=sha256`), DMARC `v=DMARC1; p=none` (no `rua`/`ruf`, so nobody receives any report). TEM signs with the customer's own DKIM key, so `d=comtso.fr` is aligned with the `From:` domain (`MAILER_FROM` is `no-reply@comtso.fr`) and DMARC passes on DKIM alone; SPF alignment depends on the envelope sender TEM uses and is not needed. Not verified on a real received message (the headers of a mail were never recorded): check `dmarc=pass` once. `p=none` only asks receivers to report and deliver as usual: for about 20 members on a private forum that sends account mails and digests, it costs nothing and is a fine permanent setting. `p=quarantine`/`reject` would only stop somebody forging `@comtso.fr` towards third parties (nobody gains from that here), and the risk is to lose real mails the day a signature or the TEM project breaks (key rotation, a new sender, an alignment mistake): spam folder or silent rejection of resets, with no report to warn you. Safe path if wanted: add `rua=mailto:<address you read>` to `p=none` (`v=DMARC1; p=none; rua=mailto:...`) at Gandi, read the aggregate reports for 2 to 4 weeks, move to `p=quarantine; pct=25` then 100 only if every legitimate source passes, and keep `reject` for never. Without reports, leave `p=none`.

## Admin panel (`/admin`)

Administrators only (anonymous = `302 /login`, a member = 403); the page list and the rules are in `AGENTS.md` ("Admin panel"). Operations it covers: enable/disable a member and grant/remove `ROLE_ADMIN` (what `app:user:promote` does), delete a member with everything they wrote (`UserRemover`, a confirmation page lists what goes), create/rename/reorder forums and delete EMPTY ones, delete a topic or a single message (moderation), delete a photo with its files, manage the random quotes.
It shows e-mail addresses on the member screens only (list and edit page, read-only), never changes a password (`app:user:reset-password` stays the fallback) and cannot disable, demote or remove its own account or the last active administrator; if no administrator is left (SQL accident): `app:user:promote <pseudo>`. Destructive actions are real on production: try them on a rehearsal host with throwaway members only (create with `app:user:create`, remove from the panel). After a release, `/admin/users/` answering 200 twice in a row proves the worker-mode fix is in the image.

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

## Sentry

Server-side error reporting with `sentry/sentry-symfony` (`config/packages/sentry.yaml`, `App\Sentry\ExpectedErrorFilter`). Hosting: sentry.io (EU region); self-hosted Sentry needs 16 GB+, far beyond this server.

- **Switch**: the environment variable `SENTRY_DSN`. Unset or empty (dev, tests, CI, and a server that does not set it) = the SDK has no DSN, sends nothing and nothing fails. It is a plain variable, not a `*_FILE` secret: a DSN only lets its holder send events to the project, it reads nothing. On the server it lives in `deploy/.env` (`SENTRY_DSN=https://<key>@<host>/<project>`; `deploy/.env.example` has it commented out) and `deploy/compose.yaml` forwards it to `app` and `migrate` (a failing migration is reported too). The dev `compose.yaml` does not forward it on purpose.
- **Enable / change / disable**: edit `SENTRY_DSN` in `deploy/.env`, then `docker compose up -d` (an environment change recreates the containers; no image rebuild). Disable = empty the value or delete the line. Never paste the DSN into a ticket, a chat or a commit.
- **What is reported**: uncaught exceptions (HTTP 500 and any HTTP exception of 500 and above) and failing console commands, with the stack trace, the route name (`route` tag) and `environment` = the kernel environment (`prod`). One event per failure, no performance tracing, profiling, logs or metrics. The `release` is the SDK default (Composer root package version).
- **What is NOT reported** (expected answers, `ExpectedErrorFilter`): any HTTP exception below 500 (404 and the fallback page, 403, 401, 405, 422, 429 rate limits), access denied, failed authentication and invalid CSRF tokens. Login throttling and the `audit` Monolog channel are untouched and stay in the logs only (Monolog is not wired to Sentry: a logged-but-caught error is not an event).
- **Privacy** (private forum): `send_default_pii: false` and the `data_collection` block switch off the member identity and IP address, cookies, request and response headers (so no `Authorization`), request/response bodies (no message text, password or token), the query string (reset-link parameters `u=`/`k=`, search terms, filters), stack-frame variables and SQL data. An event holds the exception message and class, the file/line trace with source lines of the code, the path of the URL, the HTTP method, the route name and the server name (container id). Exception messages come from our code and libraries: keep member data out of them. `tests/Sentry/SentryReportingTest` checks with the real SDK serializer that a request carrying a cookie, a bearer header, a body, a query string and a member session leaves none of it in the event.
- **Browser SDK**: none (no CSP change). If it is ever added it needs `connect-src 'self' https://*.ingest.sentry.io` (or a tunnel route) in the Caddyfile CSP.
- **Verify after a deploy** (never prints the DSN): `docker compose exec app php bin/console sentry:test --no-debug` answers `DSN correctly configured` and `Message sent successfully with ID ...` (`No DSN configured` = the variable did not reach the container: `docker compose exec app printenv SENTRY_DSN | wc -c` is 1 when empty). The "This is a test message" event appears in the Sentry project within a minute; resolve it there. A real exception check belongs on a rehearsal host only (an unhandled 500 is a real error on production).
- **Tests**: the test environment replaces the HTTP transport with `App\Tests\Sentry\RecordingTransport`, so nothing in the suite reaches a network.
