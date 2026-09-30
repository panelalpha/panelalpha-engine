# rdt-client (github.com/rogerfar/rdt-client)

Debrid-service download manager with a fake qBittorrent / SABnzbd API for the
*arr apps. One .NET process (Angular UI + API) on `:6500`, SQLite in
`/data/db`.

## What the recipe does

- `overrides/docker-compose.yml` replaces the source build (the repo's
  Dockerfile compiles the Angular client and the .NET server) with upstream's
  published `rogerfar/rdtclient:2.0.142`. `/data/db` (login, settings incl.
  the provider API key, torrents) and `/data/downloads` are named volumes.
- The login is declared in `panelalpha.yaml` (`credentials:`): the engine
  generates `RDTCLIENT_ADMIN_USER=admin` and a random 24-character
  alphanumeric password once and writes `~/.panelalpha/app-credentials.env`
  before the prepare hook on every deploy (`adopt_from` keeps the password of
  an account seeded from `~/.panelalpha/rdtclient/admin.env`).
- `files/panelalpha/rdtclient-seed.sh` runs as `seed` once the app is healthy:
  `IsLoggedIn` 402 (no user) -> `POST /Api/Authentication/Create`; 403 (user
  exists) or 200 (auth switched off by the owner) -> left alone.
- `files/panelalpha/rdtclient-proxy.conf`: `proxy` is the only published
  service and starts after `seed` succeeded, so the setup page is never
  reachable from outside. It returns 403 for `/Api/Authentication/Create`
  and requires a session for `/hub` (SignalR, no `[Authorize]` upstream,
  broadcasts the torrent list) and `/api/v2/app/preferences` (anonymous
  upstream, returns the login name and download path).

## Login and *arr setup

`GET /projects/{name}/app-credentials` (MCP `app_credentials_get`) returns the
username and password the seed created. After login,
pick the debrid provider and paste its API key in Settings. In Sonarr/Radarr
add a qBittorrent download client pointing at the site over HTTPS (port 443)
with the same username and password.

Settings > "Authentication Type: None" is honoured by upstream and makes the
whole API public; do not use it on a public address.
