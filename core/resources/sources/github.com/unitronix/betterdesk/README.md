# BetterDesk (github.com/UNITRONIX/BetterDesk)

A RustDesk-compatible remote desktop server: a Go server (signal, relay and
the client API on 21114-21119) and a Node.js web console on 5000, both built
from the repository, with SQLite on the shared `rustdesk-data` and
`console-data` volumes.

## Deploying

Deploy as is, open `/login` and sign in with the login the engine generates
(`BETTERDESK_ADMIN_USERNAME`, default `admin`, and
`BETTERDESK_ADMIN_PASSWORD`): read it with
`GET /projects/{name}/app-credentials` (MCP `app_credentials_get`). The same
login works for RustDesk clients on the Go server's `/api/login`.

## What the recipe does

- The repository's `docker-compose.yml` runs as before (both images built
  from it; the engine drops the bind-mount `driver_opts` of `rustdesk-data`
  as for any project).
- `overrides/docker-compose.override.yml` adds the engine's login as
  `env_file` to `server` and `console`, and an entrypoint in each that copies
  `BETTERDESK_ADMIN_USERNAME` / `BETTERDESK_ADMIN_PASSWORD` into
  `ADMIN_USERNAME` / `ADMIN_PASSWORD`, `INIT_ADMIN_USER` / `INIT_ADMIN_PASS`
  (the Go server) and `DEFAULT_ADMIN_USERNAME` / `DEFAULT_ADMIN_PASSWORD`
  (the console), then execs the image's own entrypoint (`/sbin/tini --
  /server-entrypoint.sh` with the compose file's command, and `/sbin/tini --
  /console-entrypoint.sh`). The compose file sets those variables in
  `environment:` from `.env`, empty, which an `env_file` cannot beat; with
  them empty the bootstrap made up a random password and kept it only in
  `/opt/rustdesk/.admin_credentials`.
- Both images seed only a database with no user, so a password changed in
  the console stays changed across restarts and redeploys.
