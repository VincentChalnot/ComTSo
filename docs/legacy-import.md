# Recovering the history older than the Symfony 2 forum (one-shot reimport)

Status: **done, imported into production on 2026-10-19; the tooling is single-use and slated for removal** (see [Removing the temporary tooling](#removing-the-temporary-tooling)).
This file is the record of what was imported, how, and what was lost. Member pseudonyms and the old site's host name are deliberately generalised here
("member A", "the old phpBB 2 site's `*.free.fr` host"). Decisions and accepted losses at a glance: `docs/decisions.md`.

The live database was built from the Symfony 2 platform (dump `2610110300.sql.gz`, command `app:legacy:import`). The forum is older than that: three earlier
platforms were found in `var/legacy/`. `app:legacy:reimport` compares them with the live database and imports **only what is missing**.

```bash
# 1. start the two temporary databases (they load the dumps on first start, ~30 s)
docker compose --profile legacy up -d legacy-mysql legacy-postgres

# 2. look first: per-source report, nothing is written (add -v for every row, -vv for title matches)
docker compose exec php bin/console app:legacy:reimport --dry-run

# 3. real run. Photos and smilies are files of the *old code trees*, which the php container does not see:
#    mount them read-only on a one-off container (traefik.enable=false keeps the one-off container out of Traefik)
docker compose exec -T database mariadb-dump -uroot -proot --single-transaction comtso > var/backup-before-reimport.sql   # rollback copy
docker compose run --rm --no-deps -T \
  -v <dir>/comtso.old:/legacy/comtso.old:ro \
  -v <dir>/comtso2.old:/legacy/comtso2.old:ro \
  -l traefik.enable=false php bin/console app:legacy:reimport -v

# 4. running it again must import nothing
docker compose exec php bin/console app:legacy:reimport --dry-run
```

Options: `--dry-run`, `--source=phpbb2|phpbb3|oldphp` (repeatable; order is always phpbb2, phpbb3, oldphp), `--tolerance=300` (seconds between two copies of a message),
`--oldphp-dir`, `--phpbb3-dir` (default to the mount points above; without them photos are reported but not copied and missing smilies are listed). The whole import runs in one transaction.

Order that works from scratch (this is also the production chain; production receives the RESULT as a DB dump + `originals/` archive, `deploy/README.md` "Legacy import"):
dump into the side database `comtso_legacy` -> migrations -> `app:legacy:import --force` (repairs double-encoded text, converts the photos) -> legacy databases up ->
`app:legacy:reimport` (one-off container with the two mounts) -> `app:photos:reprocess` -> `app:content:sanitize`. **`app:legacy:import --force` wipes the rows `app:legacy:reimport` added: run the reimport again afterwards.**

## What the three dumps contain

| Dump / platform | Period | Content |
| --- | --- | --- |
| `comtso.sql`, `phpbb_*` tables: **phpBB 2** (the old site's `*.free.fr` host) | 2004-04-30 to 2007-06 | 9 members, 8 forums in 3 categories, 135 topics (1 is a "moved" shadow), 3 891 posts, 45 private messages (each stored twice: inbox and sentbox) |
| `dump_..._full.sql`: **phpBB 3.0** (PostgreSQL) | 2007-06-23 to 2009-06 | 11 members (+52 bots), 3 forums, 91 topics, 4 244 posts, 115 private messages, 27 attachments |
| `comtso.sql`, other tables: **old self-written PHP forum** | 2007-06-23 to 2012-10 | 14 users, 254 topics, 13 956 posts, 404 messages, 298 quotes, 1 133 photos (54 albums), 32 calendar events |

The old PHP forum is the direct predecessor of the Symfony 2 site: its posts 1 to about 4 300 are the phpBB 3 posts copied as they were, and the Symfony 2 migration imported almost all of it.
phpBB 2 (2004-2007) was **never** migrated. No dump contains a chat or shoutbox table.

## Temporary services (dev `compose.yaml`, profile `legacy`)

* `legacy-mysql` (MariaDB 11.4, db `comtso_oldphp`): loads `comtso.sql` from `/docker-entrypoint-initdb.d`. The MySQL 5.7 dump loads as is (`NO_AUTO_VALUE_ON_ZERO` like in its header).
* `legacy-postgres` (PostgreSQL 16, db `comtso2`): the phpPgAdmin dump cannot be replayed verbatim. It runs `CREATE ROLE "comtso2" WITH SUPERUSER` and `ALTER DATABASE "comtso2"` (so the bootstrap
  user must not be called `comtso2` and the database must be), and its `CREATE OPERATOR` blocks for the `varchar_ci` domain use a syntax PostgreSQL rejects (with `\set ON_ERROR_STOP on` that fails the init).
  `docker/legacy/10-load-phpbb3.sh` strips those data-irrelevant blocks with `sed` and pipes the rest to `psql`.
* The command reads both through `LEGACY_OLDPHP_URL` / `LEGACY_PHPBB3_URL` (in the `php` service of `compose.yaml`); the connections are built with DBAL inside the command, the application's Doctrine configuration is untouched.
  These services exist only in the dev `compose.yaml`: never run the reimport in production.

## How "already present" is decided (never trust ids)

Existing rows have no `legacy_ref`, so a legacy message is looked up in the live database by **timestamp + normalised text** (`App\Legacy\Import\LiveIndex`, `TextNormalizer`):

* timestamps are Paris wall-clock digits on both sides (unix timestamps converted to `Europe/Paris`); tolerance +-300 s;
* the text key is the visible text lower-cased, entities decoded, typography unified, reduced to letters/digits, plus link *targets*, smilies through their alt text, quote headers removed, YouTube links reduced to the video id,
  photo links of all three URL generations reduced to "photo", the previous migration's `(Invalid img)` placeholder ignored and double-encoded UTF-8 repaired first;
* match classes: **exact**; **own text** (identical outside quotes); **near** (same author, same second, 85 % similar or more, at least 20 characters); **time-shifted** (identical long text, at most 36 h apart).
  Near matches of 90 % or more at the same second are the usual artefacts of the previous migration and are only counted; everything weaker, every time-shifted match and every author mismatch is printed under *Suspicious matches*.
  All count as "present": the command prefers a missing row to a duplicate;
* a live row is claimed once per source, and rows imported during the run only become matchable by the next source, so phpBB 3 and the old PHP forum (which share their first 4 000 posts) are never imported twice;
* topics are matched through their posts (majority vote), otherwise by title + date (+-2 days; the previous migration renamed album topics "Commentaires de l'album: X" to "X");
* private messages by (live sender, live recipient, time, body); quotes by text; photos by `original_filename` (`N.jpg`), because stored files are re-encoded and a content hash can no longer match;
* last guard: a row whose `legacy_ref` already exists is never inserted again.

**Idempotence and `legacy_ref` convention**: every imported row carries a unique `legacy_ref` (`phpbb2:post:42`, `phpbb3:message:12:54`, `oldphp:photo:40`, `phpbb3:attachment:<id>`, ...); existing ids are untouched.
The real run imported the numbers below, the immediate re-runs imported **0** rows in every table, and no imported comment shares author + second with an existing comment.

## Result (live database before and after, newest dump of the 2026-10-19 rehearsal; this is what went to production)

22 078 to 25 981 comments (+3 903), 367 to 501 topics (+134), 6 to 13 forums (+7), 12 to 18 users (+6 disabled, "Invité" included), 392 to 569 private messages (+177), quotes 348 (+0), photos 2 403 to 2 425 (+2 old-forum photos, +20 phpBB 3 attachments).

| Source | Imported | Not imported (reason) |
| --- | --- | --- |
| phpBB 2 | 134 topics, 3 891 posts, 45 private messages | nothing |
| phpBB 3 | 12 posts (11 posts of member A, an account the previous migration never created, plus one unexplained post), 115 private messages, 20 attachments | 1 orphan attachment, 6 damaged ones (below) |
| old PHP forum | 17 private messages, 2 photos (#40, #68) | 77 private-message mirror copies, 1 empty body, 1 message to an account that exists nowhere, 1 empty quote (#186), 3 photos with no file anywhere (#302, #400, #401) |

Where the 134 phpBB 2 topics went: `salle-a-tous-le-monde` 79, `cabinet-du-dr-schtroumph` +18, `conseil-des-ministres` 18, `bienvenue` 9, `traduction-de-tsbd` 5, `ballasts` 2, `journal-de-production` 2, `un-cadavre-dans-un-sac-a-main` 1.
Verified after the real run: no orphan comment/topic/message, no topic whose `comment_count` / `last_activity_at` differs from reality, no empty body, no BBCode left in any imported row, re-run = 0 imports.
5 weak near matches (below 90 %) still deserve a glance if ever needed: phpBB 3 posts 2165 and 1332 (live comments 2191 and 511: the live copy has a mangled character or was edited) and old-forum post 7177 (live 7077).

## Mapping decisions

* **Forums.** phpBB 3's three forums were all merged into `archives` by the previous migration: same here. The old PHP forum keeps its numbering (0 to `le-bac-a-sable`, 1 to `organisation`, 2 to `cabinet-du-dr-schtroumph`, 3 to `archives`, album topics to `albums`).
  phpBB 2 predates every live forum and the owner wanted 2004-2007 back *under the original names*: each old forum became a **new forum** (`bienvenue`, `conseil-des-ministres`, `salle-a-tous-le-monde`, `ballasts`, `journal-de-production`, `traduction-de-tsbd`,
  `un-cadavre-dans-un-sac-a-main`, descriptions kept, `ord` 10-16), except "Le cabinet du Dr Schtroumpf", which is the ancestor of the live `cabinet-du-dr-schtroumph` and joins it. Topics get `last_activity_at` = last comment (or creation),
  `comment_count` is recomputed for every touched topic, titles are cut to 128 characters. Moving a topic later is a one-line UPDATE of `ctso_topic.forum_id`.
* **Users.** Accounts are clustered across the four platforms and the live users: same normalised username (case/accents/punctuation ignored) or same e-mail, plus six aliases established from the dumps (same e-mail on another platform, or guest signatures
  matching a member). Accounts with no live counterpart get a **disabled** user (`enabled=0`, unusable password `!<random>`, e-mail `<name>@legacy.invalid`, `legacy_ref`), created lazily and only if imported content needs it:
  five named accounts (members A to E) and `Invité` for the 3 anonymous phpBB 2 posts + 1 topic with no usable name. Nothing was attributed to the owner by default.
* **Private messages** to `ctso_message` (`state` 4 = read, the dominant value of existing rows). The table has no subject column, so a subject (other than "Re: ..." and the old "Message Rapide") becomes a bold first line. phpBB 2 mailbox copies are collapsed;
  the old PHP forum's 77 `state = 5` rows are **mirror copies** (sender/recipient swapped) of another row and are not imported. Messages involving an account that exists in no dump are skipped.
* **Quotes** to `ctso_quote` by text (298 in the old forum, 297 already live, 1 empty). The dump has no date.
* **Photos**: the old forum's `photos/N.jpg` exists in `comtso.old/photos`; a photo is imported (the file goes through the photo pipeline, `AGENTS.md` "Photo pipeline") only when it is missing and the file really exists; row + `ctso_photo_topic` link to the album topic of its sibling photos.
* **Not representable**, left in the dumps: the 32 calendar events, `status`, user profiles of the old platforms, phpBB polls (9 in phpBB 2).
* Smilies: phpBB 2 text codes are mapped to the files `public/smilies` serves (phpBB 3 `icon_e_*` variants, custom `:love: :siffle: ...`); the only missing referenced file, `icon_arrow.gif`, was copied from the phpBB 3 tree.

## Formats found (unit-tested in `tests/Legacy/`)

| Platform | Stored form | Converter |
| --- | --- | --- |
| phpBB 2 | raw BBCode with a 10-character `bbcode_uid` on every tag, text smilies `:lol:`, entity-encoded text incl. `&#8230;` `&#8217;` and UTF-16 surrogate junk (dropped), real newlines, raw HTML allowed | `Phpbb2Converter` |
| phpBB 3.0 | BBCode with a 5-8 character uid, already-rendered smilies `<!-- s:) --><img src="{SMILIES_PATH}/...">` and magic links `<!-- m --><a class="postlink">`, `&#58;` `&#46;` in URLs, custom `[s]`/`[strike]`, attachments `[attachment=0:uid]...`; **not** the XML `<r><t>` of phpBB 3.1+ | `Phpbb3Converter` |
| old PHP forum | three generations in one column: 2007-08 phpBB 3 posts pasted as `nl2br()` text **with BBCode and uids left raw** and **double-encoded UTF-8** (2007-2009 and the user/title columns); 2009-2012 TinyMCE HTML (`image.php?photo=610.jpg` photo links); messages/quotes HTML or plain text with entities | `OldPhpConverter` (+ `TextRepair::fixMojibake`, `PhotoLinks`) |

Output is the house style: `<p>` per paragraph, `<br>` inside, `<strong>/<em>/<u>/<del>`, `<blockquote><cite>X a écrit :</cite>...`, `<pre><code>`, lists, `<a href>` (http/https/mailto), `/smilies/` images, remote `[img]` kept as `<img>`,
a YouTube link alone on its line (or `[youtube]`) becomes a `youtube-nocookie.com/embed/ID` iframe; colours, sizes and alignment are dropped, unknown tags removed. Every body then goes through `ContentSanitizer::sanitize()`.
Inline attachments leave `[pièce jointe : name.jpg]` in italics.

## Suspicious things found in the Symfony 2 migration

* **Lost** by the previous migration: 11 posts of member A and 17 private messages (to/from five accounts that were never created); the 2007-2009 phpBB 3 private messages (115) and the phpBB 2 ones (45); 2 photos of albums deleted before that migration (#40, #68) and 3 whose files were already gone. All recovered except the 3 files.
* **Garbled text in 28 existing comments, 2 topic titles and 1 quote** (double-encoded UTF-8 that the previous migration did not repair): repaired by `app:legacy:import`, see below. Other altered characters (`KAWAÏÏÏ` to `KAWAíííí`, comment 511) stay as they are.
* **Old BBCode converted badly** (left as is): `&amp;amp;quot;` in 100 comments, a glued "Citation de" header in 117, a literal `(Invalid img)` in 71, `[url=...]label[/url]` replaced by the address; 12 comments showed a raw `[attachment=0]` marker (the attachments are recovered below).
* **68 comments link to `/photos/preview/N.jpg` / `/photos/thumbnail/N.jpg`** (Symfony 2 routes; N = the old forum's photo number = `ctso_photo.original_filename` `N.jpg`). The links are left as written and kept alive by `LegacyRedirectController`:
  members get a 301 to `/photos/{id}/medium` / `/thumb` of the oldest photo named `N.jpg` (all 67 distinct numbers resolved); anonymous visitors go to the login first. Imported rows already use the new routes (`PhotoLinks`).
* No month is empty between 2007-06 and 2026-10 except 2022-08, 2023-05 and 2023-06 (genuinely quiet). 34 old `N.jpg` files differ byte-wise from the stored copy of the same photo (the previous migration re-encoded them); not touched.

## Owner's decisions (applied in the 2026-10-19 rehearsal and in production)

1. **Forum layout for 2004-2007**: original names kept, one new forum per old forum (7 new forums, "Dr Schtroumpf" merged into the existing one).
2. Photos #40 and #68 belonged to **albums that had been deleted**: imported **without album** (visible in `/photos`).
3. The 28 garbled comments and 2 titles are repaired.
4. phpBB 3 attachments are imported (below). The 32 calendar events stay in the dumps.
5. Old accounts stay **disabled** (random unusable password). Copied from the old platforms: user name, **e-mail address** (the one of the most recent platform; a `<name>@legacy.invalid` placeholder only when no valid, unused address is known, "Invité" always), earliest registration date.

## Garbled text repair

Cause: the Symfony 2 migration copied the text of the old PHP forum without decoding a double UTF-8 encoding (each UTF-8 byte read as ISO-8859-1/CP1252 and encoded again): `ê` stored as `Ãª`, a no-break space as `Â` + NBSP, `...` as `â€¦`.
`app:legacy:import` runs `MojibakeRepairer` right after the 1:1 copy: for every text column it fetches only rows matching `Ã|Â|Å|[à-ï]`, applies `TextRepair::fixMojibake()` (which only touches a string whose bytes round-trip to valid UTF-8,
so genuine "é NBSP" typography is left alone), removes a leading BOM, and rewrites **only changed rows with plain SQL** (`updated_at` untouched). Idempotent. Result: 31 rows.
Caveat: comment 842 (a Chinese quote) is only partly recoverable (two characters lost bytes in the dump); the 27 other comments are fully repaired.

## phpBB 3 attachments

The 27 `phpbb3_attachments` rows are read from the PostgreSQL dump, files from `comtso2.old/phpBB3/files/<physical_filename>`. `AttachmentImporter` (in `app:legacy:reimport`) matches each attachment to the live comment of its post, runs the file through the photo pipeline,
stores it as a photo (`legacy_ref = phpbb3:attachment:<id>`, title = attachment comment, author = poster, date = upload date) attached through `ctso_comment_photo`, and removes the raw marker from the text (a lost file leaves `[pièce jointe perdue : name]`).

The files were damaged when copied long ago (FTP in text mode: every CR before an LF was dropped). Result, by attachment number (`app:legacy:reimport -v` prints the list with paths):

* **20 imported**: 9 byte-identical to what phpBB recorded (#6, 7, 8, 15, 16, 17, 18, 19, 38); #3 with one byte lost and its PNG signature repaired; **10 a few bytes shorter but decodable, to be checked by eye (#9, 10, 12, 14, 20, 27, 28, 29, 32, 37)**.
* **6 refused, accepted loss** (the decoder cannot read them): #2, 4, 5, 36 (damaged image data), #11, 13 (broken JPEG header).
* 1 orphan (#1, never attached to a post, same file as #2), not imported.

No undamaged copy exists: no file of the recorded size was found in either old code tree, in `upload/` / `files/repaired/`, nor in the NAS photo originals.

## Photos and EXIF

* The Symfony 2 forum kept EXIF (`ctso_photo.jsonExif`/`exif`, ExifTool output): `app:legacy:import` normalised it into the `exif` JSON column and copied `taken_at`; there were no GPS columns. 2 194 of the 2 396 photos carry EXIF. The old PHP forum and phpBB platforms stored none.
* Every photo file the importers copy goes through `App\Photo\PhotoPipeline`: EXIF re-read from the original into `taken_at`, `gps_*` and the curated `exif` map (the raw ExifTool dump with server paths is dropped), rotated, capped at 10 MP, stored as WebP.
* A library restored with raw JPEGs is migrated in place with `app:photos:reprocess` (`--dry-run`, `--limit`; idempotent; about one to two seconds per 12-megapixel photo: run it on the dev machine, in `tmux`, then `app:photos:warm`).

## Rollback

`var/backup-before-reimport.sql` (full `mariadb-dump` taken before the first run) restores the previous state. To undo *only* this import (rows are identified by their `legacy_ref`):

```sql
START TRANSACTION;
CREATE TEMPORARY TABLE touched AS SELECT DISTINCT c.topic_id FROM ctso_comment c JOIN ctso_topic t ON t.id = c.topic_id
  WHERE c.legacy_ref REGEXP '^(phpbb2|phpbb3|oldphp):' AND t.legacy_ref IS NULL;
DELETE FROM ctso_comment WHERE legacy_ref REGEXP '^(phpbb2|phpbb3|oldphp):';
DELETE FROM ctso_topic   WHERE legacy_ref REGEXP '^(phpbb2|phpbb3|oldphp):';
DELETE FROM ctso_message WHERE legacy_ref REGEXP '^(phpbb2|phpbb3|oldphp):';
DELETE FROM ctso_quote   WHERE legacy_ref REGEXP '^(phpbb2|phpbb3|oldphp):';
DELETE FROM ctso_photo_topic WHERE photo_id IN (SELECT id FROM ctso_photo WHERE legacy_ref REGEXP '^(phpbb2|phpbb3|oldphp):');
DELETE FROM ctso_comment_photo WHERE photo_id IN (SELECT id FROM ctso_photo WHERE legacy_ref REGEXP '^(phpbb2|phpbb3|oldphp):');
DELETE FROM ctso_photo   WHERE legacy_ref REGEXP '^(phpbb2|phpbb3|oldphp):';          -- then delete the 22 files from var/photos/originals
DELETE FROM fos_user     WHERE legacy_ref REGEXP '^(phpbb2|phpbb3|oldphp):|^legacy:guest$';
DELETE FROM ctso_forum   WHERE id IN ('bienvenue','conseil-des-ministres','salle-a-tous-le-monde','ballasts','journal-de-production','traduction-de-tsbd','un-cadavre-dans-un-sac-a-main');
UPDATE ctso_topic t SET t.comment_count = (SELECT COUNT(*) FROM ctso_comment c WHERE c.topic_id = t.id),
  t.last_activity_at = COALESCE((SELECT MAX(c.created_at) FROM ctso_comment c WHERE c.topic_id = t.id), t.created_at)
 WHERE t.id IN (SELECT topic_id FROM touched);
COMMIT;
```

For production, the rollback is the data restore of `deploy/restore.sh` from a backup taken before (`docs/operations.md`), not this SQL: the SQL is for the dev database.

## Removing the temporary tooling

Once the result is accepted (it is single-use; the `legacy_ref` columns and the imported rows stay; `app:legacy:import` is a separate command and is not part of this list):

```bash
docker compose --profile legacy down -v                     # stops and deletes the two legacy databases
git rm -r src/Legacy src/Command/ReimportLegacyCommand.php tests/Legacy docker/legacy
# compose.yaml: delete the `legacy-mysql` and `legacy-postgres` services (and the comment above them)
# compose.yaml: also delete the `LEGACY_*` entries of the `php` service environment
# docs/: keep this file as the record; AGENTS.md "Legacy data" paragraph: drop the reimport sentence
rm var/backup-before-reimport.sql var/reimport-*.log        # when the rollback copy is no longer needed
```

Comments in `src/Controller/LegacyRedirectController.php` and `compose.yaml` point to this file: keep the file, or update them if it is ever renamed.
`var/legacy/*.sql` (the dumps) are git-ignored with the whole `var/` directory; keep them somewhere safe.
