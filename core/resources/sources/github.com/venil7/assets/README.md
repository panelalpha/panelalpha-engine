# Assets (github.com/venil7/assets)

Personal net-worth and investment tracker: a Bun/Express API serving a React
UI on :4020, SQLite, market data from Yahoo Finance.

## Why a recipe

The repository's `docker-compose.yaml` uses `image: ghcr.io/venil7/assets:${TAG}`
and nothing sets `TAG`, so the plain deploy fails with
`unable to get image 'ghcr.io/venil7/assets:': invalid reference format`.

## What the recipe does

- `overrides/docker-compose.yml` runs `ghcr.io/venil7/assets:1.8.6` (current
  release) on port 4020, `ASSETS_DB=/data/assets.db` on the `assets-data`
  named volume.
- `ASSETS_JWT_SECRET` is generated per account by the engine and stays stable.
- Optional project env vars pass through `.env`: `ASSETS_CACHE_TTL`,
  `ASSETS_JWT_EXPIRES_IN`, `ASSETS_JWT_REFRESH_BEFORE`, `ASSETS_USERNAME`,
  `ASSETS_PASSWORD` (the seeded account; upstream default admin / admin).
- A no-op `ready` service holds the deploy until `/app/` answers 200.
