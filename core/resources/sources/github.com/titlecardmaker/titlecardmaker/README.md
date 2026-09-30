# TitleCardMaker (github.com/titlecardmaker/titlecardmaker)

Title-card generator for Plex, Jellyfin and Emby with a web UI. One
FastAPI/uvicorn server on :4242, data in SQLite under `/config`.

## Deploying

No variables are required. Connections to Plex/Jellyfin/Emby/Sonarr/TMDb are
configured in the web UI after the first visit. Optional upstream env vars:
`TZ`, `PUID`, `PGID`, `UMASK`.

## What the recipe does

- Builds the repository's own `Dockerfile` (strategy `dockerfile`, port 4242).
- `overrides/docker-compose.override.yml` adds the named volume `tcm-config`
  at `/config`, which the app hardcodes in Docker (`backend/app/core/config.py`)
  and which upstream's compose always mounts; without it the container
  crash-loops on `PermissionError: [Errno 13] Permission denied: '/config'`.
- A healthcheck on `/api/healthcheck` and a no-op `ready` gate service make the
  deploy finish only once the app answers.
