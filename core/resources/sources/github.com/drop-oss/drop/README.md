# Drop (github.com/Drop-OSS/drop)

Self-hosted game distribution platform: a Nuxt server behind an in-container
nginx on :3000, with PostgreSQL.

## Deploying

Nothing is required to start. Open `/auth/signin` and sign in with the
login the engine generates (`DROP_ADMIN_USERNAME`, default `admin`,
`DROP_ADMIN_EMAIL` and `DROP_ADMIN_PASSWORD`): read it with
`GET /projects/{name}/app-credentials` (MCP `app_credentials_get`). Add a
library source under Admin, Library. Optional metadata and login providers are
configured with project environment variables (`IGDB_CLIENT_ID`,
`IGDB_CLIENT_SECRET`, `GIANT_BOMB_API_KEY`, `OIDC_*`).

## What the recipe does

- `overrides/docker-compose.yml` runs `ghcr.io/drop-oss/drop:0.4.0-rc-5` (the
  tag upstream's quickstart pins) with `postgres:17-alpine`, `EXTERNAL_URL`
  set to the site address, and `/library`, `/data` and the database on named
  volumes.
- `hooks/prepare.sh` generates the database password once into
  `~/.panelalpha/drop/`.
- A one-shot `login` service (`postgres:17-alpine`, for its `psql` and
  `wget`) runs `files/panelalpha/drop-login.sh` once Drop answers. On a
  database with no user Drop makes a setup token and only logs the
  `/setup?token=...` link; the script reads that token from the database
  and takes the wizard's steps through Drop's own API: an admin invitation,
  the sign-up with the engine's login, then `POST /api/v1/setup`, which
  deletes the token, so the logged link no longer works. Once any user
  exists it does nothing, so a password changed in Drop stays changed. It
  always exits 0: if a step fails, the log says so and the wizard stays.
- `ready` waits for `login`, so `compose up -d` returns with the admin made.
- The repository's Dockerfile is not used: `nuxt prepare` runs
  `git rev-parse` and the build context has no `.git`.
