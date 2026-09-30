# Cliparr (github.com/techsquidtv/cliparr)

Makes clips from media on a Plex or Jellyfin server. Node/Express with SQLite.

## Deploying

Nothing to set. Open the site and connect a Plex or Jellyfin server from the
web UI. Cliparr has no user accounts of its own (upstream README).

## What the recipe does

- `overrides/docker-compose.yml` runs `ghcr.io/techsquidtv/cliparr:2.0.2` on
  port 7171 with `/data` (SQLite) on the named volume `cliparr-data`, kept
  across redeploys. `ready` makes `compose up -d` wait for `/api/health`.
  Without it the engine builds the pnpm monorepo, which runs out of memory.
- `hooks/prepare.sh` generates `APP_KEY` once into
  `~/.panelalpha/cliparr/app.env` (0600). The server needs it to start and it
  encrypts stored media-server credentials, so it must never change.
