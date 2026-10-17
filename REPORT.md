# ComTSo — final report (2026-10-17)

## 1. How to try it

- **https://comtso.local.sidus.fr** (Traefik, valid Let's Encrypt certificate). The forum is private: everything redirects to `/login`.
- Accounts are the migrated ones (legacy SHA-512 passwords still work and are rehashed on first login). The owner `Vincent` is now **administrator**
  (`app:user:promote`); nobody had a role in the legacy data. The throw-away `tester*` accounts I used are deleted.
- Registration is off: `REGISTRATION_ENABLED=true` in `.env.local` opens `/register`. New accounts: `bin/console app:user:create <pseudo> <password> [--admin]`.
- Tests: `docker compose exec php vendor/bin/phpunit` → **441 tests, 1105 assertions, green** (real MariaDB `comtso_test`, DAMA rollback). `composer audit` and `npm audit`: 0 advisories (`npm audit fix` applied).
  `doctrine:schema:validate`, `lint:twig`, `lint:container`, `lint:yaml`: green.

## 2. What was delivered

| Request | Result |
|---|---|
| Traefik + SSL | Labels on the `php` service, external network `proxy`, `*.local.sidus.fr`. **Port: I used 80, not the `8843` you wrote** — nothing listens on 8843 (Caddy serves plain HTTP on 80). Change `loadbalancer.server.port` in `compose.yaml` if you really run another port. |
| AGENTS.md | Rules (private forum, flag, sanitized HTML, **every bug needs a regression test**), Traefik/DB/test setup, architecture, operations cheat-sheet, gotchas. |
| Re-import of older dumps | `docker compose --profile legacy` (MySQL + Postgres loaded from `var/legacy/`) and the single-use `app:legacy:reimport` (idempotent, `--dry-run`, `--source`). Details and per-table numbers in `docs/legacy-import.md`. |
| Private forum / flag | `access_control` ends with `^/ ROLE_USER`; unknown URLs also redirect; photos and thumbnails moved out of `public/` and served by authenticated controllers. |
| Tests with DB | PHPUnit 12 + DAMA; base class `tests/Support/AppWebTestCase.php`; suites Security, Forum, Photos, Chat, Content, Legacy, Smoke. |
| Latest Symfony 8 | 8.1.9 (bundles, PHPUnit 12, Mercure bundle 0.6 adjusted). |
| Storage format | Sanitized **HTML** in the database (Tiptap editor), YouTube via `youtube-nocookie.com` iframe only. Imports convert phpBB BBCode / old-forum markup to the same HTML. |
| Home = digest | Everything since your previous visit, grouped by topic (`?since=` / date picker to change it). |
| Quote card, pagination, lightbox | Random quote in the side column of every page; 20 messages/page, opening on the last page with the oldest-first order (as the old site) and on page 1 with newest-first (config "ordre des messages"); lightbox with keyboard and swipe navigation. |
| Chat | Own page filling the container; private Mercure (JWT subscriber cookie, anonymous disabled); live unread badge in the menu and in the tab title; verified live between two browsers. |
| Production tour | 23 features documented in `docs/LEGACY_FEATURES.md` with screenshots in `docs/legacy/`. Search, "les gens", profiles, memos, photo manager and config are ported; see gaps below. |
| UX | Narrow cards with avatar, long posts collapsed ("Afficher le post"), meta/actions on hover (tap on mobile), permalink page per message, favourite threads (star, listed in the side column), config page with light/dark and "ordre des messages" (the Bootswatch theme is gone). |
| Tailwind | Done now rather than later: Tailwind 4 with semantic colour tokens (`assets/styles/app.css`), so your future theme = editing CSS variables. Vue only for editor, chat, photo manager, theme toggle. |

Extras I added because they were needed: unread private-message badges ("Les gens" menu + per-member), `app:user:promote`, `app:photos:warm`, a production Docker stage that builds the assets.

## 3. Imported data (live database)

Before → after: comments 22 068 → **25 971**, topics 367 → 501, private messages 392 → 569, forums 6 → 13, users 12 → 18, photos 2 394 → 2 396; history now starts on **2004-04-30**.
Per-source numbers (phpBB 2: 134 topics / 3 891 posts new; phpBB 3: 12 missing posts; old PHP forum: 17 messages and 2 photos) and every rule used (user matching, dedupe by timestamp + normalised text, forum mapping) are in `docs/legacy-import.md`; a second run imports 0 rows.

