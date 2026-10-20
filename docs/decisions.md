# Decisions and history

What was decided, what was found, and what happened, for whoever works on ComTSo next. Short on purpose: procedures are in
`deploy/README.md` and `docs/operations.md`, the backlog in `TODO.md`.

## Timeline

| Date | Event |
| --- | --- |
| 2026-10-17 | Rewrite on Symfony 8.1 delivered; features of the old forum inventoried in `docs/LEGACY_FEATURES.md`. |
| 2026-10-18 | Read-only recon of the production server, go-live checklist written. |
| 2026-10-19 | Decisions D1-D14 answered; rehearsal on `next.comtso.fr`; **cutover: `comtso.fr` now serves the new app**; git history purged and rewritten (see below). |
| 2026-10-19 | Admin panel at `/admin` (members, forums, topics, quotes, photos) on the owner's sidus bundles with three Symfony 8 fixes applied as Composer patches until they are tagged upstream; "delete empty forums" shipped as part of it; no e-mail address is ever displayed there. |
| 2026-10-19 | Owner decisions: all the data is in the new database and the old data is forgotten, so the legacy import tooling and `docs/legacy-import.md` were removed; D1 (profile visibility option) dropped; the 10 shortened attachments accepted as they are; "Symfony 2" wording removed from the docs. |
| 2026-10-20 | Sentry error reporting (`sentry/sentry-symfony`): the DSN is the optional plain variable `SENTRY_DSN` (the owner already set it on the server), errors only, no personal data, expected 4xx filtered out; unset = disabled, so dev, tests and CI never need it (`docs/operations.md` "Sentry"). |
| 2026-10-20 | Admin panel changes asked by the owner: screens ordered members, forums, topics, comments, photos, quotes with icon tiles on the overview; e-mail addresses are now shown on the member screens only (this reverses the earlier "never an address in the admin" rule); the admin uses the full page width (`.page-wide`); new read-only comments screen with moderation removal (`CommentRemover`). |
| 2026-10-20 | The old Symfony stack was retired from the production server (containers, image, volume, directory): no rollback to it any more, the rollback rehearsal is obsolete. |
| 2026-10-20 | Photo backups: the NAS keeps an rsync mirror of the photo originals (no duplicates, nothing ever deleted there); the server's own `photos-*.tar.gz` is redundant for the NAS. |
| 2026-10-20 | Settings centre at `/gens/{pseudo}/parametres/<section>` (profile, avatar, security, display, statistics) replaces the single config page; statistics left the main navigation; `/gens/{pseudo}/config` is a 301 to it. |
| 2026-10-20 | Any member may add a quote (`/citations/nouvelle`, plain text, two fields); editing and removing stay with administrators. This closes the old "who may add quotes" question. |
| 2026-10-20 | Photo rotation is a stored value (`ctso_photo.rotation`, 0/90/180/270) applied when the thumb/medium/hero/avatar variants are generated, not a re-encode and not CSS: the stored file is never rewritten (no quality loss), and every image already goes through `photo_url()`. The downloaded original is not turned. |

## Go-live decisions (D1-D14, answered by the owner on 2026-10-19)

| Id | Decision |
| --- | --- |
| D1 | Profile visibility option: **dropped** (owner, 2026-10-19). Enabled profiles stay visible to every member; the former accounts that were relevant were re-enabled by the owner. |
| D1b | "Les Gens" showing disabled accounts too: not needed (the relevant former members were re-enabled), dropped from the backlog. |
| D2 | Password minimum length is 10 everywhere (registration, change, reset). |
| D3 | Login throttling: owner decided, no change recorded (current limits: `AGENTS.md` "Authentication hardening"). |
| D4 | Rehearsal host `next.comtso.fr` (wildcard DNS, nothing to create). `www.comtso.fr` redirects permanently to the apex: `deploy/compose.www-redirect.yaml`, apex `SERVER_NAME` only. |
| D5 | Plan: local rehearsal dataset, deploy it to `next.comtso.fr`, owner checks, then switch `comtso.fr`. |
| D6 | **Every project behind the shared Traefik owns its CSP.** The global `Content-Security-Policy` was removed from Traefik's `security-headers@file` middleware (HSTS, `X-Frame-Options`, nosniff stay). The new forum sends its own CSP from Caddy. Keres sends its own through Traefik labels (prepared in its own repository; checking it is in `TODO.md` wave 1); the other sidus.fr apps run without a CSP (owner: unimportant). |
| D7 | Rehearsal dataset built from a fresh dump of the old forum; no test account (`design-tester`) in it. |
| D8 | No other site embeds or calls the forum: the new app sends `frame-ancestors 'self'`. |
| D9 | fail2ban unchanged (jail `traefik-404`: 20 responses 404 in 60 s from one IP = 1 h ban, no `ignoreip`). Side effect to remember: missing thumbnails or a stopped web container can get a member banned. |
| D10 | `MAILER_FROM="ComTSo <no-reply@comtso.fr>"`; outgoing mail through Scaleway Transactional Email; the owner types the DSN himself in the server `.env`. |
| D11 | Backups: the weekly dumps of the OLD forum are written by a script on the NAS (cron on the NAS side, not on the server). That script was extended to back up the new stack too (see "Backups" below). |
| D12 | The photo processing and the data load ran on the dev machine; the server only received a DB dump and a photo archive and ran `restore.sh`. |
| D13 | Open questions of the data import answered by the owner; the import itself is closed (see "Data import findings"). |
| D14 | The old forum kept serving during the whole rehearsal; the short freeze was needed at the final cutover only. |

## Cutover facts that still matter

