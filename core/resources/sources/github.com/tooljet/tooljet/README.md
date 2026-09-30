# ToolJet (github.com/ToolJet/ToolJet)

Low-code internal-tool builder, Community Edition. NestJS server, React
client.

Plain deploy: the root `docker-compose.yaml` is a development stack (builds
the checkout, mounts `./plugins`), so the engine falls through to Railpack,
which cannot build the monorepo, and serves its placeholder. The enterprise
submodules `frontend/ee` and `server/ee` are private; the engine skips them
with a warning.

## What the recipe does

- `overrides/docker-compose.yml`: upstream's
  `deploy/docker/docker-compose-db.yaml` of v3.20.235-lts on the release image
  `tooljet/tooljet-ce:v3.20.235-lts`: `tooljet` (server and client on :3000,
  `npm run start:prod`; the image's entrypoint starts its bundled Redis and
  runs `db:setup:prod`), `postgresql` (`postgres:13`, named volume) and
  `postgrest` (`postgrest/postgrest:v12.0.2`, ToolJet Database).
- `hooks/prepare.sh` generates what upstream's `deploy/docker/internal.sh`
  writes into `.env`: `LOCKBOX_MASTER_KEY`, `SECRET_KEY_BASE`,
  `PGRST_JWT_SECRET`, the Postgres password and `PGRST_DB_URI`, once into
  `~/.panelalpha/tooljet/tooljet.env`.
- The non-secret settings of `.env.internal.example` are in the compose;
  `TOOLJET_HOST` is the account's public URL.
- `postgrest` starts after ToolJet is healthy, because ToolJet creates the
  `tooljet_db` database it serves. A no-op `ready` service waits for
  `/api/health`.

## First run

Upstream's: the first visitor creates the super admin and workspace on the
setup page.
