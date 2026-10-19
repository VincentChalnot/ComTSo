# TODO

The single live backlog (agreed 2026-10-19, after the comtso.fr cutover). Waves are in execution order; inside a wave, top to bottom.
Decisions already taken and history: `docs/decisions.md`. Ops runbook: `deploy/README.md` and `docs/operations.md`.
Items marked **BLOCKED** need a decision from the owner before any work.

## Wave 1: safety and visible gaps (about 2-3 working days)

1. **Backup: NAS mirror still to verify.** The restore test is done (a dev-database dump restored into a scratch database, row counts identical; server backup of 2026-10-19 passes `gzip -t`). The NAS mirror `new-forum/` did not exist yet: run the NAS `backup.sh` once, check that `new-forum/` holds the day's dump and photo archive, and restore-test that copy (`docs/operations.md` "Backups and restore checks").
2. **`www.comtso.fr` redirect and Keres CSP** (owner only, minutes): test `compose.www-redirect.yaml` (permanent redirect to the apex) and that the Keres app still works with its own CSP (the shared Traefik no longer sets one).

## Wave 2: hardening and photo/text features

1. **CI hardening: owner commands left.** Done (2026-10-19): Dependabot, gitleaks job, prod-image smoke test in `image`, repository security settings, stale runs removed (`docs/operations.md` "GitHub repository, CI and ghcr"). Left for the owner: delete the 6 orphaned `sha-*` ghcr tags (command in that section), then, after the signed force-push of `master`, apply the recommended branch protection (command there), and check the first Dependabot pull requests.
2. **Quick photo rotation for the owner and admins.** Touches the photo pipeline: re-encode the WebP original, invalidate `cache/{thumb,medium,hero,avatar}`, EXIF orientation. After the avatar work; pipeline test required.
3. **Sentry.** Plan in `docs/operations.md` ("Sentry plan"): cheap, but production is quiet and `/health` plus logs cover a 20-member forum. Do it after the first production error that cannot be diagnosed.

## Wave 3: decisions and big or optional items

1. **Admin panel with datagrids** (`sidus/admin-bundle` + `datagrid-bundle`). By far the biggest item: new dependencies, security model, theming against the custom design system, CSRF-test classification. The bundles declare `symfony/*: >=6.3` so Symfony 8.1 should install, **unverified**: spike with `composer require --dry-run` first. The CLI (`app:user:*`) and SQL are enough for one admin today; do not start it soon.
2. **Admins can delete empty forums.** Only as one action of the admin panel, never as a separate feature (deleting a forum is "SQL only" today, 6 static forums plus 7 recovered ones).
3. **Statistics panel** (messages per person per year, charts). Read-only nice-to-have: one SQL aggregate and a Vue chart widget; a normal page, independent of the admin panel.
4. **"Ajouter une citation" under a hovered quote. BLOCKED** on who may add quotes (all members or admins). `ctso_quote` has no date column.
5. **Better logo with the brand colours (dark).** Design asset; needs the owner's taste and the five brand colours of `abstracts/_palette.scss`.
6. **Digest-sample email test** locking the layout reuse contract: add it in the same commit as the next email layout change.
7. **Rehearse the server rollback** (stop `app`, start old `nginx`, `docs/operations.md`): only if the old stack still exists; value disappears when it is retired.

## Housekeeping, not scheduled (status to confirm)

From the go-live checklist, none of it is in the agreed waves: retire the old stack after a stable period; tighten DMARC from `p=none` once SPF/DKIM pass in real mails.

## Dropped (do not re-add; re-open only on a real request)

- **Dark mode for emails:** clients already invert light emails acceptably; a themed variant means a second palette per template and `prefers-color-scheme` support is partial.
- **ParaTest:** the suite is fast enough and the shared test database makes it flaky-prone.
- **The 6 undecodable attachments and comment 842:** accepted loss, documented in `docs/decisions.md`, not a task.
- **"Les Gens": show disabled users too:** moot, the owner re-enabled the relevant former members; all enabled profiles stay visible to every member (D1).
