# TODO

The single live backlog (agreed 2026-10-19, after the comtso.fr cutover). Waves are in execution order; inside a wave, top to bottom.
Decisions already taken and history: `docs/decisions.md`. Ops runbook: `deploy/README.md` and `docs/operations.md`.
Items marked **BLOCKED** need a decision from the owner before any work.

## Wave 1: safety and visible gaps (about 2-3 working days)

1. **Backup: NAS mirror still to verify.** The restore test is done (a dev-database dump restored into a scratch database, row counts identical; server backup of 2026-10-19 passes `gzip -t`). The NAS mirror `new-forum/` did not exist yet: run the NAS `backup.sh` once, check that `new-forum/` holds the day's dump and photo archive, and restore-test that copy (`docs/operations.md` "Backups and restore checks").
2. **`www.comtso.fr` redirect and Keres CSP** (owner only, minutes): test `compose.www-redirect.yaml` (permanent redirect to the apex) and that the Keres app still works with its own CSP (the shared Traefik no longer sets one).

## Wave 2: hardening and photo/text features

1. **CI hardening: owner commands left.** Done (2026-10-19): Dependabot, gitleaks job, prod-image smoke test in `image`, repository security settings, stale runs removed (`docs/operations.md` "GitHub repository, CI and ghcr"). Left for the owner: delete the 6 orphaned `sha-*` ghcr tags (command in that section), then, after the signed force-push of `master`, apply the recommended branch protection (command there), and check the first Dependabot pull requests.
2. **Sentry.** Plan in `docs/operations.md` ("Sentry plan"): cheap, but production is quiet and `/health` plus logs cover a 20-member forum. Do it after the first production error that cannot be diagnosed.

## Wave 3: decisions and big or optional items

1. **"Ajouter une citation" under a hovered quote. BLOCKED** on who may add quotes (all members or admins). The admin panel (`/admin/quotes`) now lets administrators add, edit and delete quotes (so administrators already can), but the public hover UI for members is still undecided. `ctso_quote` has a `created_at` column but no date is shown anywhere.
2. **Better logo with the brand colours (dark).** Design asset; needs the owner's taste and the five brand colours of `abstracts/_palette.scss`.
3. **Rehearse the server rollback** (stop `app`, start old `nginx`, `docs/operations.md`): only if the old stack still exists; value disappears when it is retired.

## Waiting for the owner (not an agent task)

- **Tag the Symfony 8 fixes of the sidus bundles**: push the `symfony-8` branches of `SidusAdminBundle` and `SidusFilterBundle` (3 small fixes, committed locally), tag `sidus/admin-bundle` 5.0.7 and `sidus/filter-bundle` 6.0.4, then drop the Composer patches of the admin panel (exact steps: `AGENTS.md`, "Admin panel", "Composer patches").

## Housekeeping, not scheduled (status to confirm)

From the go-live checklist, none of it is in the agreed waves: retire the old stack after a stable period; tighten DMARC from `p=none` once SPF/DKIM pass in real mails.

## Dropped (do not re-add; re-open only on a real request)

- **Dark mode for emails:** clients already invert light emails acceptably; a themed variant means a second palette per template and `prefers-color-scheme` support is partial.
- **ParaTest:** the suite is fast enough and the shared test database makes it flaky-prone.
- **The 6 undecodable attachments and comment 842:** accepted loss, documented in `docs/decisions.md`, not a task.
- **"Les Gens": show disabled users too:** moot, the owner re-enabled the relevant former members; all enabled profiles stay visible to every member (D1).
