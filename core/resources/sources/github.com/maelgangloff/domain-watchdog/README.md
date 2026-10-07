# Domain Watchdog

Runs upstream's four services (app, Messenger worker, PostgreSQL 16, Valkey)
from the release image `maelgangloff/domain-watchdog:v1.4.5` instead of the
repository's compose file, which the engine cannot start: the rewrite of its
`healthcheck: { test: [ ], disable: true }` produces `test: {}` and Compose
rejects the file.

- `hooks/prepare.sh` writes `~/.panelalpha/domain-watchdog/secrets.env` once:
  the database password (also in `DATABASE_URL`), `APP_SECRET` and
  `JWT_PASSPHRASE`. The image otherwise uses the values committed in the
  repository's `.env`.
- Data: `database_data` (PostgreSQL), `content` (`public/content`, optional
  instance customisation), Caddy's `caddy_data`/`caddy_config`.
- A no-op `ready` service holds `compose up -d` until the app is healthy
  (migrations run in the entrypoint before FrankenPHP starts).
- Registration is open (`REGISTRATION_ENABLED=true`), as upstream ships it.
