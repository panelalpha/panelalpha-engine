# Lidarr (github.com/lidarr/lidarr)

Music collection PVR. One .NET process (UI + REST API `/api/v1`) on `:8686`,
SQLite in `/config`. A sibling of the Sonarr recipe
(`github.com/sonarr/sonarr`): same Servarr config and auth code, same four
files with the names changed.

## What the recipe does

- `overrides/docker-compose.yml` replaces the source build with
  `linuxserver/lidarr:3.1.0` (current stable; Servarr publishes no image, the
  repo's default branch is `develop`). `/config` and `/music` are named
  volumes, so settings, the login and the library survive redeploys.
- **Auth is pinned by environment**: `LIDARR__AUTH__METHOD=Forms`,
  `LIDARR__AUTH__REQUIRED=Enabled`. The "set up authentication" first-visitor
  modal never appears, and auth cannot be relaxed to "disabled for local
  addresses", which behind the proxy would open it to everyone. Port, bind
  address and URL base are pinned the same way.
- `hooks/prepare.sh` writes `~/.panelalpha/lidarr/admin.env` once
  (`LIDARR_ADMIN_USER=admin`, random hex password).
- `files/panelalpha-seed.sh` runs as the `seed` service (same image: curl, jq,
  xmlstarlet) after the app is healthy (`/ping`). It reads the API key from
  `/config/config.xml` and, if `GET /api/v1/config/host` has no `username`,
  sends the whole resource back with the user set via
  `PUT /api/v1/config/host/1`. An existing user is never touched. It also
  chowns `/music` to PUID 1000 so it can be added as a root folder. `ready`
  gates `compose up` on it.

## Login

`admin` / the password in `~/.panelalpha/lidarr/admin.env`. The API key is in
Settings > General. Adding artists needs Lidarr's metadata server
(api.lidarr.audio); indexers and download clients are runtime configuration.
