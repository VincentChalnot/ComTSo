# AGENTS.md — ComTSo forum

Private forum for a circle of friends (French-speaking, history back to 2004). Symfony 8.1 + FrankenPHP/Caddy + MariaDB 11.4,
Vite + Tailwind CSS 4 + Vue 3 (only for interactive widgets), Mercure for the chat. **Read this file before changing anything.**

## Golden rules

1. **Every bug fix MUST come with a regression test** that fails without the fix. No exception. Features get tests for their important behaviour too.
2. **The forum is completely private.** Anonymous visitors are always redirected to `/login` (see `access_control` in `config/packages/security.yaml`
   and `FallbackController` for unknown URLs). Never serve user data from `public/`.
3. **Registration is disabled** unless `REGISTRATION_ENABLED=true` (feature flag, parameter `app.registration_enabled`). `/register` redirects to `/login` otherwise.
4. **Stored content is sanitized HTML.** Every write path (forms, imports) runs `App\Content\ContentSanitizer`; rendering sanitizes again (`|rich_text`). Never `|raw` user content.
5. **UI language is French, code/comments/commit messages are English.** Dates are displayed in `Europe/Paris` (the legacy data is stored in Paris local time, `date.timezone=Europe/Paris`).
6. Don't post/delete anything on the real production forum (`comtso.fr`, Symfony 2) when browsing it for reference.

## Running the app

Everything runs in Docker (`compose.yaml` + `compose.override.yaml`). Never run PHP/composer/npm on the host.

```bash
docker compose up -d                       # php (FrankenPHP), database (MariaDB), node (vite build --watch), mailer
docker compose exec php bin/console ...    # Symfony console
docker compose exec php composer ...
docker compose exec node npm ...           # assets are rebuilt automatically into public/assets (git-ignored)
```

### Reverse proxy (Traefik) and TLS

The app is exposed **only** through the shared Traefik instance (`../traefik`, external Docker network `proxy`, override with `TRAEFIK_NETWORK`):

- URL: **https://comtso.local.sidus.fr** (`SERVER_NAME`; `*.local.sidus.fr` resolves publicly to 127.0.0.1). Traefik obtains a valid
  Let's Encrypt certificate itself through the `gandi` DNS-challenge resolver (`CERT_RESOLVER`), nothing to configure locally.
- The `php` service carries the Traefik labels (`compose.yaml`). The router/service name is `comtso`.
- **Traefik forwards to port 80 of the container**: FrankenPHP/Caddy listens on plain HTTP there (`CADDY_SITE_ADDRESS=":80"`, port-only so that
  internal calls such as the Mercure publish to `http://php/.well-known/mercure` also work). The original request asked for port `8843`;
  nothing listens on that port in this stack, so `80` is used.
- Symfony trusts the proxy headers (`TRUSTED_PROXIES=private_ranges`, `framework.trusted_proxies`) so generated URLs and cookies are `https`.
- No port is published on the host except MariaDB (`DATABASE_PORT`, default `3307`).

### Database

- `comtso` (app), `comtso_test` (PHPUnit). Credentials `comtso`/`comtso` (root `root`). `docker/database/10-test-db.sql` creates the test DB + grants on first volume init.
- Schema is managed by Doctrine migrations only (`bin/console doctrine:migrations:diff` then `migrate`). `doctrine:schema:validate` must stay green.
- The old Symfony 2 database (`var/2610110300.sql.gz`) was imported with `bin/console app:legacy:import` (see below).

## Tests

PHPUnit 12 + DAMA (each test runs in a rolled-back transaction on the real `comtso_test` database).

```bash
docker compose exec php sh -c 'APP_ENV=test php bin/console doctrine:migrations:migrate -n'   # once, and after each new migration
docker compose exec php vendor/bin/phpunit
```

Base class `tests/Support/AppWebTestCase.php` offers `createUser/createForum/createTopic/createComment/loginAs`. Start small, test behaviour
(security, permissions, pagination, ordering, import idempotence), never wiring or markup trivia.

