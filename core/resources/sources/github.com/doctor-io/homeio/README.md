# Homeio (github.com/doctor-io/homeio)

Home server dashboard: Next.js with a custom Node server on PostgreSQL, the
repository's own `docker-compose.yml` (services `homeio` and `db`).

## Deploying

Deploy as is, open `/login` and sign in with the login the engine generates
(`HOMEIO_ADMIN_USER`, default `admin`, and `HOMEIO_ADMIN_PASSWORD`): read it
with `GET /projects/{name}/app-credentials` (MCP `app_credentials_get`).
Homeio has one account and no password change of its own.

The engine leaves out the compose file's Docker socket and `/DATA` host
mounts, so Homeio's app store cannot install apps here (it logs that the
socket is not mounted).

## Why a recipe

Homeio has no default login: its first visitor registers the one account at
`/register`. Its middleware sends visitors there only while its own lookup, a
fetch of `/api/auth/status` at the site's public address (the forwarded host
and scheme) from inside the container, says there is no user; when that fetch
fails it assumes an account exists. On PanelAlpha it fails, so a new instance
showed only the sign-in form, `/register` redirected to it, and nobody could
get in.

## What the recipe does

- `overrides/docker-compose.override.yml` runs
  `files/panelalpha/homeio-start.sh` in place of the image's command, with
  Docker's init as PID 1, and mounts the engine's `app-credentials.env`
  read-only at `/pa/app-credentials.env`.
- `homeio-start.sh` starts `homeio-register.mjs` in the background with the
  login (read in that subshell only, so the server's environment never has
  it), then runs upstream's `docker-entrypoint.sh` unchanged.
- `homeio-register.mjs` waits for the server on its own port, asks
  `/api/auth/status` there and, only while `hasUsers` is false, registers the
  login through Homeio's own `POST /api/auth/register`, which also creates
  the data folders and the app catalog as the page's registration does, and
  refuses every registration after it (403). An account that exists is never
  touched.

Nothing else changes.

Checked locally (2026-11-19, 7dd9fb2d, the repository's compose file without
the host mounts the engine drops, with this override): the plain image behind
proxy headers whose host the container cannot reach answered `/` 307 to
`/login` and `/register` 307 to `/login`, as on PanelAlpha; with the recipe the
helper registered the account, the generated login signed in
(`POST /api/auth/login` 200, `/api/auth/me` 200, `/` 200, also behind those
proxy headers), a wrong password was 401, a visitor's `POST /api/auth/register`
was 403, and after a restart the helper left the account alone and the login
still worked.
