# ComTSo

Private forum for a circle of friends (history back to 2004): forums and topics, photo albums with a lightbox, private
messages/memos, a real-time chat, favourites, a "what's new since your last visit" home page and full-text search.

**Stack:** Symfony 8.1 · FrankenPHP (Caddy + Mercure) · MariaDB 11.4 · Vite · Tailwind CSS 4 · Vue 3 (editor, chat, uploads) · PHPUnit 12.

> Working on the code (humans and agents): read [`AGENTS.md`](AGENTS.md) first. Feature inventory of the old site: [`docs/LEGACY_FEATURES.md`](docs/LEGACY_FEATURES.md).

## Run it

Requires Docker + Compose and the shared Traefik reverse proxy (external network `proxy`, see `../traefik`).

```bash
docker compose up -d --build
docker compose exec php composer install        # first time only (vendor/ is bind-mounted)
docker compose exec php bin/console doctrine:migrations:migrate -n
docker compose exec php bin/console app:user:create <pseudo> <password> --admin   # accounts are created by the owner
```

The app answers on **https://comtso.local.sidus.fr** (`SERVER_NAME`): Traefik forwards to the container's port 80 and obtains a Let's
Encrypt certificate (DNS challenge). Assets are rebuilt automatically by the `node` service (`vite build --watch`).

| Env var | Default | Meaning |
|---|---|---|
| `SERVER_NAME` | `comtso.local.sidus.fr` | public host name (Traefik router rule, `DEFAULT_URI`, Mercure URL) |
| `TRAEFIK_NETWORK` / `TRAEFIK_ENTRYPOINT` / `CERT_RESOLVER` | `proxy` / `websecure` / `gandi` | Traefik wiring |
| `REGISTRATION_ENABLED` | `false` | set to `true` to open `/register` (the forum is private; accounts are normally created by the owner) |
| `DATABASE_PORT` | `3307` | MariaDB published on the host |

## Quality

```bash
docker compose exec php sh -c 'APP_ENV=test php bin/console doctrine:migrations:migrate -n'   # once per migration
docker compose exec php vendor/bin/phpunit
```

## Data migration from the old platforms

- The Symfony 2 database (`var/2610110300.sql.gz`) is imported with `bin/console app:legacy:import` (see the command help; side database `comtso_legacy`).
- Older platforms (first PHP forum, two phpBB installs) are recovered by the single-use `app:legacy:reimport`: see [`docs/legacy-import.md`](docs/legacy-import.md).
- Photo originals live in `var/photos/originals` (private, never under `public/`); thumbnails are generated lazily (`bin/console app:photos:warm` pre-generates them).