Decisions that deserve your eye:

- **7 new forums** for the phpBB 2 era ("Bienvenue !", "Conseil des ministres", "Salle à tous le monde", "Ballasts", "Journal de production", "Traduction de TSBD", "Un cadavre dans un sac à main…") kept under their original names — rename/merge in the `ctso_forum` table if you prefer.
- **6 disabled users** were created for authors that only exist in old platforms (`Dr Schtroumpf`, `Frofro`, `Merryswingcool`, `Aline`, `Janna`, `Invité`) and 6 nickname aliases were merged by hand (e.g. "Shogûn" = Le Kilt, "nounou !" = Lea). Check the mapping list in the doc.
- 3 photos (#302, #400, #401) have no file in any archive; 77 private-message mirror copies, 2 empty rows and 1 message to an unknown account were skipped. Calendar events (32) and the old chat do not exist in any dump / have no counterpart.
- 5 weak near-duplicate matches (<90 % similarity) are listed in the doc for manual review.
- `app:content:sanitize` was run on everything (it also normalised `&#39;`/`&#64;`-style entities to plain characters so LIKE-search finds e-mail addresses and apostrophes). A full database dump from before is in `var/backup-before-sanitize.sql` (and `var/backup-before-reimport.sql`); delete them when you're satisfied.

## 4. Bugs found along the way (all with regression tests)

- `app:content:sanitize` looped forever on `ctso_forum` (string ids paged with `(int)`) — found when the real run hung; fixed + test.
- Sanitizer serialised `@ + = '` as numeric entities → searching an e-mail address found nothing; stored as plain characters now + test.
- Search highlighting ignored accents/case (the DB collation did not) and apostrophe spellings (`&#39;` vs `&#039;`) were not matched; fixed + tests.
- Disabled legacy accounts could log in; the checker message was English; fixed + tests.
- Agents found: duplicate imports from fuzzy matching (7 + 2 duplicates, matcher fixed + tests), Mercure authorization service wiring (named arguments broke container compile), debug-toolbar matching the chat layout selector.

## 5. Code quality assessment

Good: one place for sanitizing, explicit private-by-default security with tests proving it, idempotent importers keyed on `legacy_ref`, controllers thin enough to read, tests on behaviour rather than markup, semantic CSS tokens.
Weak spots / debt:

- **Search is `LIKE '%term%'`** on 26 k comments (fine now, ~tens of ms); move to a MariaDB FULLTEXT index if the data grows.
- **Thumbnails** are generated lazily; the first visit of a photo wall is slow (12-megapixel originals ≈ 1–2 s each with GD). `app:photos:warm` pre-generates them (it was still running when I wrote this, 91/2396 thumbs cached early on; it is safe to leave it, or to run again).
- Unread chat badge: existing members start at `chat_last_read_id = 0`, so their first badge shows 355 until they open `/chat`.
- The chat badge script opens one Mercure `EventSource` per page load plus two small requests (`/chat/state`).
- Editing forum descriptions/creating forums is not ported (admin edits the table); photo albums by member keep the legacy structure.
- YouTube playback could not be verified in the Playwright Chromium (error 153 with no `Referer`); the iframe markup is the standard nocookie embed with a referrer policy — please test once in a normal browser.
- Production image: the new `frontend_builder` stage builds (verified), but I did not build or deploy the full prod image; uploads need a persistent volume for `var/photos`, and `MERCURE_JWT_SECRET`/`APP_SECRET` must be real secrets (the dev values in `.env` are random but committed).
- `git`: nothing is committed; the work tree contains the whole migration. Review `git status`, then commit in logical slices (upgrade, security, importer, front-end, chat/editor).

## 6. Advice

1. Commit now, in slices; put `REGISTRATION_ENABLED`, `MERCURE_JWT_SECRET`, `APP_SECRET`, `CERT_RESOLVER` in `.env.local`/secrets.
2. Open the app as Vincent and skim: home digest, a 2005 thread, a thread with YouTube, a 2012 photo wall, `/gens`, PMs (unread badges from the legacy `state = NULL` rows).
3. Decide on the forum names/ordering of the 7 recovered forums and the disabled placeholder users.
4. Back up `var/photos/originals` (2.4 k files, outside git) — it is now the only copy besides the old servers.
5. Keep the rule "every bug gets a test" cheap: `AppWebTestCase` helpers make it ~10 lines.
