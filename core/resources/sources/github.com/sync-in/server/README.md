# Sync-in (github.com/Sync-in/server)

File storage, sync and sharing with real-time collaboration: the released
`syncin/server` image (NestJS + Angular on :8080) with MariaDB, following
upstream's `docker/docker-compose.yaml`.

## Deploying

No variable is required; the site opens Sync-in's login page. Upstream creates
the first administrator only when `INIT_ADMIN` is set: set `INIT_ADMIN=true`
plus `INIT_ADMIN_LOGIN` and `INIT_ADMIN_PASSWORD` (more than 3 characters) as
project env vars before the first deploy. Without a login/password upstream
falls back to its documented default (`sync-in` / `sync-in`). The admin is
created once; later deploys log "already exists".

Mail: `SYNCIN_MAIL_HOST`, `SYNCIN_MAIL_PORT`, `SYNCIN_MAIL_SENDER`,
`SYNCIN_MAIL_AUTH_USER`, `SYNCIN_MAIL_AUTH_PASS`, `SYNCIN_MAIL_SECURE`.

## What the recipe does

- `overrides/docker-compose.yml` replaces the repository's development setup
  (the root Dockerfile's monorepo build is OOM-killed at 2500 MB) with
  upstream's compose: image pinned to `2.5.2`, `mariadb:11.8`, and the
  configuration as `SYNCIN_*` env vars instead of a bind-mounted
  `environment.yaml`; `SYNCIN_SERVER_PUBLICURL` is the site's address. The
  optional OnlyOffice / Collabora / draw.io sidecars are left out.
- `hooks/prepare.sh` writes the JWT access/refresh secrets, the MFA encryption
  key and the MariaDB root password once into `~/.panelalpha/sync-in/`.
- A `ready` service waits for the image's own readiness check (`/healthz/ready`).
- Data: named volumes `data` (users' files) and `mariadb_data`.
