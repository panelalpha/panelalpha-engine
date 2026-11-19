# onWatch (github.com/onllm-dev/onwatch)

Usage and quota tracker for AI API subscriptions (Anthropic, Codex, Copilot,
OpenRouter and more): one Go binary in a distroless image, SQLite in
`/data/onwatch.db`.

## Deploying

Deploy as is, open `/login` and sign in with the login the engine generates
(`ONWATCH_ADMIN_USER`, default `admin`, and `ONWATCH_ADMIN_PASS`): read it with
`GET /projects/{name}/app-credentials` (MCP `app_credentials_get`). Change it
under Settings > Security. Provider keys go in the project's env_vars (see
`.env.example`), and some providers can be added under Settings.

## Why a recipe

The repository's `docker-compose.yml` mounts a workstation directory, so the
engine builds its `Dockerfile` instead and writes `.env` from `.env.example`.
That file sets `ONWATCH_ADMIN_USER=admin` and `ONWATCH_ADMIN_PASS=changeme`,
so every deploy signed in with a login published in the repository.

## What the recipe does

- `overrides/docker-compose.override.yml` adds the engine's
  `app-credentials.env` to the app service's `env_file`, after `.env`, so its
  `ONWATCH_ADMIN_USER` / `ONWATCH_ADMIN_PASS` win. These are the names onWatch
  reads; nothing in the app changes.
- onWatch itself keeps the login: on the first start it stores the password's
  hash in its database, and from then on the stored hash wins over the
  environment, so a password changed under Settings > Security survives a
  restart. A database still holding the published `changeme` hash takes the
  environment's password instead (upstream's own rule).

`/data` is not a volume (the Dockerfile declares none), so a redeploy that
recreates the container starts a new database, with the generated login again.

Checked locally (2026-11-19, 7e2343e9, the image built from the repository's
Dockerfile, `.env` from `.env.example`, the override over a stand-in for the
generated service): the generated login signs in (`POST /login` 302 to `/`,
`/api/providers` 200), `admin` / `changeme` is refused (302 to
`/login?error=invalid`, `/api/providers` 401); a password changed with
`PUT /api/password` survived a restart and the generated one was refused
after it.
