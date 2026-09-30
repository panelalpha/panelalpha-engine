# Swiparr (github.com/m3sserstudi0s/swiparr)

Swipe through a media library (Jellyfin, Emby, Plex or TMDB) alone or with
friends to decide what to watch.

## What the recipe does

- `overrides/docker-compose.yml` runs `ghcr.io/m3sserstudi0s/swiparr:1.5.1` on
  port 4321; `/app/data` (SQLite database and the generated auth secret) is on
  the named volume `swiparr-data`, kept across redeploys.
- `APP_PUBLIC_URL` is the account's public address.
- `ready` holds the deploy until `/api/health` answers.

## Configuring

Set project environment variables and redeploy, for example
`JELLYFIN_URL=https://jellyfin.example.com`, or `PROVIDER=tmdb` with
`TMDB_ACCESS_TOKEN`, or `PROVIDER_LOCK=false` to choose the server in the UI.
See the upstream README's environment variable matrix.
