# MediaFusion (github.com/mhdzumair/mediafusion)

Stremio/Kodi add-on server with a web UI (`/app`) for configuring catalogs,
scrapers and debrid providers. The stack is a Rust API plus worker, PostgreSQL
18 and Redis.

Plain deploy: the root `pyproject.toml` belongs to tooling and to the
deprecated `python-deprecated/` tree. The engine picks `python`, finds no
entry point and serves its own placeholder page. Upstream deploys from
`deployment/docker-compose/` with the published image.

## What the recipe does

- `overrides/docker-compose.yml` runs upstream's compose stack at
  `mhdzumair/mediafusion:6.1.6`:
  - the API on :8000 with `HOST_URL`/`POSTER_HOST_URL` = `${PA_PUBLIC_URL}`
  - `mediafusion-worker`
  - `postgres:18-alpine` with `pg_stat_statements` preloaded, as upstream does
  - Redis
  - named volumes for the database and Redis
- Left out: the nginx + mkcert TLS front (the engine terminates TLS), TRAWL (a
  headless browser pool that needs 1 GB of shm) and Prowlarr. All three are
  optional upstream, and the worker's `TRAWL_URL` is unset.
- `hooks/prepare.sh` writes `SECRET_KEY`, `POSTGRES_PASSWORD` and
  `POSTGRES_URI` once to `~/.panelalpha/mediafusion/app.env`. Upstream's
  compose hardcodes `mediafusion:mediafusion`.
- `.env` is an optional env_file, so project env vars (`API_PASSWORD`,
  `CONTACT_EMAIL`, provider keys, `IS_SCRAP_FROM_*`) reach both processes.
- `ENABLE_RATE_LIMIT` defaults to `false`, upstream's `.env-sample` value,
  because every visitor arrives from the same proxy address.
- A no-op `ready` service waits for `/health`. The first start runs the
  migrations in about 10 s.

## First run

Upstream's defaults: without `API_PASSWORD` the instance reports
`is_public_instance: true` and registration is open.
