# Kanboard (github.com/kanboard/kanboard)

Kanban project management: PHP on nginx and PHP-FPM in one Alpine image,
SQLite under `data/` by default.

## Deploying

Deploy as is, open `/login` and sign in with the login the engine generates
(`KANBOARD_ADMIN_USERNAME`, default `admin`, and `KANBOARD_ADMIN_PASSWORD`):
read it with `GET /projects/{name}/app-credentials` (MCP `app_credentials_get`).

## What the recipe does

- `overrides/docker-compose.yml` builds the repository's Dockerfile on port 80
  (published as 8080), with `data/` and `plugins/` on the named volumes
  `kanboard-data` and `kanboard-plugins` (kept across redeploys), the engine's
  login as `env_file` and the wrapper below as the entrypoint.
- `files/panelalpha-kanboard-start.sh` runs Kanboard's migrations (a new
  database gets the built-in `admin` / `admin`), sets the engine's username and
  password on that admin while its password is still `admin`, then execs
  Kanboard's own `/usr/local/bin/entrypoint.sh`. A password changed in Kanboard
  is never overwritten. `*.sh` is in Kanboard's `.dockerignore`, so the script
  is never in the image or under its web root.
