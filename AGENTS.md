# AGENTS.md — ComTSo forum

Private forum for a circle of friends (French-speaking, history back to 2004). Symfony 8.1 + FrankenPHP/Caddy + MariaDB 11.4,
Vite + Tailwind CSS 4 + Vue 3 (only for interactive widgets), Mercure for the chat. **Read this file before changing anything.**

## Golden rules

1. **Every bug fix MUST come with a regression test** that fails without the fix. No exception. Features get tests for their important behaviour too.
2. **The forum is completely private.** Anonymous visitors are always redirected to `/login` (see `access_control` in `config/packages/security.yaml`
   and `FallbackController` for unknown URLs). Never serve user data from `public/`.
3. **Registration is disabled** unless `REGISTRATION_ENABLED=true` (feature flag, parameter `app.registration_enabled`). `/register` redirects to `/login` otherwise.
4. **Stored content is sanitized HTML.** Every write path (forms) runs `App\Content\ContentSanitizer`; rendering sanitizes again (`|rich_text`). Never `|raw` user content.
5. **UI language is French, code/comments/commit messages are English.** Dates are displayed in `Europe/Paris` (the data is stored in Paris local time, `date.timezone=Europe/Paris`).
6. Never post or delete anything on the real production forum (`comtso.fr`, which is this app) when browsing it for reference.

## Git workflow

- **Always commit WITHOUT signing**: the repo has `commit.gpgsign=true`, so use `git -c commit.gpgsign=false commit ...`. The owner reviews the commits and rebases to sign them later. Never stop or loop because of GPG.
- **Never push** to `master`/`main` (or force-push anything): the owner pushes after reviewing and signing the commits. Commit locally and stop; report the commits instead.
- **Atomic commits**: one logical change each; the tests and docs that belong to a change go in the same commit; the tree stays green after each commit.
- **One message convention**: Conventional Commits, `type(scope): imperative English summary`. Types used here: `feat`, `fix`, `refactor`, `docs`, `test`, `ci`, `chore`; optional scope (`mail`, `photos`, `design`, `deploy`...). Summary lower-case, no trailing period, 72 characters at most; a body only when the why is not obvious.

## Running the app

Everything runs in Docker through the **single dev `compose.yaml`** (production is `deploy/compose.yaml`, see "Deploy" below). Never run PHP/composer/npm on the host.

```bash
cp .env.example .env                       # first time only: dev secrets read by Compose (see "Configuration and secrets")
docker compose up -d                       # php (FrankenPHP), database (MariaDB), node (vite build --watch), mailer
docker compose exec php bin/console ...    # Symfony console
docker compose exec php composer ...
docker compose exec node npm ...           # assets are rebuilt automatically into public/assets (git-ignored)
docker compose up -d design-system         # started by `up -d` too; DEV ONLY showcase of the design system: http://localhost:5174/ (Vite dev server, compose.yaml only, not in the production image)
docker compose exec node npm run contrast  # WCAG AA check of every text/background pair in both themes
```

### Configuration and secrets

PHP never parses a `.env` file: `symfony/dotenv` is not installed and `composer.json` has `extra.runtime.disable_dotenv`. **All configuration is real environment variables supplied by Docker Compose**
(`bin/console` and `public/index.php` get `APP_ENV`/`APP_DEBUG` from the environment; `phpunit.dist.xml` forces `APP_ENV=test`, `APP_DEBUG=1` and `KERNEL_CLASS`).

- **Non-secret settings** live in the `environment:` block of the `php` service in `compose.yaml`, each with an inline default, overridable from the shell (`REGISTRATION_ENABLED=true docker compose up -d`):
  `APP_ENV`, `DEFAULT_URI`, `TRUSTED_PROXIES`, `REGISTRATION_ENABLED`, `MAILER_FROM` (sender of the e-mails, `Name <address>`), `DATABASE_URL`, `MERCURE_URL`, `MERCURE_PUBLIC_URL`, `SERVER_NAME`, Traefik knobs. Adding a setting = add it there (and to `deploy/compose.yaml`).
