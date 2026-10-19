# TODO

The single live backlog (agreed 2026-10-19, after the comtso.fr cutover). Waves are in execution order; inside a wave, top to bottom.
Decisions already taken and history: `docs/decisions.md`. Ops runbook: `deploy/README.md` and `docs/operations.md`.
Items marked **BLOCKED** need a decision from the owner before any work.

## Wave 1: safety and visible gaps (about 2-3 working days)

1. **Backup check (NAS mirror).** Verify the NAS backup of the new stack (`new-forum/` mirror, see `docs/operations.md`) and do one restore test of a dump into a scratch database (`deploy/restore.sh`, or the scratch-DB procedure in `docs/operations.md`). Biggest risk reduction per minute.
2. **CI first run: CHECK.** The first real GitHub Actions run happens on the history-rewrite push. Verify end to end: `base`, `test`, `frontend`, `image`, `publish` green, `latest` pushed to ghcr, then make the `comtso-base` package public (otherwise a fork or a clean machine cannot pull the base image). Docs-only pushes are skipped (`paths-ignore`): trigger manually or push a non-docs commit if nothing started.
3. **`www.comtso.fr` redirect and Keres CSP** (owner only, minutes): test `compose.www-redirect.yaml` (permanent redirect to the apex) and that the Keres app still works with its own CSP (the shared Traefik no longer sets one).
4. **Eyeball the 10 shortened attachments** (#9, 10, 12, 14, 20, 27, 28, 29, 32, 37, see `docs/legacy-import.md`): 15 minutes, import fidelity; do it before new messages bury the evidence.
5. **Search: paginate, results by type in tabs.** Correctness/performance, not a nicety: results are unbounded over about 26 000 comments. Keep the six legacy sections as tabs (Commentaires, Photos, Chats, Membres, Topics, Citations); `tests/Forum` search tests cover it.
6. **"Les Gens": show ALL users, including disabled ones.** Tiny change in `PeopleController`, restores the old forum's behaviour for former members. Decide in the same change whether disabled accounts are visible to everyone or only marked; placeholder (`*.invalid`) accounts must never leak addresses; test it.
7. **Change avatar.** Most visible gap for members. Plumbing exists (`avatar` variant, photo pipeline, `Photo` avatar link): form on `/gens/{pseudo}/config`, release the old avatar through `PostPhotos::releaseOrphans`, test.

## Wave 2: hardening and photo/text features

1. **CI hardening.** Dependabot (a few lines of YAML, security value: right after the CI check above), ghcr cleanup of old `sha-*` tags (the history rewrite orphaned them; keep the tag currently deployed, see `deploy/README.md` "Rollback"), a prod-image smoke test in CI.
2. **Modern UTF-8 emojis for new messages** (old gif smilies stay). First check that `ctso_comment` is `utf8mb4` (legacy tables may be `utf8`), then the sanitizer allow-list, an editor picker next to the smilies (`assets/vue/editor/smilies.js`) and a sanitizer test with astral characters.
3. **Quick photo rotation for the owner and admins.** Touches the photo pipeline: re-encode the WebP original, invalidate `cache/{thumb,medium,hero,avatar}`, EXIF orientation. After the avatar work; pipeline test required.
4. **Sentry.** Plan in `docs/operations.md` ("Sentry plan"): cheap, but production is quiet and `/health` plus logs cover a 20-member forum. Do it after the first production error that cannot be diagnosed.

## Wave 3: decisions and big or optional items

1. **Profile visibility option (D1). BLOCKED.** The go-live record says D1 was decided "no change requested"; ask the owner what the option is before any work.
2. **Admin panel with datagrids** (`sidus/admin-bundle` + `datagrid-bundle`). By far the biggest item: new dependencies, security model, theming against the custom design system, CSRF-test classification. The bundles declare `symfony/*: >=6.3` so Symfony 8.1 should install, **unverified**: spike with `composer require --dry-run` first. The CLI (`app:user:*`) and SQL are enough for one admin today; do not start it soon.
3. **Admins can delete empty forums.** Only as one action of the admin panel, never as a separate feature (deleting a forum is "SQL only" today, 6 static forums plus 7 recovered ones).
4. **Statistics panel** (messages per person per year, charts). Read-only nice-to-have: one SQL aggregate and a Vue chart widget; a normal page, independent of the admin panel.
5. **"Ajouter une citation" under a hovered quote. BLOCKED** on who may add quotes (all members or admins). `ctso_quote` has no date column.
6. **Better logo with the brand colours (dark).** Design asset; needs the owner's taste and the five brand colours of `abstracts/_palette.scss`.
7. **Digest-sample email test** locking the layout reuse contract: add it in the same commit as the next email layout change.
8. **Rehearse the server rollback** (stop `app`, start old `nginx`, `docs/operations.md`): only if the old stack still exists; value disappears when it is retired.

## Housekeeping, not scheduled (status to confirm)

From the go-live checklist, none of it is in the agreed waves: retire the old Symfony 2 stack after a stable period and then remove the one-shot import tooling (`docs/legacy-import.md` "Removing the temporary tooling"); tighten DMARC from `p=none` once SPF/DKIM pass in real mails; replace the real-looking dev `MERCURE_JWT_SECRET` of `.env.example` by an obvious placeholder; optional gitleaks CI step.

## Dropped (do not re-add; re-open only on a real request)

- **Dark mode for emails:** clients already invert light emails acceptably; a themed variant means a second palette per template and `prefers-color-scheme` support is partial.
- **ParaTest:** the suite is fast enough and the shared test database makes it flaky-prone.
- **The 6 undecodable attachments and comment 842:** accepted loss, documented in `docs/decisions.md`, not a task.
