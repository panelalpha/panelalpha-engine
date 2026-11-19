# INTERCEPT (github.com/smittix/intercept)

SDR signal monitoring web UI: Flask on gunicorn + gevent in one Debian image
with the decoders built in, SQLite under `instance/`.

## Deploying

Deploy as is (the build compiles the decoders and takes about 20 minutes), open
`/login` and sign in with the login the engine generates
(`INTERCEPT_ADMIN_USERNAME`, default `admin`, and `INTERCEPT_ADMIN_PASSWORD`):
read it with `GET /projects/{name}/app-credentials` (MCP `app_credentials_get`).
No SDR hardware is attached, so the decoders have nothing to read.

## What the recipe does

- `overrides/docker-compose.yml` builds the repository's Dockerfile on port
  5050 with `instance/` on the named volume `intercept-instance` (kept across
  redeploys), `.env` and the engine's login as `env_file` and the wrapper below
  as the entrypoint. The repository's own compose file (privileged, the host's
  USB devices) is not used.
- `files/panelalpha-intercept-start.sh` passes `INTERCEPT_ADMIN_PASSWORD` only
  while `instance/intercept.db` has no user, so INTERCEPT creates its admin
  with the engine's login (no forced password change), and drops it once the
  admin exists: INTERCEPT would otherwise set it on the admin on every start
  and undo a password changed in the app. Then it runs the image's
  `start.sh`.
