# Whisparr (github.com/whisparr/whisparr)

*arr-family PVR, a Sonarr fork (v2). One .NET process (UI + REST API
`/api/v3`) on `:6969`, SQLite (`whisparr2.db`) in `/config`. Copied from the
Sonarr recipe; the one real difference is how auth is pinned.

## What the recipe does

- `overrides/docker-compose.yml` replaces the source build with
  `ghcr.io/hotio/whisparr:v2-2.2.0-release.231`, the current v2 release that
  the repository's default branch (`v2-develop`) produces. Servarr publishes no
  image and linuxserver has none for Whisparr. hotio's `v3` tags are the
  Radarr-based rewrite, a different application. `/config` and `/library` are
  named volumes, so settings, the login and the library survive redeploys.
- **Auth is pinned in config.xml, not env.** Whisparr v2 binds only
  `Whisparr:Postgres` from the environment (`Bootstrap.cs`); there is no
  `AuthOptions`, so `WHISPARR__AUTH__METHOD` does nothing.
  `files/panelalpha-init.sh` runs as the `init` service before the app on every
  deploy and sets `AuthenticationMethod=Forms` (unless it is already Forms or
  Basic), `AuthenticationRequired=Enabled`, `Port=6969`, `BindAddress=*` and an
  empty `UrlBase`. With method `None` Whisparr authenticates every request
  (`NoAuthenticationHandler`), and "disabled for local addresses" would open it
  to everyone behind the proxy. A change made in the UI holds until the next
  deploy, which re-pins it.
- The login is declared in `panelalpha.yaml` (`credentials:`): the engine
  generates `WHISPARR_ADMIN_USER=admin` and a random password once, keeps them
  on the project and writes `~/.panelalpha/app-credentials.env` before the
  prepare hook on every deploy. `adopt_from` takes the password an account
  deployed before this already has from `~/.panelalpha/whisparr/admin.env`.
- `files/panelalpha-seed.sh` runs as the `seed` service after the app is
  healthy (`/ping`). It reads the API key from `/config/config.xml` (with sed:
  the hotio image has no xmlstarlet), and if `GET /api/v3/config/host` has no
  `username`, sends that resource back with the credentials via
  `PUT /api/v3/config/host/1`. It never touches an existing user. `ready`
  gates `compose up` on it.

## Login

`GET /projects/{name}/app-credentials` returns the username and password (the
MCP tool `app_credentials_get`). They are what the seed created: a password
changed later in the UI is not reflected there. The API key is in
Settings > General. Indexers and download clients are runtime configuration.
