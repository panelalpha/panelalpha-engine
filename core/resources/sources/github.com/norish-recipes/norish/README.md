# Norish (github.com/norish-recipes/norish)

Realtime recipe app. One Node server on :3000 with PostgreSQL, Redis and the
Obscura headless browser (used to render pages for recipe URL imports).

## Deploying

No variables are required. The first visit shows Norish's sign-in page; the
first account registered with email/password becomes the admin (upstream's
first-run behaviour). OIDC/GitHub/Google sign-in can be added through the
upstream env vars (`OIDC_*`, `GITHUB_CLIENT_*`, `GOOGLE_CLIENT_*`).

## What the recipe does

- `overrides/docker-compose.yml` is upstream's `docker/docker-compose.example.yml`
  with `norishapp/norish:v0.24.0-beta`, `AUTH_URL` set to the site's address,
  and `MASTER_KEY` / `POSTGRES_PASSWORD` as `${VAR:?}` variables that the engine
  generates per account, stable across redeploys. `MASTER_KEY` only needs 32+
  characters (Norish HKDF-derives its keys from it).
- The repository's own `docker/Dockerfile` is not built: it requires
  `NORISH_VERSION_REPORT_JSON`, which only upstream's release CI provides.
- Data: `db_data`, `norish_data` (uploads) and `redis_data` named volumes.