Layout: `tests/Security` (privacy, login, registration flag, activity tracking), `tests/Forum` (pagination, permalinks, favourites, digest, permissions, preferences, search,
private messages), `tests/Photos`, `tests/Chat`, `tests/Content` (sanitizer), `tests/Legacy` (importers, converters, idempotence), `tests/Smoke` (every page renders).

## Architecture notes

- **Photos are private**: originals in `var/photos/originals` (bind-mounted from the host in dev), thumbnails generated lazily in
  `var/photos/cache/{thumb,medium}` by `App\Service\ImageProcessingService` (imagine/imagine, GD). Served only by authenticated controllers.
- **Legacy-compatible URLs** (old links were shared in messages): `/forums/`, `/forums/{forum}`, `/forum/{forum}/{topic}`, `/comment/{id}`, `/gens/{username}`, `/photos/{id}`.
- Entities keep the legacy table names (`fos_user`, `ctso_*`). Imported rows carry `legacy_ref` (unique) so imports are idempotent.
- User preferences live in `fos_user.config` (JSON): `theme` (`light|dark`), `message_order` (`asc|desc`).
- `previous_login`/`last_activity` on users drive "what's new since your last visit" on the home page.
- Tailwind 4 with semantic colour tokens (`assets/styles/app.css`); light/dark is `html[data-theme]`. Retheming = editing the CSS variables.
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
  After changing the allow-list run `bin/console app:content:sanitize --dry-run` then without `--dry-run` (idempotent, batched, plain SQL, leaves `updated_at` alone).

## Legacy data

`docs/legacy/` holds screenshots of the Symfony 2 production site (reference for features/UX) and `docs/LEGACY_FEATURES.md` lists every feature seen there.
Older platforms (old PHP forum MySQL dump, two phpBB installs) live in `var/legacy/`; see `docs/legacy-import.md` for the one-shot reimport command.

## Operations cheat-sheet

```bash
docker compose exec php bin/console app:user:create <pseudo> <password> [--admin]   # accounts are created by the owner (registration is off)
docker compose exec php bin/console app:user:promote <pseudo> [--revoke]            # grant/remove ROLE_ADMIN (moderate everything)
docker compose exec -T -e XDEBUG_MODE=off php php bin/console app:photos:warm       # pre-generate thumbnails (lazy otherwise); --variants=avatar,thumb,medium
docker compose --profile legacy up -d legacy-mysql legacy-postgres                  # old dumps loaded from var/legacy/ (not started by default)
docker compose exec -T php php bin/console app:content:sanitize --dry-run --no-debug
```

Front-end: `assets/scripts/main.js` is the only entry; Vue components in `assets/vue/components/*.vue` are registered **automatically** (`import.meta.glob`) and
mounted on `[data-vue-component="Name"][data-props='{json}']`. If `public/assets` is empty or the watcher died on an import error, fix it and `docker compose restart node`.
The production image (`Dockerfile`, `frankenphp_prod` target) builds the assets in a `frontend_builder` stage.

## Gotchas

- `compose.override.yaml` mounts an anonymous volume on `/app/var`: anything written to `var/` that is not bind-mounted (`var/photos`) is lost on `docker compose down`.
- Mercure hub runs in compatibility mode (`protocol_version_compatibility 8` in the Caddyfile / `protocol_version: '0.x'` in `config/packages/mercure.yaml`).
- Playwright MCP calls time out after 30 s: keep scripted crawls short (a few pages per call).
- Run whole-database console commands (`app:content:sanitize`, `app:legacy:reimport`, `app:photos:warm`) with `-e XDEBUG_MODE=off` and `--no-debug`: Xdebug and the dev
  profiler make them an order of magnitude slower. Id columns are not always integers (`ctso_forum.id` is a slug): never page on `(int) $id`.
- `app:legacy:import --force` (Symfony 2 dump importer) wipes the content tables, **including rows added by `app:legacy:reimport`**: run `app:legacy:reimport` again afterwards.
- Private messages: `ctso_message.state` NULL = unread; opening `/gens/{user}` marks the messages received from that member as read. Memos (author = recipient) never count.
