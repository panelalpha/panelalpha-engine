# Radarr (github.com/radarr/radarr)

Movie collection manager (a Sonarr fork). One .NET process (UI + REST API) on
`:7878`, SQLite in `/config`. Built from the Sonarr recipe
(`../../sonarr/sonarr/`); read its README for the reasoning, this one lists
only what differs.

## What the recipe does

- `overrides/docker-compose.yml` replaces the source build with
  `linuxserver/radarr:6.4.4` (current stable v6; Servarr publishes no image,
  the repo's default branch is `develop`). `/config` and `/movies` are named
  volumes, so settings, the login and the library survive redeploys.
- Auth is pinned by environment (`RADARR__AUTH__METHOD=Forms`,
  `RADARR__AUTH__REQUIRED=Enabled`), so the first-visitor "set up
  authentication" modal never appears. Port, bind address and URL base are
  pinned the same way.
- The login is declared in `panelalpha.yaml` (`credentials:`): the engine
  generates `RADARR_ADMIN_USER=admin` and a random password once, keeps them
  on the project and writes `~/.panelalpha/app-credentials.env` before the
  prepare hook on every deploy. `adopt_from` takes the password an account
  deployed before this already has from `~/.panelalpha/radarr/admin.env`.
- `files/panelalpha-seed.sh` (the `seed` service, same image) reads the API
  key from `/config/config.xml` and, only if `GET /api/v3/config/host` has no
  `username`, PUTs the whole resource back with the login set. It chowns
  `/movies` to PUID 1000 so it can be added as a root folder. `ready` gates
  `compose up` on it.

## Login

`GET /projects/{name}/app-credentials` returns the username and password (the
MCP tool `app_credentials_get`). They are what the seed created: a password
changed later in the UI is not reflected there. The API key is in
Settings > General. Indexers and download clients are runtime configuration.
