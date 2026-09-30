# FacilMap (github.com/FacilMap/facilmap)

Collaborative map editor on OpenStreetMap: Node.js on :8080 with MariaDB.

## What the recipe does

- `overrides/docker-compose.yml` runs `facilmap/facilmap:4.1` (release v4.1.2;
  the repository HEAD is 5.0.0-alpha) with a `mariadb:11` sidecar in utf8mb4,
  as the upstream Docker docs show. FacilMap creates its tables on first boot;
  `ready` makes `compose up -d` wait until it answers.
- `hooks/prepare.sh` writes the database password once to
  `~/.panelalpha/facilmap/secrets.env`.
- Database and tile cache are named volumes.

The repository's own compose is an internal test stack whose Dockerfile runs
install, type-check, lint, tests and both builds in one step; it ran out of
memory at 4096 MB.

## Optional keys

Set as project environment variables; the map works without them:
`ORS_TOKEN` / `MAPBOX_TOKEN` (routing), `MAXMIND_USER_ID` +
`MAXMIND_LICENSE_KEY` (geoip start position), `LIMA_LABS_TOKEN` (hi-dpi tiles).
