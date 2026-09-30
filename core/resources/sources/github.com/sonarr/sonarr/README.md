# Sonarr (github.com/sonarr/sonarr)

TV series PVR. One .NET process (UI + REST API) on `:8989`, SQLite in
`/config`. This is the template for the rest of the *arr family (Radarr,
Prowlarr, Lidarr, Whisparr): they share Servarr's config and auth
code, so the same four files work with the names changed.

## What the recipe does

- `overrides/docker-compose.yml` replaces the source build with
  `linuxserver/sonarr:4.0.20` (stable v4; Servarr publishes no image, the repo's
  default branch is v5-develop). `/config` and `/tv` are named volumes, so
  settings, the login and the library survive redeploys.
- **Auth is pinned by environment, not config.xml.** Servarr reads
  `<APP>__<SECTION>__<KEY>` env vars over config.xml and the UI
  (`Bootstrap.GetConfiguration()`, `AuthOptions`):
  `SONARR__AUTH__METHOD=Forms`, `SONARR__AUTH__REQUIRED=Enabled`. Forms from
  the first boot means the "set up authentication" first-visitor modal never
  appears, and `Enabled` cannot be relaxed to "disabled for local addresses",
  which behind the proxy would open it to everyone. Port, bind address and
  URL base are pinned the same way.
- `hooks/prepare.sh` writes `~/.panelalpha/sonarr/admin.env` once
  (`SONARR_ADMIN_USER=admin`, random hex password).
- `files/panelalpha-seed.sh` runs as the `seed` service (same image: it has
  curl, jq, xmlstarlet) after the app is healthy (`/ping`). It reads the API
  key Sonarr generated into `/config/config.xml`, and if
  `GET /api/v3/config/host` has no `username`, sends that resource back with
  `username`/`password`/`passwordConfirmation` set via
  `PUT /api/v3/config/host/1`. It never touches an existing user, so a
  password changed in the UI stays changed. It also chowns `/tv` to PUID 1000
  so it can be added as a root folder. `ready` gates `compose up` on it.

## Copying it for a sibling

Change the image, the port (Radarr 7878, Prowlarr 9696, Lidarr 8686,
Whisparr 6969), the env prefix (`RADARR__`, ...), the API
version (`/api/v1` for Prowlarr and Lidarr) and the library volume
(`/movies`, `/music`, ...; Prowlarr has none). Keep the whole-resource PUT:
the endpoint saves every field it receives.

## Login

`admin` / the password in `~/.panelalpha/sonarr/admin.env`. The API key is in
Settings > General. Indexers and download clients are runtime configuration.