- **Secrets** (`APP_SECRET`, `DATABASE_PASSWORD`, `DATABASE_ROOT_PASSWORD`, `MERCURE_JWT_SECRET`, `MAILER_DSN`) live in the git-ignored root `.env`, which is **read by Compose only** (copy the committed `.env.example`).
  Compose turns each into a file under `/run/secrets/<name>` (top-level `secrets:` with `environment:` source) and the containers receive only the *path*:
  Symfony reads `%env(trim:file:APP_SECRET_FILE)%`, `%env(trim:file:MERCURE_JWT_SECRET_FILE)%`, `%env(trim:file:MAILER_DSN_FILE)%`; `DATABASE_URL` embeds `%env(trim:file:DATABASE_PASSWORD_FILE)%`
  (Doctrine's `resolve:` processor expands it at runtime, so passwords must not contain URL-special characters: use hex); Caddy reads `{file.{$MERCURE_JWT_SECRET_FILE}}` in the Caddyfile;
  MariaDB uses its native `MARIADB_*_FILE`. A new secret = `.env.example` + `secrets:` entries (top level and per service) + a `*_FILE` env var + a `trim:file:` reference.
- The dev DB passwords only matter when the `database_data` volume is created; `comtso`/`comtso`, `root`/`root` are what the existing volume holds.

### Reverse proxy (Traefik) and TLS

The app is exposed **only** through the shared Traefik instance (`../traefik`, external Docker network `proxy`, override with `TRAEFIK_NETWORK`):

- URL: **https://comtso.local.sidus.fr** (`SERVER_NAME`; `*.local.sidus.fr` resolves publicly to 127.0.0.1). Traefik obtains a valid
  Let's Encrypt certificate itself through the `gandi` DNS-challenge resolver (`CERT_RESOLVER`), nothing to configure locally.
- The `php` service carries the Traefik labels (`compose.yaml`). The router/service name is `comtso`.
- **Traefik forwards to port 80 of the container**: FrankenPHP/Caddy listens on plain HTTP there (`CADDY_SITE_ADDRESS=":80"`, port-only so that
  internal calls such as the Mercure publish to `http://php/.well-known/mercure` also work). The original request asked for port `8843`;
  nothing listens on that port in this stack, so `80` is used.
- Symfony trusts the proxy headers (`TRUSTED_PROXIES=private_ranges`, `framework.trusted_proxies`) so generated URLs and cookies are `https`.
- No port is published on the host except MariaDB (`DATABASE_PORT`, default `3307`) and the dev-only design-system showcase (`DESIGN_SYSTEM_PORT`, default `5174`, plain HTTP). `TRAEFIK_ENABLE=false` removes the Traefik router (second copy of the stack, CI).

### Database

- `comtso` (app), `comtso_test` (PHPUnit). Credentials `comtso`/`comtso` (root `root`), from `.env` secrets. `docker/database/10-test-db.sql` creates the test DB + grants on first volume init. The compose project is pinned to `comtso` (`name:`): volumes `comtso_database_data`, `comtso_caddy_data`, `comtso_caddy_config` hold the dev data: **never `docker compose down -v`**.
- Schema is managed by Doctrine migrations only (`bin/console doctrine:migrations:diff` then `migrate`). `doctrine:schema:validate` must stay green.
- Every table and text column is `utf8mb4` (collation `utf8mb4_uca1400_ai_ci`, connection `charset=utf8mb4` in `DATABASE_URL`), checked on 2026-10-19 on the dev DB and `comtso_test`: 4-byte emojis need no migration. `tests/Content/EmojiStorageTest.php` fails if a column ever falls below `utf8mb4`.
- All the data of the old platforms lives in this database; the one-shot import tooling was removed on 2026-10-19 (`docs/decisions.md` "Data import findings").

## Tests

PHPUnit 12 + DAMA (each test runs in a rolled-back transaction on the real `comtso_test` database).

```bash
docker compose exec php sh -c 'APP_ENV=test php bin/console doctrine:migrations:migrate -n'   # once, and after each new migration
docker compose exec php vendor/bin/phpunit
```

No `.env.test`: the environment of the `php` container (compose) plus `phpunit.dist.xml` (`APP_ENV=test`, `APP_DEBUG=1`, `KERNEL_CLASS`) is all the suite needs.

Base class `tests/Support/AppWebTestCase.php` offers `createUser/createForum/createTopic/createComment/loginAs`. Start small, test behaviour
(security, permissions, pagination, ordering), never wiring or markup trivia.

Layout: `tests/Admin` (admin panel: access, every page, each screen, translations, worker mode), `tests/Security` (privacy, login + throttling, password change/reset, CSRF, health, legacy photo URLs, prod config, activity tracking), `tests/Command` (cutover, reset-password), `tests/Forum` (pagination, permalinks, favourites, digest, permissions, preferences, search,
private messages), `tests/Photos`, `tests/Chat`, `tests/Content` (sanitizer), `tests/Smoke` (every page renders).

## Deploy and CI

- **Production** = `deploy/compose.yaml` + `deploy/.env.example` (copied to `deploy/.env` on the server, never committed) running the **pre-built image** `ghcr.io/vincentchalnot/comtso:${COMTSO_IMAGE_TAG:-latest}`
  (`frankenphp_prod` target: assets built in `frontend_builder`, `composer --no-dev --classmap-authoritative`, `APP_ENV=prod`, `APP_DEBUG=0`, no `.env`/dotenv in the image). Services: `app` (Caddy + FrankenPHP + the embedded
  Mercure hub, healthcheck = public `GET /health` through loopback on the internal `http://app:8080` listener with `Host: app`: `healthy` = Caddy + PHP + MariaDB), `migrate` (one-shot `doctrine:migrations:migrate`, `app` waits for it: single replica only), `database` (MariaDB 11.4, healthcheck, no published port).
  Same secret mechanism as dev (see "Configuration and secrets"). Volumes: `photos` (`/app/var/photos`), `caddy_data`, `caddy_config`, `database_data`. Direct HTTPS by Caddy (`SERVER_NAME`) or behind a proxy
  (`CADDY_SITE_ADDRESS=:80`, `TRUSTED_PROXIES`; shared Traefik = override file `deploy/compose.traefik.yaml` via `COMPOSE_FILE` in `deploy/.env`, router `comtso-forum`, knobs `CERT_RESOLVER`/`TRAEFIK_NETWORK`). The Symfony app publishes to Mercure on an internal-only listener `http://app:8080`.
  Outgoing mail: `MAILER_DSN` is a Symfony Mailer DSN (`symfony/mailer`, `smtp://` needs no extra package); **Scaleway Transactional Email is the recommended provider**: `smtp://<SCW_PROJECT_ID>:<SCW_SECRET_KEY>@smtp.tem.scaleway.com:587` (sender domain validated in the TEM console, `MAILER_FROM` on it), see `deploy/.env.example`. Runbook (first deploy, admin, upgrade, rollback, backup): `deploy/README.md`.
- **Dockerfile stages / base image**: `php_base` (FrankenPHP + system packages + all PHP extensions incl. a disabled Xdebug: the slow part, ~2 min cold) sits between the `###> base-image ###` markers; `frankenphp_base` (`FROM ${BASE_IMAGE}`: FrankenPHP config files),
  `frankenphp_dev`, `frontend_builder`, `prod_libs`, `frankenphp_prod_builder`, `frankenphp_prod`. CI publishes `php_base` as **`ghcr.io/vincentchalnot/comtso-base:<hash>`** (hash = `docker/base-hash.sh`, of the marked block only) and only when that tag does not exist yet.
  `ARG BASE_IMAGE` defaults to the local `php_base` stage (always builds, offline too); the dev `compose.yaml` passes the pinned published tag instead, so `docker compose up` pulls the extensions instead of compiling them.
  **After editing the base block** (extension list, system packages, upstream image): run `docker/base-hash.sh --write` (re-pins `compose.yaml`; CI fails otherwise), commit, and until CI has published the new tag build locally with
  `COMTSO_BASE_IMAGE=php_base docker compose build php`. Offline: same variable. The package must be **public** (or `docker login ghcr.io`) for anonymous pulls.
- **CI** (`.github/workflows/ci.yml`, GitHub Actions, skipped for docs-only changes): `base` (publishes the base image if its tag is missing; forks/Dependabot cannot push and fall back to the local stage + gha cache) →
  in parallel `test` (dev image on the pulled base, compose up, migrations on `comtso_test`, `doctrine:schema:validate`, `lint:twig|yaml|container`, PHPUnit), `frontend` (`npm ci && npm run build`, `npm run contrast`), `audit`
  (`composer audit`, `npm audit --omit=dev --audit-level=high`: **non-blocking**, `continue-on-error`), `gitleaks` (blocking secret scan of the whole history, pinned official image, allowlist in `.gitleaks.toml` for the reviewed dev findings only) and `image` (builds `frankenphp_prod` into the gha cache on every event, so a broken Dockerfile fails the PR, then smoke-tests it through `deploy/compose.yaml` with throwaway secrets: `/health` = `ok`, `/` redirects to `/login`; the local-only tag is never pushed). `publish`
  (needs `test`, `frontend`, `image`, `gitleaks`; pushes only, never on pull requests) re-runs the build against the cache and pushes to ghcr.io (`latest` on the default branch `master`, `sha-<short>`, `X.Y.Z`/`X.Y` for `v*` tags): keep CI green before tagging. Dependabot (`.github/dependabot.yml`) opens weekly grouped PRs. Repository settings and branch-protection recommendation: `docs/operations.md`.
  **Weekly refresh** (`.github/workflows/refresh-base.yml`, Monday 04:00 UTC + manual dispatch): rebuilds `php_base` with `--pull --no-cache`, overwrites the same hash tag (also tagged `<hash>-<run id>`), then rebuilds and pushes the prod image as `latest` + `YYYYMMDD` so servers get OS/PHP security fixes on the next pull; it does not re-run the tests.
  A newer push cancels the run in progress (never for tags). Layer caches: base = ghcr image, other stages = `type=gha` (scopes `dev`, `prod`, `base`); `RUN --mount=type=cache` for npm/composer only helps local builds (not exported to gha).
- **Security headers** are set in `frankenphp/Caddyfile` (CSP, nosniff, Referrer-Policy, X-Frame-Options, Permissions-Policy, X-Robots-Tag; HSTS only with `HSTS_ENABLED=true`, set in `deploy/compose.yaml`, absent in dev). The CSP allows the single inline
  theme script of `base.html.twig` by sha256: after editing that script, put the hash printed by `tests/Security/CaddyCspTest.php` into the Caddyfile. `/robots.txt` (`Disallow: /`) is a Caddy `respond`, reachable without login. In dev the shared Traefik
  (`security-headers@file`) replaces `Content-Security-Policy` and adds its own X-Robots-Tag, and the Symfony profiler toolbar uses inline scripts that the CSP blocks (dev only): to test the real CSP in a browser, override the response header in the test tool.
- **Logs** (prod): Monolog = `fingers_crossed` at error (50-record buffer, info and above) + an always-on `audit` channel (notice+: `LoginAuditSubscriber` failed logins, `PasswordReset` mail trail with recipient domain only, never addresses/passwords/SMTP user), JSON on stderr, deprecations dropped. Caddy access log is OFF unless `CADDY_ACCESS_LOG=on` (snippet in `frankenphp/Caddyfile`, skips `/health`). `deploy/compose.yaml` rotates every service log (json-file 5 x 10 MB). Secrets are files created with the container: after editing `deploy/.env` use `docker compose up -d --force-recreate app`. `openssl.cafile` is set in `frankenphp/conf.d/10-app.ini` (`TlsTrustStoreTest`).
- **Backups**: `deploy/backup.sh` (DB dump + photo originals, retention `BACKUP_KEEP_DAYS`, `BACKUP_DIR`) and `deploy/restore.sh --yes <db> [photos]`; cron line in `deploy/README.md`.
- **Production server** (live since 2026-10-19): server facts, rollback to the old forum, backup/restore checks, secret rotation, monitoring and the Sentry plan are in `docs/operations.md`; decisions and history in `docs/decisions.md`; the backlog in `TODO.md`. The prod compose project is named `comtso-forum` on purpose (the old Symfony stack of that server is the project `comtso`).

## Architecture notes

- **Photos are private**: files in `var/photos/originals` (bind-mounted from the host in dev), thumbnails generated lazily in
  `var/photos/cache/{thumb,medium,hero,avatar}` by `App\Service\ImageProcessingService` (imagine/imagine, GD) and **always WebP** (`image/webp`). Served only by authenticated
  controllers (`photo_source`, `photo_variant`), every response `X-Content-Type-Options: nosniff`. `hero` (≤1600 px, inset, never upscaled) feeds the home hero;
  `app:photos:warm --variants=avatar,thumb,medium,hero` pre-generates them.
- **Photo pipeline** (`App\Photo\PhotoPipeline`, the ONLY way an image enters the library: uploads via `PhotoUploader::store`, topic photo manager, `app:photos:reprocess`
  via `PhotoReprocessor`): 1. header only (`getimagesize`, real type, never the client's): > 100 megapixels / > 30 000 px a side is refused BEFORE decoding, and an image that would
  not fit in the remaining `memory_limit` (768M, `frankenphp/conf.d/10-app.ini`) is refused with a French message instead of a fatal error; 2. EXIF read from the untouched original
  (`App\Photo\ExifReader`: curated flat UTF-8 map, rationals/GPS normalised); 3. orientation applied, downscaled to ≤ 10 MP / 8000 px (never upscaled), re-encoded as WebP q85 = the stored "original"
  (drops polyglot payloads; `original_filename` is kept for display, downloads are named `<name>.webp`); 4. animated GIFs are kept byte for byte as GIF (≤ 10 MP, else refused). Upload limit **10 MB**
  per file (`PhotoUploader::MAX_SIZE`, `upload_max_filesize=10M`, `post_max_size=128M`; restart `php` after changing the ini), 10 files per message.
  Persisted on `Photo`: `taken_at` (indexed), `gps_latitude/longitude/altitude` (indexed together, for a future map; **shown only to the photo's owner and administrators**), `exif` JSON (Make, Model, Lens*,
  ExposureTime + `ExposureTimeFraction`, FNumber, ISO, FocalLength(+35mm), Flash, GPS*, `Original{Width,Height,FileSize,MimeType}`…); the photo page lists them through `PhotoDetails` (twig `photo_details`).
  `app:photos:reprocess [--dry-run] [--limit=N]` migrates a restored library in place (idempotent: WebP within the caps and animated GIFs are skipped); run with `-e XDEBUG_MODE=off --no-debug`.
  Photo deletion (`POST /photos/{id}/delete`, CSRF): the author only when nothing uses the photo, an administrator always (links, likes and avatar go with it, files removed).
  **Rotation** (`POST /photos/{id}/rotate`, owner or administrator, CSRF token `rotate-photo-{id}`, `direction=left|right`, buttons on the photo page except for GIFs): `App\Photo\PhotoRotator` writes the turned picture through `PhotoPipeline::rotate()` (same decode/orient/cap/WebP q85 steps, a re-encode per turn; an animated GIF is refused by looking at the file)
  under a NEW file name, switches the row (file, size, type, width/height, `exif.Orientation` = 1), then deletes the old file and its `cache/{thumb,medium,hero,avatar}` entries. **Image URLs are versioned**: browsers keep photo files for a year (`immutable`), so every URL
  goes through `App\Photo\PhotoUrls` / the twig function `photo_url(photo, 'thumb|medium|hero|avatar|source|download')` and carries `?v=` = `Photo::getFileVersion()` (changes with the file name). Never build `photo_variant` / `photo_source` URLs by hand.
- **Likes and home hero**: members like/unlike photos (`PhotoLike`, `ctso_photo_like`, unique user+photo, ON DELETE CASCADE; `POST /photos/{id}/like` toggles with CSRF token `like-photo-{id}` and answers
  `{liked, count}` to fetch / redirects without JS; `GET /photos/{id}/like` gives the state + token). The photo page has the heart + who liked; the lightbox heart (`ui.js`, `data-photo-id` on every
  `data-lightbox` link) loads the state lazily. **Home hero** (`App\Photo\FeaturedPhotoProvider`, `templates/home/_hero.html.twig`): a RANDOM photo, drawn on every page load, among the 10 distinct photos most
  recently liked (avatars excluded; `Random\Randomizer` injected, seeded in tests); nothing liked = no hero. The home response is `private, no-store`.
- **Photos on messages**: `App\Entity\CommentPhoto` (`ctso_comment_photo`, unique comment+photo, ON DELETE CASCADE) links photos to a message; `PhotoTopic` still links them to a topic
  (the new-topic form attaches uploads to the topic). The unmapped `PhotoUploadType` field (`comment[photos][]`, max `PostPhotos::MAX_PER_POST` = 10 files of `PhotoUploader::MAX_SIZE` = 10 MB, real
  content type checked by `Image` + the pipeline) is enhanced by `assets/vue/components/AttachPhotos.vue` (drag & drop, paste, thumbnails, keeps the input's `FileList` in sync, so a plain
  submit works). `App\Photo\PostPhotos` stores all-or-nothing, links, detaches and `releaseOrphans()` (file + row deleted only when no topic, message or avatar still uses the photo): used by
  create/edit/delete of a comment (only author/admin; the edit page lists attached photos with a remove checkbox). Pages rendering many messages call `CommentPhotoRepository::preload($comments)` (one query) before
  rendering `_post/message.html.twig`: do the same in any new listing. In tests, `em()->clear()` before a request that must lazy-load collections created in the same test.
- **Avatars**: a member's avatar is a `Photo` of the library (`User::avatar`, displayed by the `_ui/avatar.html.twig` macro through the `avatar` variant everywhere). Members change or remove their OWN avatar on `/gens/{pseudo}/config`
  (`PeopleController::avatar`, POST `people_avatar`, Symfony form `AvatarType` = its own CSRF token, 10 MB and real content check through `PhotoUploadType::imageConstraint()`): `App\Photo\AvatarManager` stores the file through the photo pipeline,
  switches the link, then releases the previous avatar with `PostPhotos::releaseOrphans()` (kept when a topic or message still uses it). Administrators cannot change somebody else's avatar (no form on their page, POST = 403).
- **Thread previews** (forum page): `App\Forum\ThreadPreviewBuilder` builds the read model of a whole page of topics with batch queries (query count asserted in `ForumPageTest`);
  excerpts are plain text from `App\Content\TextExcerpt` (twig `excerpt_text`, auto-escaped), relative dates come from the `ago` filter. The inline "Aperçu" fetches
  `GET /forum/{forumId}/{id}/preview` (`TopicPreviewController`, HTML fragment of the last 5 messages, private/no-store) through `initThreadPreviews()` in `assets/scripts/ui.js`.
  Markup of the new components is documented in `docs/components-markup.md`.
- **Search** (`/recherche?q=&tab=&page=`, `SearchController`, `App\Search\SiteSearch`): six tabs (comments, topics, photos, chat, people, quotes; labels, page sizes `perPage` and empty texts in `SiteSearch::TABS`), each a plain link carrying the term (no JavaScript).
  One `COUNT` per kind feeds the tab badges, only the open tab runs a bounded page query (`Repository::search($term, $limit, $offset)` + `countSearch($term)`, deterministic order with the id as tie-breaker); the default tab is the first one with hits, an unknown `tab` falls back to it and a `page` that is not a whole number or out of range is clamped (never a 400).
  Results join the author's avatar (`a.avatar`) so the page has no per-result query (asserted in `tests/Forum/SearchTabsTest.php`). Privacy is unchanged: members only, disabled members never listed or counted.
- **Statistics** (`/statistiques`, `StatisticsController`, nav link "Statistiques"): read-only, every member. `App\Stats\MessageStatistics::report()` runs ONE aggregate DBAL query (comments and chat grouped by author and `YEAR(created_at)`, `UNION ALL`, joined to `fos_user` for the pseudo and `enabled` flag ONLY: no address is ever selected). Disabled members are listed by pseudo without a profile link, messages without author are "Anciens membres".
  The page has an accessible table (server-rendered, the source of truth) and two `assets/vue/components/StatsChart.vue` charts (pure SVG, props via `data-props`, series colours `link` / `kicker` so AA holds in both themes; showcase section `design-system/sections/35-stats-chart.html`). Tests: `tests/Forum/StatisticsTest.php`.
- **Legacy-compatible URLs** (old links were shared in messages): `/forums/`, `/forums/{forum}`, `/forum/{forum}/{topic}`, `/comment/{id}`, `/gens/{username}`, `/photos/{id}`, `/topic.php?id=`, and the old forum's photo links `/photos/preview/N.jpg` / `/photos/thumbnail/N.jpg` (68 messages use them): `LegacyRedirectController` answers
  301 (members only, like everything) to the versioned `photo_variant` `medium` / `thumb` URL of the oldest photo whose `original_filename` is `N.jpg` (never a file path); that 301 is `private, max-age=3600` so a rotation reaches those links within the hour.
- Entities keep the legacy table names (`fos_user`, `ctso_*`). Rows carried over from the older platforms carry `legacy_ref` (unique, e.g. `phpbb3:post:1234`): provenance only, nothing imports from it anymore.
- User preferences live in `fos_user.config` (JSON): `theme` (`light|dark`), `message_order` (`asc|desc`).
- `previous_login`/`last_activity` on users drive "what's new since your last visit" on the home page.
- **Design system** (`docs/design-system.md` is the reference; showcase at `http://localhost:5174/`): Tailwind 4 written in **SCSS** (`assets/styles/app.scss` -> `_index.scss`, Sass runs first then `@tailwindcss/postcss` in `vite.config.js`; `@tailwindcss/vite` is not used). Colours are theme-scoped CSS variables (`--c-<role>`, roles in `assets/styles/themes/_light.scss` / `_dark.scss`, derived from the five brand colours in `abstracts/_palette.scss`); light/dark is `html[data-theme]` (a `data-theme` on any element re-themes its subtree). Retheming = editing a role colour, then `npm run contrast` (AA is mandatory in both themes). Components live one-per-file in `assets/styles/components/`, inside `@layer components` so utilities win; class names (`.btn .card .post .chat-* .rich-editor* .hero .thread-preview …`) are a stable API (see `docs/components-markup.md`). Paper textures are pseudo-elements (`texture-host` + `texture-tone` mixins, recipe computed from the host colour's luminance per theme, dial `--texture-ratio`; tiles tracked in `assets/images/textures/`, **not** in the git-ignored `public/images/`). Fonts are self-hosted (`@fontsource-variable`, no CDN). New component = new partial + `@use` in `_index.scss` + a showcase section in `design-system/sections/`.
- **Chat** (`/chat`, `App\Controller\ChatController`, `assets/vue/components/ChatRoom.vue`): messages are PLAIN TEXT (rendered as text nodes, URLs linkified by the widget, never `v-html`).
  Real time is **private Mercure**: every update is published with `private: true` (`App\Chat\ChatPublisher`; topic `chat` for messages,
  `chat/users/{id}` for a member's read pointer), the Caddy hub has no `anonymous` directive, and members subscribe with the subscriber JWT cookie
  that `GET /chat/state` issues (same origin, `Secure`, `SameSite=Lax`, cleared on logout). `assets/scripts/chat-badge.js` (every page) owns the single
  `EventSource`, keeps the nav badge / `document.title` live and re-emits `chat:message`, `chat:status` events that the chat page consumes.
  Unread = other members' messages with id > `fos_user.chat_last_read_id` (monotonic, see `ChatMessageRepository::markReadUpTo`). Online = `last_activity` within 5 minutes.
  In tests the hub is replaced by `tests/Chat/RecordingHub` (see `config/packages/mercure.yaml`).
- **Rich text** (topic descriptions, comments, private messages, signatures): the `RichTextType` form field (`App\Form\Type\RichTextType`, theme `templates/form/rich_text.html.twig`)
  is a plain textarea enhanced by the Vue/Tiptap editor `assets/vue/components/RichEditor.vue` (Tiptap is code-split into `assets/vue/editor/create-editor.js`,
  loaded only on pages with an editor; options `compact`, `min_height`, placeholder via `attr`). Submitted HTML is sanitized in `PRE_SUBMIT` and again at render (`|rich_text`)
  by `App\Content\ContentSanitizer`, an allow-list defined in `config/packages/html_sanitizer.yaml` plus `App\Content\AttributeSanitizer\*`: inline CSS is reduced to
  `text-align`/`text-decoration`, links are `http(s)/mailto` with forced `rel`/`target`, images `http(s)` or site-relative, and the **only iframe allowed is a YouTube player**,
  rewritten to `https://www.youtube-nocookie.com/embed/<id>` (`App\Content\YouTube`, mirrored in `assets/vue/editor/youtube.js`); a paragraph holding only a YouTube link
  becomes the player. Adding a smiley = drop the gif in `public/smilies/` **and** list it in `assets/vue/editor/smilies.js` (a test enforces it).
  **Emojis** are plain Unicode text (astral characters pass the sanitizer untouched, chat messages and private messages included): the editor's second popover ("Émojis", `assets/vue/editor/emojis.js`,
  curated groups with French labels, Unicode 12 or older, inserted as text, no dependency) sits next to the gif smilies. Adding an emoji = one line in `emojis.js`; `tests/Content/EmojiPickerTest.php` checks every entry survives the sanitizer, `EmojiStorageTest` that each write path stores and shows them back.
  After changing the allow-list run `bin/console app:content:sanitize --dry-run` then without `--dry-run` (idempotent, batched, plain SQL, leaves `updated_at` alone).

- **Authentication hardening** (`config/packages/security.yaml`, `framework.yaml`): `login_throttling` = 5 attempts / 15 min per username + IP (25 per IP), counters in the filesystem pool `limiter.cache`
  (`app.limiter.storage` in memory under `when@test`: a test that needs counts across requests calls `$client->disableReboot()`). Logout needs its stateless CSRF token: use `{{ logout_path() }}`, tests use `$this->logOut()`.
  Session and remember-me cookies are `HttpOnly`, `SameSite=Lax`, `Secure` in prod (`when@prod`); `kernel.debug`, profiler and toolbar exist only in dev/test (`tests/Security/ProductionConfigTest.php` boots the prod kernel).
  Every POST/DELETE route checks a token or is a Symfony form: `tests/Security/CsrfTest.php` lists them and fails when a new one is not classified.
- **Passwords**: members change theirs on `/gens/{pseudo}/config` (current password, min 10 characters, native hasher, signed in again on a fresh session; old remember-me cookies and sessions die with the old hash).
  "Mot de passe oublié ?" (`PasswordResetController`, `App\Security\PasswordReset`) mails a link signed with `UriSigner` (1 h) bound to the member's current password hash + e-mail, so it is single use without any table;
  the answer is always neutral (no enumeration; mail is sent in `kernel.terminate`), 10 requests/h per IP and 3/h per account (`rate_limiter` in `framework.yaml`), never to disabled accounts or `*.invalid` placeholder addresses.
  Links are built on `DEFAULT_URI`, sender is `MAILER_FROM`. Fallback for the owner: `bin/console app:user:reset-password <pseudo> [--password=…]`.
- **E-mails**: always built with `App\Mail\TemplatedMailFactory` (templates `email/<name>.html.twig` + `.txt.twig` extending `email/base.*.twig`, macros in `email/_macros.*.twig`, see `docs/design-system.md` "Emails"). The factory renders BOTH parts immediately: never render Twig in a `kernel.terminate` deferred task (after the response, `ob_start()` fails under FrankenPHP and the mail leaves empty); only `MailerInterface::send()` may be deferred.
- **Health**: `GET /health` is public, `200 ok` (`SELECT 1`) or `503 unavailable`, `Cache-Control: no-store`, nothing else leaks.
- **Moderation**: administrators delete a whole topic from its page (`topic_delete`, confirm + CSRF; `App\Forum\TopicRemover`: bulk delete of messages, photo links, favourites, then `PostPhotos::releaseOrphans`).
  Forums are managed in the admin panel (below); only an EMPTY forum can be deleted (`App\Forum\ForumRemover`).
- **Admin panel** (`/admin`, ROLE_ADMIN only; nav link "Administration" visible to administrators only): built on the owner's bundles `sidus/admin-bundle` (screens + routes), `sidus/datagrid-bundle` (lists) and `sidus/filter-bundle` (filters/sorting/pager, configured INLINE under each datagrid's `query_handler`, there is no `sidus_filter` block).
  Everything is declared in `config/packages/sidus.yaml` (one `sidus_admin.configurations.<screen>` + one `sidus_data_grid.configurations.<screen>`), routes `sidus_admin.<screen>.<action>` come from `config/routes/sidus_admin.yaml` (prefix `/admin`, before `app_fallback`), access = `access_control ^/admin(/|$)` + `permissions: [ROLE_ADMIN]` on every screen.
  Screens: members (`user`: filters pseudo/account/role/last activity, enable/disable + ROLE_ADMIN, removal through `App\Forum\UserRemover`), forums (`forum`: create/edit, slug from the title and never changed, delete only when empty), topics (`topic`: read-only list, removal through `TopicRemover`), quotes (`quote`: full CRUD, plain text), photos (`photo`: read-only list, removal through `App\Photo\PhotoRemover`, which the photo page button uses too). No chat, likes or private messages. **Never an e-mail address in any admin page** (`tests/Admin` asserts it on every page); members' profile fields stay theirs.
  **Rules for a new screen**: 1. entity + datagrid config in `sidus.yaml` copying a neighbour (`controller_pattern: *controllers` resolves `App\Admin\Action\<Screen>\<Action>Action`, then `App\Admin\Action\<Action>Action`, then the bundle's); 2. `sortable` and filter `attributes` are explicit allow-lists, and `App\Admin\Action\ListAction` also replaces any other sort column of the request by the default one (the bundle would use ANY property as ORDER BY); 3. EXPLICIT `methods` on every action (`[GET]` for pages, `[GET, POST]` for forms and confirmation pages): bundle routes are `ANY` otherwise and the CSRF audit cannot see them; 4. a destructive action is NEVER the bundle's `DeleteAction` for something with dependants (it is a raw `EntityManager::remove`): extend `App\Admin\Action\AbstractRemovalAction` (confirmation page on GET, removal on POST with the form token) and call the domain service (`UserRemover`, `TopicRemover`, `ForumRemover`, `PhotoRemover`); a quote has no dependants and uses the bundle's delete (GET confirmation + POST with token); 5. forms are Symfony forms in `src/Form/Admin` (their token is the CSRF protection); 6. French labels in `translations/messages.fr.yaml` (`admin.<screen>.*`, `datagrid.<screen>.<column>`, `sidus.admin.<screen>.<action>.success`; `tests/Admin/AdminTranslationsTest` fails on a missing one); 7. cells are small templates under `templates/admin/` (`cells/*` are generic); a per-row query is forbidden: add joins in `prepareQuery()` or a grouped query in `extraViewParameters()` of a screen's `ListAction` subclass (see forum, topic, photo); 8. tests: add the pages to `tests/Admin/AdminPagesTest` (anonymous 302, member 403, admin 200, no address), a feature test, and the routes to `ADMIN_FORM_ROUTES` + `tokenlessAdminRequests()` of `tests/Security/CsrfTest` (it fails for an admin route without explicit methods or not classified).
  Safety: nobody can disable, demote or remove their OWN account, nor the last active administrator (`App\Admin\AdminAccountGuard`, used by `UserAdminType` and the removal); `app:user:promote` stays the way back. **FrankenPHP worker**: the bundles keep stateful `DataGrid`/query-handler objects for the whole process ("Pager already applied" on the second visit), so `App\DependencyInjection\RequestScopedDatagridPass` swaps their registries for `App\Admin\Registry\RequestScoped*` (rebuilt per request, `kernel.reset`); `tests/Admin/WorkerModeTest` covers it. Design system: `_filter-bar`, `_admin`, `_table`, `_pager`, showcase section `24-admin.html`, templates `templates/admin/` and `templates/bundles/SidusAdminBundle/Action/` (no Bootstrap anywhere).
  **Composer patch (filter bundle only)**: `sidus/admin-bundle` >= 5.0.7 and `sidus/datagrid-bundle` 4.0.x are released and need nothing. `sidus/filter-bundle` 6.0.3 does not boot on Symfony 8.1 (removed `TaggedLocator`/`TaggedIterator`, removed `Request::get()`); the fix is on the owner's `v6.x` branch, untagged, and applied here by `cweagans/composer-patches` v2 (`composer.json` `extra.patches` -> `patches/sidus/sidus-filter-bundle-symfony-8.patch`, `patches.lock.json`, plugin in `require` and `allow-plugins`; the prod Dockerfile copies `patches/` and `patches.lock.json` before `composer install`, patches need `git` which the base image has). **Remove it once `sidus/filter-bundle` >= 6.0.4 is tagged**: `composer require "sidus/filter-bundle:^6.0.4" --no-install`, delete the `extra.patches` key, `composer remove cweagans/composer-patches`, delete `patches/` and `patches.lock.json`, drop `patches.lock.json` and `patches` from the Dockerfile `COPY` lines (and the comment above them), `composer update "sidus/*" --with-all-dependencies`, check the vendor code (`AutowireLocator`/`AutowireIterator` in the filter bundle, no `Request::get()`), delete this paragraph and the patch mention in `docs/decisions.md`, run the suite and build the prod image.
- **Cutover** (`app:cutover:prepare [--dry-run] [--delete-user=<pseudo>]…`, run once before opening the doors, with `-e XDEBUG_MODE=off --no-debug`): every member's `previous_login` = now and
  `chat_last_read_id` = latest chat message (never backwards), optional deletion of test accounts with what they wrote (`App\Forum\UserRemover`), then lists ROLE_ADMIN, disabled and mail-less accounts.
- A refused reply (`CommentController::create`) re-renders the topic page with the bound form and HTTP 422 (typed text is kept); the other forms already re-render in place.

## Legacy data

`docs/LEGACY_FEATURES.md` lists every feature of the old forum (reference for features/UX). Its screenshots are NOT in the repository (they show private member information; the owner keeps them privately, `/docs/legacy/` is git-ignored): never add them back.
All the data of the older platforms (old Symfony forum, first PHP forum, two phpBB installs) is already in the database; the import tooling is gone and the old dumps are not used (`docs/decisions.md`).

## Operations cheat-sheet

```bash
docker compose exec php bin/console app:user:create <pseudo> <password> [--admin]   # accounts are created by the owner (registration is off)
docker compose exec php bin/console app:user:promote <pseudo> [--revoke]            # grant/remove ROLE_ADMIN (moderate everything; the admin panel at /admin does it too)
docker compose exec php bin/console app:user:reset-password <pseudo> [--password=…]  # lost password, no usable e-mail: prints a one-time random password
docker compose exec -T -e XDEBUG_MODE=off php php bin/console app:cutover:prepare --no-debug --dry-run  # before go-live; then without --dry-run (see Architecture notes)
docker compose exec -T -e XDEBUG_MODE=off php php bin/console app:photos:warm       # pre-generate thumbnails (lazy otherwise); --variants=avatar,thumb,medium,hero
docker compose exec -T php php bin/console app:content:sanitize --dry-run --no-debug
```

Front-end: `assets/scripts/main.js` is the only entry; Vue components in `assets/vue/components/*.vue` are registered **automatically** (`import.meta.glob`) and
mounted on `[data-vue-component="Name"][data-props='{json}']`. If `public/assets` is empty or the watcher died on an import error, fix it and `docker compose restart node`.
The production image (`Dockerfile`, `frankenphp_prod` target) builds the assets in a `frontend_builder` stage.
Styling pipeline in one line: `assets/scripts/main.js` imports `assets/styles/app.scss`; fonts/textures are bundled and fingerprinted into `public/assets/` through the `@fontsource-variable` / `@textures` Vite aliases (also defined in `design-system/vite.config.js`).

## Gotchas

- Legacy passwords: the old forum stored `sha512 x5000 base64` of `password{salt}`, **or** of `sha1(sha1(password)){salt}` for members older than FOSUserBundle (its custom auth provider tried both). `App\Security\LegacyPasswordHasher` accepts both and `migrate_from` upgrades to bcrypt at first login. In `password_hashers`, Symfony takes the **first entry matching the user**, and `migrate_from` is ignored with `algorithm: auto`: keep `App\Entity\User` first with `algorithm: native`, and repeat the full entry in `when@test`.
- `compose.yaml` mounts an anonymous volume on `/app/var`: anything written to `var/` that is not bind-mounted (`var/photos`) is lost on `docker compose down`.
- Mercure hub runs in compatibility mode (`protocol_version_compatibility 8` in the Caddyfile / `protocol_version: '0.x'` in `config/packages/mercure.yaml`).
- Playwright MCP calls time out after 30 s: keep scripted crawls short (a few pages per call).
- Run whole-database console commands (`app:content:sanitize`, `app:photos:warm`) with `-e XDEBUG_MODE=off` and `--no-debug`: Xdebug and the dev
  profiler make them an order of magnitude slower. Id columns are not always integers (`ctso_forum.id` is a slug): never page on `(int) $id`.
- Private messages: `ctso_message.state` NULL = unread; opening `/gens/{user}` marks the messages received from that member as read. Memos (author = recipient) never count.
