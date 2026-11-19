# pyLoad (github.com/pyload/pyload)

Download manager with a web UI: Python, SQLite, settings and accounts under
`/config`.

## Deploying

Deploy as is, open `/login` and sign in with the login the engine generates
(`PYLOAD_ADMIN_USERNAME`, default `admin`, and `PYLOAD_ADMIN_PASSWORD`): read it
with `GET /projects/{name}/app-credentials` (MCP `app_credentials_get`).

## What the recipe does

- The repository's Dockerfile builds a pyLoad whose web UI listens on
  `localhost` only, so `overrides/docker-compose.yml` runs the image upstream's
  README lists instead, `linuxserver/pyload-ng:0.5.0b3.dev101-ls255`, on port
  8000, with `/config` and `/downloads` on the named volumes `pyload-config`
  and `pyload-downloads` (kept across redeploys), the engine's login as
  `env_file` and a `ready` service so `compose up` waits for the web UI.
- `files/panelalpha-pyload-login.sh` is installed as a linuxserver custom init
  script (`/custom-cont-init.d/`, which the image runs only when executable;
  the entrypoint installs it with mode 0755 and then runs the image's `/init`).
  It runs after the image has written `/config/settings/pyload.cfg` and before
  pyLoad starts. On a new `/config` it lets pyLoad create its database with a
  dry run (`pyload --dry-run`, which gives it the built-in `pyload` / `pyload`),
  then renames that user to the engine's username and sets the engine's
  password with pyLoad's own password hashing, only while the password is
  still `pyload`. A password changed in pyLoad is never overwritten.
