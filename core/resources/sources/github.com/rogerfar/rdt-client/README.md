# rdt-client (github.com/rogerfar/rdt-client)

Debrid-service download manager with a fake qBittorrent / SABnzbd API for the
*arr apps. One .NET process (Angular UI + API) on `:6500`, SQLite in
`/data/db`.

## What the recipe does

- `overrides/docker-compose.yml` replaces the source build (the repo's
  Dockerfile compiles the Angular client and the .NET server) with upstream's
  published `rogerfar/rdtclient:2.0.142`. `/data/db` (login, settings incl.
  the provider API key, torrents) and `/data/downloads` are named volumes.
- `hooks/prepare.sh` writes `~/.panelalpha/rdtclient/admin.env` once
  (`RDTCLIENT_ADMIN_USER=admin`, 24 random alphanumerics).
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

`admin` / the password in `~/.panelalpha/rdtclient/admin.env`. After login,
pick the debrid provider and paste its API key in Settings. In Sonarr/Radarr
add a qBittorrent download client pointing at the site over HTTPS (port 443)
with the same username and password.

Settings > "Authentication Type: None" is honoured by upstream and makes the
whole API public; do not use it on a public address.
