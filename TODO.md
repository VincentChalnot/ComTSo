# TODO

The single live backlog (updated 2026-10-20, after the cutover week). Waves are in execution order; inside a wave, top to bottom.
Decisions already taken and history: `docs/decisions.md`. Ops runbook: `deploy/README.md` and `docs/operations.md`.
Items marked **BLOCKED** need a decision from the owner before any work.

Done and removed from this list (details in `docs/decisions.md`): GitHub/CI hardening, backup restore test and NAS photo rsync mirror, `www.comtso.fr` redirect (live), old Symfony stack retired (2026-10-20, so no rollback rehearsal), DMARC review, Sentry, admin panel (members with e-mails, forums, topics, comments, photos, quotes), settings centre, photo rotation without quality loss, quotes by any member, Mailpit through Traefik (dev), logo proposals.

## Wave 1: next agent tasks

1. **Verify Sentry after the next deploy.** `SENTRY_DSN` is already set on the server: run `docker compose exec app php bin/console sentry:test` once and check the event arrives (`docs/operations.md` "Sentry").
2. **Wire in the chosen logo** (follow-up of the owner's pick, see below): favicons, masthead, login card, e-mail header, in both themes.

## Waiting for the owner (not an agent task)

- **Git**: sign/rebase the local commits and push (`git log --oneline origin/master..HEAD` lists them); then apply the recommended branch protection after the force-push and check the first Dependabot pull requests (`docs/operations.md` "GitHub repository, CI and ghcr").
- **Delete the 6 orphaned `sha-*` ghcr tags** (command in `docs/operations.md`).
- **Tag `sidus/filter-bundle` v6.0.4**, then drop the Composer patches (steps: `AGENTS.md` "Composer patches").
- **Sidus registries state defect** (`DataGridRegistry` / `QueryHandlerRegistry` cache stateful objects and are not reset between requests): fix upstream; then delete the request-scoped registries workaround (`AGENTS.md` "Admin panel", `App\Admin\Registry\RequestScoped*`, `RequestScopedDatagridPass`).
- **Choose a logo concept** in `docs/logo-proposal/` (then Wave 1 item 2).
- **NAS**: delete the two old `photos-*.tar.gz` once the rsync mirror is trusted; optionally stop shipping the server's own photos tarball (now redundant, `docs/operations.md` "Backups").
- **Keres CSP**: check the Keres app still works with its own CSP (the shared Traefik no longer sets one).
- **Dev Mailpit host**: the default is `mail.comtso.local.sidus.fr`. The owner asked for `mail.local.comtso.fr`, which resolves to the public server: add a `*.local.comtso.fr` A record to 127.0.0.1, then set `MAILPIT_HOST`.
- **DMARC**: read `dmarc=pass` once in the header of a received mail. Optional: add `rua=` reports (`docs/operations.md`); `p=none` is fine for this forum.

## Dropped (do not re-add; re-open only on a real request)

- **Dark mode for emails:** clients already invert light emails acceptably; a themed variant means a second palette per template and `prefers-color-scheme` support is partial.
- **ParaTest:** the suite is fast enough and the shared test database makes it flaky-prone.
- **The 6 undecodable attachments and comment 842:** accepted loss, documented in `docs/decisions.md`, not a task.
- **"Les Gens": show disabled users too:** moot, the owner re-enabled the relevant former members; all enabled profiles stay visible to every member (D1).
