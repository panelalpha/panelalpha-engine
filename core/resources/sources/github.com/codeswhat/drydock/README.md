# Drydock (github.com/codeswhat/drydock)

Container update monitor: a Node API and a Vue UI in one image, its store
under `/store`.

## Deploying

Deploy as is, open `/login` and sign in with the login the engine generates
(`DRYDOCK_ADMIN_USER`, default `admin`, and `DRYDOCK_ADMIN_PASSWORD`): read it
with `GET /projects/{name}/app-credentials` (MCP `app_credentials_get`).

On a PanelAlpha account Drydock has no Docker socket, so its local watcher
finds no containers (it logs a retry every few seconds); a remote Docker host
is configured with `DD_WATCHER_<name>_HOST` (upstream's watcher docs).

## Why a recipe

A fresh Drydock refuses anonymous access unless `DD_ANONYMOUS_AUTH_CONFIRM=true`
and has no user store: its only login is `DD_AUTH_BASIC_<name>_USER` /
`DD_AUTH_BASIC_<name>_HASH`, an argon2id hash, from the environment. With none
set a new instance showed "No authentication methods configured" and nobody
could get in.

Behind the account's web server, which terminates TLS, Drydock also refused
every signed-in change (logout included) as cross-site: without
`DD_SERVER_TRUSTPROXY` it compares the browser's `https://` origin with the
`http://` it sees.

## What the recipe does

- Builds the repository's Dockerfile with the dockerfile strategy, as detection
  already did.
- `env:` sets `DD_SERVER_TRUSTPROXY=1` (one hop, the account's web server), so
  Express reads the proxy's `X-Forwarded-Proto`.
- `overrides/docker-compose.override.yml` runs
  `files/panelalpha/drydock-start.sh` under tini as the entrypoint (restating
  the image's `node dist/index.js` command) and mounts the engine's
  `app-credentials.env` read-only at `/pa/app-credentials.env`.
- `drydock-start.sh`, before the image's own `/usr/bin/entrypoint.sh`, hashes
  the generated password the way upstream's docs do (Node's
  `crypto.argon2Sync`, argon2id, m=65536 t=3 p=4) and exports
  `DD_AUTH_BASIC_ADMIN_USER` / `DD_AUTH_BASIC_ADMIN_HASH`; the password itself
  never reaches the server's environment. A `DD_AUTH_BASIC_ADMIN_USER` the
  project sets wins.

Drydock has no password change of its own: the login lives in the
environment, so the engine's stored values stay the login across restarts and
redeploys. Nothing else changes.

Checked locally (2026-11-19, ff11b8d5, the image built from the repository's
Dockerfile, the override over a stand-in for the generated service):
`GET /auth/status` lists the basic provider; the generated login signs in
(`POST /auth/login` with HTTP Basic 200, `GET /auth/user` 200), a wrong one is
401; behind a stand-in proxy (`X-Forwarded-Proto: https`) logout with the
https Origin was 200, and 403 "CSRF validation failed" without
`DD_SERVER_TRUSTPROXY`; a restart kept the login.
