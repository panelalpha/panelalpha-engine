# Borg Backup Server (github.com/marcpope/borgbackupserver)

A web console for borg backups: PHP on Apache with MariaDB, ClickHouse and an
sshd for the agents, all in the published `marcpope/borgbackupserver` image,
with every bit of data on the `bbs-data` volume.

## Deploying

Deploy as is, open `/login` and sign in as `admin` with the password the
engine generates (`ADMIN_PASS`; `BBS_ADMIN_USER` is always `admin`): read it
with `GET /projects/{name}/app-credentials` (MCP `app_credentials_get`).

## What the recipe does

- The repository's `docker-compose.yml` runs as before (web UI on 80,
  published as 8080; sshd on 22 as 2222).
- `overrides/docker-compose.override.yml` adds the engine's login as
  `env_file`. On a fresh install the entrypoint sets `ADMIN_PASS` on the
  built-in `admin`; without it, it made up a random password and printed it
  only to the container log, which a visitor never sees.
- The entrypoint uses `ADMIN_PASS` on a fresh install only, so a password
  changed under Profile stays changed across restarts and redeploys. An
  install made before this recipe keeps the password it was given then.
