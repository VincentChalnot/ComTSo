# Recovering the history older than the Symfony 2 forum (one-shot reimport)

The live database was built from the Symfony 2 platform (`var/2610110300.sql.gz`, command `app:legacy:import`). The forum is
older than that: three earlier platforms were found in `var/legacy/`. `app:legacy:reimport` compares them with the live
database and imports **only what is missing**. It is a single-use tool: once the owner is satisfied, remove it
(see [Removing the temporary tooling](#removing-the-temporary-tooling)).

```bash
# 1. start the two temporary databases (they load the dumps on first start, ~30 s)
docker compose --profile legacy up -d legacy-mysql legacy-postgres

# 2. look first: per-source report, nothing is written (add -v for every row, -vv for title matches)
docker compose exec php bin/console app:legacy:reimport --dry-run

# 3. real run. Photos and smilies are files of the *old code trees*, which the php container does not see:
#    mount them read-only on a one-off container (traefik.enable=false keeps the one-off container out of Traefik)
docker compose exec -T database mariadb-dump -uroot -proot --single-transaction comtso > var/backup-before-reimport.sql   # rollback copy
docker compose run --rm --no-deps -T \
  -v /home/vincent/www/comtso.old:/legacy/comtso.old:ro \
  -v /home/vincent/www/comtso2.old:/legacy/comtso2.old:ro \
  -l traefik.enable=false php bin/console app:legacy:reimport -v

# 4. running it again must import nothing
docker compose exec php bin/console app:legacy:reimport --dry-run
```

Options: `--dry-run`, `--source=phpbb2|phpbb3|oldphp` (repeatable; order is always phpbb2 → phpbb3 → oldphp),
`--tolerance=300` (seconds between two copies of a message), `--oldphp-dir`, `--phpbb3-dir` (default to the mount points above;
without them photos are reported but not copied and missing smilies are listed). The whole import runs in one transaction.

## What the three dumps contain

| Dump / platform | Period | Content |
| --- | --- | --- |
| `comtso.sql`, `phpbb_*` tables: **phpBB 2** (comtso.free.fr) | 2004-04-30 → 2007-06 (3 posts of 2008-09) | 9 members, 8 forums in 3 categories, 135 topics (1 is a "moved" shadow), 3 891 posts, 90 private-message rows (= 45 messages, every message is stored once in the inbox and once in the sentbox) |
| `dump_..._full.sql`: **phpBB 3.0** (PostgreSQL) | 2007-06-23 → 2009-06 | 11 members (+52 bots), 3 forums, 91 topics, 4 244 posts, 115 private messages, 27 attachments |
| `comtso.sql`, other tables: **old self-written PHP forum** | 2007-06-23 → 2012-10 | 14 users, 254 topics, 13 956 posts, 404 messages, 298 quotes, 1 133 photos (54 albums), 32 calendar events |

The old PHP forum is the direct predecessor of the Symfony 2 site: its posts 1 → ~4 300 are the phpBB 3 posts copied as they were,
and the Symfony 2 migration imported almost all of it. phpBB 2 (2004-2007) was **never** migrated.
There is no chat / shoutbox table in any dump (nothing to put in `ctso_chat_message`).

## Services (dev `compose.yaml`, profile `legacy`)

* `legacy-mysql` (MariaDB 11.4, db `comtso_oldphp`, root/root): loads `comtso.sql` through `/docker-entrypoint-initdb.d` (read-only mount).
  The MySQL 5.7 dump loads as is (MyISAM tables are fine, `sql_mode=NO_AUTO_VALUE_ON_ZERO` set like in the dump header).
* `legacy-postgres` (PostgreSQL 16, db **`comtso2`**, user `postgres`): the phpPgAdmin dump cannot be replayed verbatim:
  * it runs `CREATE ROLE "comtso2" WITH SUPERUSER` and `ALTER DATABASE "comtso2"`, so the bootstrap user must *not* be called
    `comtso2` and the database *must* be;
  * its `CREATE OPERATOR` blocks for the `varchar_ci` domain use a syntax PostgreSQL rejects (`COMMUTATOR = <(varchar_ci,varchar_ci)`),
    and `\set ON_ERROR_STOP on` turns that into a failed init. `docker/legacy/10-load-phpbb3.sh` strips these (data-irrelevant)
    blocks with `sed` and pipes the rest to `psql`; the dump itself is mounted read-only at `/legacy/phpbb3.sql`.
* The command reads both through `LEGACY_OLDPHP_URL` / `LEGACY_PHPBB3_URL` (set in the `php` service of `compose.yaml`, no `.env` involved); the connections are built with DBAL inside the command,
  nothing was added to the application's Doctrine configuration.

## How "already present" is decided (never trust ids)

Existing rows have no `legacy_ref`, so a legacy message is looked up in the live database by **timestamp + normalised text**
(`App\Legacy\Import\LiveIndex`, `TextNormalizer`):

* timestamps are Paris wall-clock digits on both sides (unix timestamps are converted to `Europe/Paris`); tolerance ±300 s;
* the text key is the visible text lower-cased, entities decoded, typography unified (’ → ', … → ..., « » → "), reduced to letters/digits, plus the
  link *targets* (not their labels), smilies through their alt text, quote headers removed, YouTube links reduced to the video id,
  photo links of all three URL generations reduced to "photo", the previous migration's `(Invalid img)` placeholder and
  the double-encoded UTF-8 of the old PHP dump (`prÃªt`) repaired first;
* match classes: **exact**; **own text** (identical outside quotes); **near** (same author, same second, ≥ 85 % similar, ≥ 20 characters);
  **time-shifted** (identical long text, ≤ 36 h apart). Near matches ≥ 90 % at the same second are the usual artefacts of the previous
  migration (stray `n` from `\n`, `Ï` turned into `í`, a link cut at a comma) and are only counted ("fuzzy matches accepted", `-v` lists
  them); everything weaker, every time-shifted match and every author mismatch is printed under *Suspicious matches*. All of them count as
  "present": the command prefers a missing row to a duplicate;
* a live row is claimed once per source (two identical double-posts need two live rows) and rows imported during the run only become
  matchable by the next source, so phpBB 3 and the old PHP forum, which share their first 4 000 posts, are never imported twice;
* topics are matched through their posts (majority vote of the live topic of the matched posts), otherwise by title + date (±2 days; the
  previous migration renamed the album topics "Commentaires de l'album: X" → "X");
* private messages by (live sender, live recipient, time, body — with or without the subject line); quotes by text;
  photos by `original_filename` (`N.jpg`) (stored files are re-encoded by the photo pipeline, so a content hash can no longer match);
* last guard: a row whose `legacy_ref` already exists is never inserted again.

Verified: the real run imported the numbers below, the immediate re-run imported **0** rows in every table, and no imported comment shares
author + second with an existing comment (SQL check).

## Mapping decisions

* **Forums.** phpBB 3's three forums were all merged into `archives` by the previous migration: same here. The old PHP forum keeps its
  numbering (0 → `le-bac-a-sable`, 1 → `organisation`, 2 → `cabinet-du-dr-schtroumph`, 3 → `archives`, album topics → `albums`).
  phpBB 2 predates every live forum and the owner wants 2004-2007 back *under the original names*: each old forum became a **new forum**
  (`bienvenue` "Bienvenue !", `conseil-des-ministres`, `salle-a-tous-le-monde` "Salle à tous le monde", `ballasts`, `journal-de-production`,
  `traduction-de-tsbd`, `un-cadavre-dans-un-sac-a-main`, descriptions kept, `ord` 10-16), except "Le cabinet du Dr Schtroumpf", which is the
  ancestor of the live `cabinet-du-dr-schtroumph` and joins it (a second "Dr Schtroumpf" forum would be confusing). Topics get
  `last_activity_at` = last comment (or creation), `comment_count` is recomputed for every touched topic, titles are cut to 128 characters.
  Moving a topic to another forum later is a one-line UPDATE of `ctso_topic.forum_id`.
* **Users.** Accounts are clustered across the four platforms and the live users: same normalised username (case/accents/punctuation
  ignored) or same e-mail, plus six aliases established from the dumps (`Shogûn` = `Le Kilt` [same e-mail on phpBB 3], `nounou !` = `Lea`, `Jonas` =
  `Le Djo`, `Hiko` = `rozenn` [same e-mails], guests signing `Vince` / `Vincent C.` = `Vincent`). `Mimi (pas logée)` is `Mimi`. The old PHP forum lost
  user 61 (Frofro) but its ids are phpBB 3's, so it is resolved there. Accounts with no live counterpart get a **disabled** user (`enabled=0`,
  unusable password `!<random>`, e-mail `<name>@legacy.invalid`, `legacy_ref`), created lazily, only if imported content needs it:
  `Dr Schtroumpf` (phpBB 2 + 3), `Frofro`, `Merryswingcool`, `Aline`, `Janna`, and `Invité` for the 3 anonymous phpBB 2 posts + 1 topic
  with no usable name. Every live author of the old posts is therefore known; nothing was attributed to "Vincent" by default.
* **Private messages** → `ctso_message` (`state` 4 = read, the dominant value of the existing rows). The table has no subject column, so a subject
  (other than "Re: …" and the old "Message Rapide") becomes a bold first line. phpBB 2 mailbox copies are collapsed; the old PHP forum's 77
  `state = 5` rows are **mirror copies** (sender/recipient swapped) of another row in the table (checked for all 77) and are not imported.
  Messages involving an account that exists in no dump (user 69) are skipped.
* **Quotes** → `ctso_quote` by text (298 in the old forum, 297 already live, 1 is empty). No date exists in the dump: new rows would get the
  import time (none needed).
* **Photos**: the old forum's `photos/N.jpg` exists in `comtso.old/photos`; a photo is imported (the file goes through the photo pipeline — EXIF read into `taken_at`/GPS/`exif`, orientation applied, ≤ 10 MP, stored as `uniqid().webp` in
  `var/photos/originals`; see AGENTS.md —, row + `ctso_photo_topic` link to the album topic of its sibling photos) only when it is missing and the file really exists.
* **Not representable**: the 32 calendar `events` (no table/feature in the new app), `status`, user profiles of the old platforms, phpBB polls (9 in
  phpBB 2, no polls in the new app) and phpBB 3 attachments (see below). They stay in the dumps.
* Smilies: phpBB 2 text codes are mapped to the files `public/smilies` already serves (phpBB 3 `icon_e_*` variants, custom `:love: :siffle: …`).
  The only referenced file that was missing, `icon_arrow.gif`, was copied from the phpBB 3 tree (never overwriting).

## Formats found (verified on samples, unit-tested with them in `tests/Legacy/`)

| Platform | Stored form | Converter |
| --- | --- | --- |
| phpBB 2 | raw BBCode with a 10-character `bbcode_uid` on every tag (`[quote:529f7b77c5="nounou !"]`, `[code:1:uid]`), text smilies `:lol:`, entity-encoded text incl. `&#8230;` `&#8217;` (characters outside latin1) and UTF-16 surrogate junk (`&#56319;&#57065;`, dropped), real newlines, raw HTML allowed (`enable_html=1`: `<a>`, `<table>`) | `Phpbb2Converter` |
| phpBB 3.0 | BBCode with a 5-8 character uid (`[quote=&quot;gigi&quot;:81487]`), already-rendered smilies `<!-- s:) --><img src="{SMILIES_PATH}/…"><!-- s:) -->` and magic links `<!-- m --><a class="postlink">`, `&#58;` `&#46;` in URLs, custom `[s]`/`[strike]`, attachments `[attachment=0:uid]<!-- ia0 -->x.jpg<!-- ia0 -->[/attachment:uid]`; **not** the XML `<r><t>` of phpBB 3.1+ | `Phpbb3Converter` |
| old PHP forum | three generations in one column: 2007-08 phpBB 3 posts pasted as `nl2br()` text (`<br />\n`, `<img src="images/smilies/…">`) **with the BBCode and uids left raw** and **double-encoded UTF-8** (`prÃªt`, `â€¦`, only 2007-2009 and the user/title columns); 2009-2012 TinyMCE HTML (`<p>`, `&eacute;`, `image.php?photo=610.jpg` photo links); messages/quotes: HTML or plain text with entities | `OldPhpConverter` (+ `TextRepair::fixMojibake`, `PhotoLinks`) |

Output is the house style: `<p>` per paragraph, `<br>` inside, `<strong>/<em>/<u>/<del>`, `<blockquote><cite>X a écrit :</cite>…`, `<pre><code>`, lists,
`<a href>` (http/https/mailto only), `/smilies/` images, remote `[img]` kept as `<img>`, a YouTube link alone on its line (or `[youtube]`) becomes a
`youtube-nocookie.com/embed/ID` iframe, colours/sizes/alignment are dropped, unknown or stray tags are removed rather than shown. Every body then goes
through `ContentSanitizer::sanitize()` (the sanitizer writes `'` as `&#039;`, which renders normally). Inline attachments leave
`[pièce jointe : name.jpg]` in italics (see "phpBB 3 attachments" for the files themselves). After conversion no BBCode tag is left in any imported row (checked in SQL).

## Results

Live database before → after (rehearsal of 2026-10-19 on the newest dump, `2610190739.sql.gz`): 22 078 → 25 981 comments (+3 903 imported), 367 → 501 topics (+134), 6 → 13 forums (+7),
12 → 18 users (+6 disabled, "Invité" included), 392 → 569 messages (+177), quotes 348 (+0), photos 2 403 → 2 425 (+2 old-forum photos, +20 phpBB 3 attachments).
All imported rows carry a `legacy_ref` (`phpbb2:post:42`, `phpbb3:message:12:54`, `oldphp:photo:40`, …); existing ids are untouched.

| Source / table | total | already present | imported | skipped (reason) |
| --- | ---: | ---: | ---: | --- |
| phpBB 2 topics | 134 | 0 | **134** | – |
| phpBB 2 posts | 3 891 | 0 | **3 891** | – |
| phpBB 2 private messages | 45 | 0 | **45** | – |
| phpBB 3 topics | 91 | 91 | 0 | – |
| phpBB 3 posts | 4 244 | 4 232 | **12** | – |
| phpBB 3 private messages | 115 | 0 | **115** | – |
| old PHP forum topics | 254 | 254 | 0 | – |
| old PHP forum posts | 13 956 | 13 956 | 0 (the phpBB 3 recoveries above already cover them) | – |
| old PHP forum private messages | 404 | 308 | **17** | 77 mirror copies, 1 empty body, 1 to an account that exists nowhere |
| old PHP forum quotes | 298 | 297 | 0 | 1 empty (#186) |
| old PHP forum photos | 1 133 | 1 128 | **2** (#40, #68) | 3 lost: #302, #400, #401 have no file anywhere |
| phpBB 3 attachments | 27 | 0 | **20** (photos of their message) | 1 orphan, 6 damaged files (list below) |

("present" includes ~280 phpBB 3 and ~750 old-forum rows matched fuzzily, see above. The table is the state of the final run from a clean database; see the
history at the end of "Rollback" for the matching fixes made on the way.)

The 12 recovered phpBB 3 posts: the **11 posts of Frofro** (an account the previous migration did not create; messages of 2008-02/03 in "Bienvenue à
Frofro !", "Besac KIKÉLA", "Été 2008"…) and one plain post of 2007-11-11 20:57 ("re-test ?", topic "MAJ_OK_OU_PRESQUE", unexplained). The 5 matches below 90 %
that still deserve a glance: phpBB 3 posts 2165 and 1332 (live #2191, #511: the live copy has a mangled character or was edited), old-forum post 7177 (live #7077).

Where the 134 phpBB 2 topics went: `salle-a-tous-le-monde` 79, `cabinet-du-dr-schtroumph` +18, `conseil-des-ministres` 18, `bienvenue` 9, `traduction-de-tsbd` 5,
`ballasts` 2, `journal-de-production` 2, `un-cadavre-dans-un-sac-a-main` 1.

Verification after the real run: no orphan comment/topic/message, 0 topics whose `comment_count` / `last_activity_at` differ from reality (all topics,
not only the touched ones), 0 empty bodies, 0 BBCode leftovers, re-run = 0 imports (run twice after the real one), `tests/Legacy` green. Spot checks of rendered rows (phpBB 2 posts
2320 and 2329 with YouTube embeds, 124 with a quote, 19 with a table, 1568 with entities; phpBB 3 posts 103, 1194 with quotes, 2404 with a link; PMs
phpbb3:1:2, phpbb2:26, oldphp:6) looked right.

## Suspicious things found in the Symfony 2 migration (nothing was modified, only reported)

* **Lost**: the 11 posts of Frofro and 17 private messages (to/from accounts 53 Dr Schtroumpf, 61 Frofro, 64 Merryswingcool, 67 Aline, 68 Janna) — the
  accounts themselves were not created; the live database had no message of the 2007-2009 phpBB 3 period (115) nor of phpBB 2 (45); 2 photos of albums that were deleted before the
  migration (#40, #68) and 3 whose files were already gone.
* **Garbled text left in 28 existing comments** (double-encoded UTF-8 that the previous migration did not repair; `Â` + no-break space, `NoÃ«l`, `èª°…` Chinese, a BOM `ï»¿`) and
  2 topic titles (#29 "Noël !", #87) and 1 quote (BOM): comments `440,463,615,839,842,868,2949,3178,5231,5232,9652,9875,9970,10294,10304,11043,11211,11309,11314,11431,11853,15566,16129,17051,18240,18494,19961,20222`.
  **Repaired by `app:legacy:import`** (`MojibakeRepairer`, 31 rows) — see "Garbled text repair" below. Other characters were altered (`KAWAÏÏÏ` → `KAWAíííí`, comment 511) and stay as they are.
* **Markup of the old BBCode converted badly**: 100 comments contain `&amp;amp;quot;`, 117 a "Citation de &quot;x&quot;" header glued into a `<blockquote>`;
  71 comments contain the literal `(Invalid img)` where a remote image used to be; `[url=…]label[/url]` lost its label (replaced by the address);
  12 comments showed raw `[attachment=0]file.jpg[/attachment]`: the 27 phpBB 3 attachments (files in `comtso2.old/phpBB3/files/`) were never migrated;
  `app:legacy:reimport` now recovers them (below).
* **68 comments link to `/photos/preview/N.jpg` / `/photos/thumbnail/N.jpg`** (Symfony 2 routes, N being the old forum's photo number = `ctso_photo.original_filename` `N.jpg`).
  The links are left as written and kept alive by `LegacyRedirectController`: members get a 301 to `/photos/{id}/medium` / `/photos/{id}/thumb` of the oldest photo named `N.jpg`
  (the imported one wins over a later upload with the same name; 67 distinct numbers in the dev data, all resolved); anonymous visitors go to the login first.
  Imported rows already use the new routes (`PhotoLinks`).
* No month is empty between 2007-06 and 2026-10 except 2022-08, 2023-05 and 2023-06 (genuinely quiet, nothing in the sources covers them); yearly counts of the
  old PHP forum equal the live ones (2007: 1 371, 2009: 3 256, 2010: 4 682, 2011: 2 141) except the missing Frofro posts in 2008 (1 891 vs 1 880).
* Photo originals: 34 old `N.jpg` files differ byte-wise from the stored copy of the same photo (the previous migration re-encoded them); not touched.

## Owner's decisions (all applied in the 2026-10-19 rehearsal)

1. **Forum layout for 2004-2007**: the original names are kept, one new forum per old forum (7 new forums, "Dr Schtroumpf" merged into the existing one).
2. Photos #40 "joli" (gigi) and #68 "été 2008" (nounou) belonged to **albums that had been deleted**: they stay imported **without album** (visible in `/photos`).
   (The dump itself holds 131 other photos that are in no album; the 20 attachment photos below are in none either.)
3. The 28 garbled comments / 2 titles are repaired, see below.
4. phpBB 3 attachments are imported, see below. The 32 calendar events stay in the dumps.
5. Old accounts stay **disabled** (random unusable password). What the old platforms knew is copied: user name, **e-mail address** (the one of the most recent platform; a
   `<name>@legacy.invalid` placeholder only when no valid, unused address is known — "Invité" always), earliest registration date. "Invité" collects the anonymous posts.

## Garbled text repair

Cause: the Symfony 2 migration copied the text of the old PHP forum without decoding a double UTF-8 encoding (each UTF-8 byte had been read as ISO-8859-1/CP1252 and encoded again), so
`ê` was stored as `Ãª`, a no-break space as `Â` + NBSP, `…` as `â€¦`. `app:legacy:import` now runs `MojibakeRepairer` right after the 1:1 copy: for every text column it fetches
only the rows matching `Ã|Â|Å|[à-ï]`, applies `TextRepair::fixMojibake()` (which only touches a string whose bytes round-trip to valid UTF-8, so « é NBSP » typography is left alone),
removes a leading BOM, and rewrites **only changed rows with plain SQL** (`updated_at` untouched). Idempotent. Result on the 2610190739 dump: **31 rows** (28 comments, topics 29 and 87, quote 109).
Caveat: comment 842 (the Chinese quote) is only partly recoverable — two characters lost bytes in the dump (`ç¡`, `çŠ`), the rest reads correctly. The 27 other comments are fully repaired.

## phpBB 3 attachments

The 27 `phpbb3_attachments` rows are read from the PostgreSQL dump; files live in `comtso2.old/phpBB3/files/<physical_filename>`. `app:legacy:reimport` (`AttachmentImporter`) matches each attachment to the
live comment of its post, runs the file through the photo pipeline (EXIF, WebP, 10 MP cap), stores it as a photo (`legacy_ref = phpbb3:attachment:<id>`, title = attachment comment, original name kept,
author = poster, date = upload date) attached to the comment through `ctso_comment_photo`, and **removes the raw marker** from the text (a lost file leaves `[pièce jointe perdue : name]`). The live comment
keeps the post's `legacy_ref` so that a second run cannot insert the post again; `TextNormalizer` ignores attachment markers in every form for the same reason.

The files were damaged when they were copied (FTP in text mode: every CR before an LF was dropped). Result: **20 imported** (9 byte-identical to what phpBB recorded, 10 a few bytes shorter but
decodable — check them by eye —, 1 PNG whose signature byte was restored), **6 refused** because the decoder cannot read them, 1 orphan (never attached to a post). The exact list with paths is
printed by `app:legacy:reimport -v` ("phpbb3 attachments imported / not imported").

| # | Original name | File in `comtso2.old/phpBB3/files/` | Size recorded → on disk | phpBB post → live comment | State |
| ---: | --- | --- | --- | --- | --- |
| 1 | Image 2.png | `54_ba10d46009c38f6641c8aa9de535d280` | 89308 → 89297 | 0 → – | **orphan** (never posted; same file as #2), not imported |
| 2 | Image 2.png | `54_67784fe2f276033adc961284d222954e` | 89308 → 89297 | 6 → #3 | **NOT imported**: undecodable (PNG body damaged) |
| 3 | comt le retour ton cul.png | `54_4860fe39a15976711bba9a9a2f6f9a65` | 5018 → 5017 | 73 → #72 | imported, 1 byte(s) lost, PNG signature repaired |
| 4 | london_0002.jpg | `58_c55f1d1801a2bb9d4a9750ca9bc60cf3` | 642570 → 642553 | 83 → #83 | **NOT imported**: undecodable (JPEG data damaged) |
| 5 | DSCN0211.JPG | `58_4f75c61bbe6cdc7d62a7ea50d33cfb78` | 765943 → 765921 | 84 → #84 | **NOT imported**: undecodable (JPEG data damaged) |
| 6 | DSCF3103.JPG | `54_83274b0ef5459a8bc9d955766691bdae` | 23821 → 23821 | 130 → #133 | imported, intact |
| 7 | DSCF2930.JPG | `54_066b0a3412a2f839ea9ce504debfe9bd` | 21183 → 21183 | 130 → #133 | imported, intact |
| 8 | Photo 3.jpg | `2_2c26a9bee2c01f6a7c415f7694a1427d` | 82090 → 82090 | 149 → #157 | imported, intact |
| 9 | Photo 9.jpg | `2_662c3464a1481edea777948ee95234d0` | 87899 → 87898 | 322 → #341 | imported, 1 byte(s) lost (check it by eye) |
| 10 | Photo 11.jpg | `2_f35999150e3f253490b92388f17fe6bc` | 86363 → 86361 | 322 → #341 | imported, 2 byte(s) lost (check it by eye) |
| 11 | le manoir.jpg | `2_69e3ccf6345fa0f3b3b1f304e2d2a2bb` | 111242 → 111233 | 350 → #371 | **NOT imported**: broken JPEG header (EXIF segment shifted) |
| 12 | moi cheuveux courts 1.jpg | `54_1d71084274af462dfdacbe238bbce5e4` | 962660 → 962650 | 482 → #514 | imported, 10 byte(s) lost (check it by eye) |
| 13 | cat_gun.jpg | `2_57fef8297f1beed5add7d4e90e130802` | 77358 → 77354 | 1300 → #1229 | **NOT imported**: broken JPEG header |
| 14 | Mushin2.jpg | `2_ba92c39574b5af8208db0ec6e9d2045e` | 217375 → 217362 | 1897 → #1919 | imported, 13 byte(s) lost (check it by eye) |
| 15 | solo.jpg | `2_955ae61c3b87493d065cee7210b56c7c` | 3005 → 3005 | 2128 → #2142 | imported, intact |
| 16 | cyberhead.jpg | `2_7ae4f2984e26ca281bfcdb280a267898` | 4022 → 4022 | 2128 → #2142 | imported, intact |
| 17 | finalfantasy.jpg | `2_bfb8ed0aa19e24bf2a7c6d497154b621` | 7649 → 7649 | 2128 → #2142 | imported, intact |
| 18 | oeil.jpg | `2_15736ad5fa036cccbf4cb5401cb405da` | 4326 → 4326 | 2133 → #2154 | imported, intact |
| 19 | fille.jpg | `2_0d976f31f8662bdc4983d2ae4548ad5e` | 12564 → 12564 | 2133 → #2154 | imported, intact |
| 20 | Page_1.jpg | `2_452fa422bd2b3d2af86c8e924945ca2f` | 338939 → 338935 | 2144 → #2168 | imported, 4 byte(s) lost (check it by eye) |
| 27 | fille.jpg | `2_ba66a239d01967bdf4222bd776961455` | 562934 → 562924 | 2176 → #2202 | imported, 10 byte(s) lost (check it by eye) |
| 28 | cyber.jpg | `2_32f63572631ddf6ae25f80efa3bfd9f7` | 367984 → 367970 | 2176 → #2202 | imported, 14 byte(s) lost (check it by eye) |
| 29 | eye.jpg | `2_d9cd01a9bc07095b9429745c747a721a` | 127584 → 127583 | 2178 → #2218 | imported, 1 byte(s) lost (check it by eye) |
| 32 | Page_1.jpg | `2_f012cf6148ae2f635f7d87d2ffca3a11` | 184126 → 184124 | 2159 → #2207 | imported, 2 byte(s) lost (check it by eye) |
| 36 | finalfantasy.jpg | `2_de8f4beebd82bcceed83bb99b0e24661` | 744819 → 744796 | 2178 → #2218 | **NOT imported**: undecodable (JPEG data damaged) |
| 37 | haut_les_mains.jpeg | `58_4a11d4e76be708ae09e72c131a2635ec` | 21646 → 21645 | 2426 → #2494 | imported, 1 byte(s) lost (check it by eye) |
| 38 | 5869577.gif | `58_4871501c1fc158cbdcb974219cf8d692` | 17316 → 17316 | 2429 → #2498 | imported, intact |

(Searched for an undamaged copy: no file of the recorded size exists in `comtso.old`, `comtso2.old` (`upload/`, `files/repaired/` included) nor in the NAS photo originals — the two size coincidences are other images.)

## Rehearsal on the dev stack (2026-10-19)

| Step | Time |
| --- | ---: |
| Load the dump into the side database `comtso_legacy` | 1.3 s |
| Recreate the dev database + `doctrine:migrations:migrate` | ~1 s |
| Copy 2 404 NAS originals into `var/photos/originals` (`cp -r`) | 1 min 43 s |
| `app:legacy:import --force` — database part (12 users, 6 forums, 367 topics, 22 078 comments, 2 403 photos…) + mojibake repair | ~25 s |
| `app:legacy:import` — photo files (JPEG → WebP in place, 871 MB → 334 MB) | ~10 min (total command 10 min 30 s, 1 GB memory limit) |
| `app:legacy:reimport --dry-run` | 12 s |
| `app:legacy:reimport` (real; 134 + 4 244 … rows, 20 attachments, 2 photos) | 14 s |
| `app:legacy:reimport` run again (twice): 0 imports | 12 s each |
| `app:photos:reprocess` (dry-run and real; everything already up to date) | 0.3-0.6 s |
| `app:content:sanitize` (2 013 rows changed; dry-run afterwards: 0) | 1.6 s |

Order that works from scratch: dump → `comtso_legacy` → migrations → `app:legacy:import --force` → legacy DBs up → `app:legacy:reimport` (one-off container with the two mounts) →
`app:photos:reprocess` → `app:content:sanitize`. Fixed on the way: `app:legacy:import` failed on a fresh schema (`last_activity_at` without default); it is now computed
(`GREATEST(created_at, newest comment)`).

## Rollback

`var/backup-before-reimport.sql` (full `mariadb-dump` taken before the first run) restores the previous state. To undo *only* this import without touching
anything else (rows are identified by their `legacy_ref`):

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

(The real run itself was done twice on a clean state: the first run exposed two matching flaws — a French « é » + no-break space + « » read as
mojibake, and quote headers differing between renderings — which produced 7 duplicates; they were removed with the statements above, the matcher was fixed
and covered by tests, and the import re-run from scratch. A further pass found two more duplicates of the garbled Chinese posts, fixed the same way.)

## Removing the temporary tooling

Once the result is accepted (it is single-use; `legacy_ref` columns and the imported rows stay):

```bash
docker compose --profile legacy down -v                     # stops and deletes the two legacy databases
git rm -r src/Legacy src/Command/ReimportLegacyCommand.php tests/Legacy docker/legacy
# compose.yaml: delete the `legacy-mysql` and `legacy-postgres` services (and the comment above them)
# compose.yaml: also delete the `LEGACY_*` entries of the `php` service environment
# docs/: keep this file as the record; AGENTS.md "Legacy data" paragraph: drop the reimport sentence
rm var/backup-before-reimport.sql var/reimport-*.log        # when the rollback copy is no longer needed
```

`var/legacy/*.sql` (the dumps) are git-ignored with the whole `var/` directory; keep them somewhere safe.

## Photos and the photo pipeline (EXIF)

* The Symfony 2 forum **did** keep EXIF: `ctso_photo.jsonExif`/`exif` (ExifTool output). `app:legacy:import` normalised it into the `exif` JSON column (Make, Model, DateTime,
  ExposureTime, FNumber, ISOSpeedRatings, FocalLength, DateTimeOriginal + the whole raw ExifTool dump) and copied `taken_at`; there were no GPS columns. 2 194 of the 2 396 photos carry EXIF.
  The old PHP forum and the phpBB platforms stored none (their photo rows have no EXIF).
* Every photo file that these importers copy now goes through `App\Photo\PhotoPipeline` (`app:legacy:import` through `PhotoReprocessor` when the photo source is another directory, in place when it is
  the photo directory; `app:legacy:reimport` for the photos it recovers): EXIF is re-read from the original file into `taken_at`, `gps_*` and the curated `exif` map (what only the old map knew is kept, its raw
  ExifTool dump with server paths is dropped), the picture is rotated, capped at 10 MP and stored as WebP.
* A library that is already restored with raw JPEGs (e.g. a dump of the production database + `var/photos`) is migrated in place with `app:photos:reprocess` (`--dry-run`, `--limit`; idempotent; roughly one to two
  seconds per 12-megapixel photo, so ~1 h for 2.4 k photos — run it once in a `tmux`, then `app:photos:warm`).
