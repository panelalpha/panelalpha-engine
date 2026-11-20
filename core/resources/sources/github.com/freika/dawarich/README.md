# Dawarich (github.com/freika/dawarich)

Self-hosted location history tracker (a Google Timeline replacement): a Rails
app on :3000 with a Sidekiq worker, PostGIS and Redis.

## Deploying

Deploy as is, open `/users/sign_in` and sign in with the login the engine
generates (`DAWARICH_ADMIN_EMAIL`, default `admin@example.com`, and
`DAWARICH_ADMIN_PASSWORD`): read it with `GET /projects/{name}/app-credentials`
(MCP `app_credentials_get`).

## What the recipe does

- `overrides/docker-compose.yml` is upstream's `docker/docker-compose.yml` on
  the published `freikin/dawarich:1.15.2` image, beside PostGIS and Redis,
  with memory limits; `hooks/prepare.sh` generates `SECRET_KEY_BASE` and the
  database password once into `~/.panelalpha/dawarich/`.
- Self-hosted Dawarich has no sign-up, and upstream's `db:seed` creates the
  admin `demo@dawarich.app` / `safepassword`. The web service reads the
  engine's login as `env_file`; the image's `web-entrypoint.sh` migrates and
  seeds, then runs the service's command under `bundle exec`:
  `files/panelalpha/dawarich-login.sh` runs `files/panelalpha/dawarich-login.rb`
  with `bin/rails runner`, which gives an admin that still has `safepassword`
  the engine's email and password through Dawarich's own `User` model, then
  execs the Rails server.
- An admin whose password was changed in Dawarich is never touched. The
  runner boots Rails once more before the server on every start of the web
  service.