- **Server** (`sidus.fr`, Ubuntu 24.04, 2 vCPU, 1.8 GiB RAM: tight, so heavy jobs such as `app:photos:reprocess` and big warm-ups run on the dev machine). It runs several unrelated Compose projects behind one shared Traefik.
- **Two stacks, two project names.** New forum: directory `/root/www/comtso-forum`, Compose project **`comtso-forum`** (volumes `comtso-forum_*`), Traefik router `comtso-forum`. Old Symfony forum: `/root/www/comtso`, project **`comtso`** (router `comtso`). The names are deliberately different: reusing `comtso` would make Compose treat the old containers as orphans. Never run the old `make deploy` again (it rebuilds the stack under the project `comtso`).
- **Traefik** owns host ports 80/443; certificate resolver `gandi` (it uses the HTTP-01 challenge); the new forum is exposed only through `deploy/compose.traefik.yaml`. Traefik's entrypoint middleware still adds HSTS and `X-Frame-Options: SAMEORIGIN` to every router (D6).
- **Cutover**: rehearsal data kept (no reload), image pinned to a `sha-*` tag, `backup.sh` run just before, `app:cutover:prepare` run once (18 `previous_login`, 17 `chat_last_read_id`, one `ROLE_ADMIN`: the owner), old `nginx` stopped, `SERVER_NAME=comtso.fr`. Never run `app:cutover:prepare` a second time: it resets everybody's "what's new" pointers.
- **Dataset at go-live**: 18 users (8 enabled), 13 forums, 501 topics, 25 981 comments, 569 private messages, 2 425 photos.
- **Rollback target**: only the old `nginx` container of the legacy stack was stopped; its `php-fpm` and `mysql` containers and volumes are untouched and **the old stack is kept until it is retired on purpose**. Procedure: `docs/operations.md` "Rollback to the old forum". Anything posted on the new forum after the switch is not in the old database.
- **Backups**: the NAS script runs the server's `deploy/backup.sh` (DB dump + photo originals into `/var/backups/comtso`, 7 days kept on the server; this makes a second, server-side cron redundant: keep only one of the two) when the new stack is running and mirrors that directory into `new-forum/` next to the script (no deletion, prune by hand). Verifying it is in `TODO.md` wave 1.
- **Mail**: Scaleway TEM, sender domain `comtso.fr` (SPF and MX already published, DMARC `p=none`). The first reset-mail attempts of 2026-10-19 failed because the container still held the old secret file, and the silent per-account limiter then hid the retries: see `docs/operations.md` "Mail troubleshooting".

## Data import findings that still matter

- **Accepted as is (owner, 2026-10-19): all the data is in the new database, the old data is forgotten.** The one-shot import tooling (`app:legacy:import`, `app:legacy:reimport`, `src/Legacy/`, the `legacy-mysql`/`legacy-postgres` compose services, `docs/legacy-import.md`) was removed on 2026-10-19; the old dumps are no longer used and no migration script remains.
- **Accepted losses**: 6 phpBB 3 attachments that cannot be decoded (#2, 4, 5, 11, 13, 36; the files were damaged by a text-mode FTP copy long ago, no intact copy exists) and comment 842 (a Chinese quote, two characters lost bytes in the dump, the rest reads correctly). 3 old-forum photos (#302, #400, #401) have no file anywhere. The 10 attachments imported a few bytes shorter than recorded (#9, 10, 12, 14, 20, 27, 28, 29, 32, 37) are accepted as they are (no eyeball check).
- **`legacy_ref`** (unique, on imported rows of users, forums, topics, comments, photos...) is kept as provenance only (`<source>:<id>`); the columns stay in the schema and nothing reads them to import anything.
- Accounts that existed only in the old platforms were created **disabled**, with an unusable password and a `*.invalid` placeholder address when no real one was known. Members of the old Symfony forum keep their password (two historical hash formats, both accepted and upgraded at first login: `AGENTS.md` "Gotchas"). The former accounts that were relevant were re-enabled by the owner.

## History purge and rewrite (2026-10-19)

- **Why**: `docs/legacy/` held 18 screenshots of the old production site containing private member information. They were added to git on 2026-10-17. They are now kept privately by the owner, outside the repository, and `/docs/legacy/` is git-ignored so they cannot be re-added by accident. Production images never contained them (`.dockerignore` excludes `docs/`).
- **What was done**: the directory was removed from every commit of every ref; every commit was rewritten under one identity (the owner's personal one, replacing the former work identity and the name variants); the history was linearized (no merge commits) and the commits re-signed with the owner's key; all remote branches other than `master` were deleted; `master` was force-pushed. All commit ids changed, so SHAs quoted in older notes, and the ghcr `sha-*` image tags of the old commits, no longer match any commit.
- **Consequences**: every other clone is obsolete and must be re-cloned, never pulled (a pull would merge the old history back). GitHub keeps old commits reachable by id, in cached views and in pull-request refs: only GitHub Support can remove them. **If not done yet, the owner files a Support request** ("remove cached views and references to sensitive data") naming the repository and the purged paths.
- **Secret scan** (gitleaks over every commit of every ref, before the rewrite): 5 findings. One is a fake password in a test fixture (false positive). The others are the development `MERCURE_JWT_SECRET`, present in a `.env` that was tracked for a while and, as a real-looking value, in `.env.example` (still there, see `TODO.md` housekeeping); manual review showed the same `.env` also carried the development `APP_SECRET`, the dev DB passwords and a placeholder mailer DSN. **They are development-only secrets and the owner accepted them as they are**; production secrets are generated randomly on the server (`openssl rand -hex 32`) and never leave it. No dump, key, certificate or photo file was ever committed. Limits of the scan: regex-based, it cannot look inside images and does not check that a credential is live.
