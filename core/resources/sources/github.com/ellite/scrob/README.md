# Scrob (github.com/ellite/scrob)

Media tracker that syncs watch history from Jellyfin, Plex and Emby. One image
(`bellamy/scrob`: Astro frontend on :7330, FastAPI backend on 127.0.0.1:7331)
plus PostgreSQL.

## Why a recipe

The repository's `docker-compose.yaml` uses the literal password `changeme`
both as `POSTGRES_PASSWORD` and inside the app's `DATABASE_URL`. The engine
replaces the first with a generated secret but not the second, so the app
fails with `password authentication failed for user "scrob"` and restarts
forever.

## What the recipe does

- `overrides/docker-compose.yml` keeps upstream's two services, pinned to
  `bellamy/scrob:2.21.0` and `postgres:16-alpine`.
- `SCROB_DB_PASSWORD` and `SCROB_SECRET_KEY` are required variables the
  engine generates once per account (stable across redeploys); the password is
  used in both the database and the DSN.
- Data on the `db_data` and `scrob_data` volumes.
- A healthcheck on the backend and the frontend, and a no-op `ready` service,
  make the deploy wait until Scrob answers.

First visit opens Scrob's sign-in page; the first account registered is the
admin, as upstream ships it.
