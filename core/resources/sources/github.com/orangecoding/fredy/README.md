# Fredy (github.com/orangecoding/fredy)

Real estate finder: Node.js with a React UI, a stealth Chromium for scraping,
SQLite under `/db` and its config under `/conf`.

## Deploying

Deploy as is, open `/#/login` (the UI's login route) and sign in
with the login the engine generates (`FREDY_ADMIN_USERNAME`, default `admin`,
and `FREDY_ADMIN_PASSWORD`): read it with `GET /projects/{name}/app-credentials`
(MCP `app_credentials_get`).

## What the recipe does

- `overrides/docker-compose.yml` builds the repository's Dockerfile on port 9998
  (the repository's compose file bind-mounts `./conf` and `./db`, so the engine
  used the dockerfile strategy for it), with `/db` and `/conf` on the named
  volumes `fredy-db` and `fredy-conf` (kept across redeploys), the engine's
  login as `env_file` and the wrapper below as the entrypoint (and the image's
  `node index.js` as the command, which an entrypoint set in compose clears).
- `files/panelalpha-fredy-start.sh` runs `files/panelalpha-fredy-login.mjs`
  from `/fredy`, then execs Fredy's own `/usr/local/bin/docker-entrypoint.sh`
  (which hands `/db` and `/conf` to `node` and starts Fredy under tini).
- `files/panelalpha-fredy-login.mjs` uses Fredy's own modules: it creates the
  config if missing, runs the migrations and `ensureAdminUserExists()` (a new
  database gets the built-in `admin` / `admin`, as on Fredy's first start), then
  sets the engine's username and password on that admin with Fredy's
  `upsertUser()`, only while its password is still `admin`. A password changed
  in Fredy is never overwritten. Neither file is in the image: the Dockerfile
  copies only the files it names.
