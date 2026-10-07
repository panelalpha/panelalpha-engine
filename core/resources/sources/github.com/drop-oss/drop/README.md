# Drop (github.com/Drop-OSS/drop)

Self-hosted game distribution platform: a Nuxt server behind an in-container
nginx on :3000, with PostgreSQL.

## Deploying

Nothing is required to start. On first boot Drop logs a one-time setup link
(`Open https://<site>/setup?token=... in a browser`); read it from the app
container's log and create the admin account there. Optional metadata and
login providers are configured with project environment variables
(`IGDB_CLIENT_ID`, `IGDB_CLIENT_SECRET`, `GIANT_BOMB_API_KEY`, `OIDC_*`).

## What the recipe does

- `overrides/docker-compose.yml` runs `ghcr.io/drop-oss/drop:0.4.0-rc-5` (the
  tag upstream's quickstart pins) with `postgres:17-alpine`, `EXTERNAL_URL`
  set to the site address, and `/library`, `/data` and the database on named
  volumes.
- `hooks/prepare.sh` generates the database password once into
  `~/.panelalpha/drop/`.
- The repository's Dockerfile is not used: `nuxt prepare` runs
  `git rev-parse` and the build context has no `.git`.
