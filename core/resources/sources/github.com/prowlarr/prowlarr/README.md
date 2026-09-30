# Prowlarr (github.com/prowlarr/prowlarr)

Indexer manager for the *arr stack. One .NET process (UI + REST API
`/api/v1`) on `:9696`, SQLite in `/config`. Copied from the Sonarr recipe
(`github.com/sonarr/sonarr`); the differences are the image, port, env
prefix, API version and no library volume.

## What the recipe does

- `overrides/docker-compose.yml` replaces the source build with
  `linuxserver/prowlarr:2.6.5` (current stable; Servarr publishes no image,
  the repo's default branch is `develop`). `/config` is a named volume, so
  settings, the login, indexers and apps survive redeploys.
- **Auth is pinned by environment:** `PROWLARR__AUTH__METHOD=Forms`,
  `PROWLARR__AUTH__REQUIRED=Enabled`, so the first-visitor "set up
  authentication" modal never appears and auth cannot be relaxed to
  "disabled for local addresses" (every client is local behind the proxy).
  Port, bind address and URL base are pinned the same way.
- `hooks/prepare.sh` writes `~/.panelalpha/prowlarr/admin.env` once
  (`PROWLARR_ADMIN_USER=admin`, random hex password).
- `files/panelalpha-seed.sh` runs as the `seed` service after `/ping` is
  healthy: reads the API key from `/config/config.xml` and, if
  `GET /api/v1/config/host` has no `username`, sends the whole resource back
  with the user set via `PUT /api/v1/config/host/1`. An existing user is never
  touched. `ready` gates `compose up` on it.

## Login

`admin` / the password in `~/.panelalpha/prowlarr/admin.env`. The API key is
in Settings > General. Indexers and applications are runtime configuration.
