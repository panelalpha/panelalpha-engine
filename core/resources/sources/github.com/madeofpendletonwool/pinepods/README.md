# PinePods (github.com/madeofpendletonwool/pinepods)

Podcast manager: Rust API and web UI behind nginx on :8040, PostgreSQL and
Valkey.

## Deploying

Nothing to set. Open the site and create the first admin on PinePods' own
setup page. Any other PinePods setting (`OIDC_*`, `TZ`, ...) can be set as a
project environment variable and applies on the next deploy.

## What the recipe does

- `overrides/docker-compose.yml` replaces what a plain deploy would run (the
  repository's `Dockerfile.validator`, a schema test harness) with upstream's
  documented PostgreSQL stack from `madeofpendletonwool/pinepods:0.9.0`,
  `postgres:18.1` and `valkey/valkey:8-alpine`.
- The database, downloads and backups are on named volumes (kept across
  redeploys) instead of `/home/user/pinepods` bind mounts.
- The database password is generated per account by the engine and stays
  stable across redeploys. `SERVER_URL` is the site's address, used in RSS feed
  links.
- `ready` (no-op) holds the deploy until the image's own `/api/health` check
  passes.
