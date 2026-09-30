# Jellysweep (github.com/jon4hz/jellysweep)

Cleans up old media from a Jellyfin server, with a web UI where users see what
is scheduled for deletion and request to keep it. Starts in dry-run mode.

## Deploying

Set these project environment variables, then deploy:

- `JELLYSWEEP_JELLYFIN_URL`, `JELLYSWEEP_JELLYFIN_API_KEY`
- `JELLYSWEEP_SONARR_URL` + `JELLYSWEEP_SONARR_API_KEY` and
  `JELLYSWEEP_RADARR_URL` + `JELLYSWEEP_RADARR_API_KEY` (the upstream README
  says either, but v0.16.1 refuses to start without both: its config defaults
  create both sections, so both are validated)
- `JELLYSWEEP_JELLYSTAT_URL` + `JELLYSWEEP_JELLYSTAT_API_KEY`, or
  `JELLYSWEEP_STREAMYSTATS_URL` + `JELLYSWEEP_STREAMYSTATS_SERVER_ID`

Optional: `JELLYSWEEP_JELLYSEERR_URL` + `_API_KEY`, `JELLYSWEEP_DRY_RUN=false`
to really delete, and any other `JELLYSWEEP_*` setting from the upstream README.
Without the required ones the deploy fails with:

```
jellysweep: missing project environment variable(s): JELLYSWEEP_JELLYFIN_URL ... Set them and redeploy.
```

Library names and per-library rules live in `~/.panelalpha/jellysweep/config.yml`
(created on the first deploy with "Movies" and "TV Shows"; edit it to match
your Jellyfin libraries and redeploy).

## What the recipe does

- `overrides/docker-compose.yml` runs `ghcr.io/jon4hz/jellysweep:v0.16.1` on
  port 3002 with `/app/data` (SQLite) on the named volume `jellysweep-data`,
  `JELLYSWEEP_SERVER_URL` set to the public address.
- `hooks/prepare.sh` generates `JELLYSWEEP_SESSION_KEY` and copies the starter
  config into `~/.panelalpha/jellysweep/` once.
- `env-check` (one-shot) fails the deploy while a required variable is unset;
  `ready` holds the deploy until `/health` answers.
