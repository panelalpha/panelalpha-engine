# FeedLog (github.com/linkcraftstudio/feedlog)

A feedback board, public roadmap and changelog: Nuxt, better-auth, Postgres
with pgvector. The repository ships `compose.yml` with upstream's published
image (`ghcr.io/linkcraftstudio/feedlog`) and `pgvector/pgvector:pg17`; the
stack is used as it is.

## Signing in

Open the site and click **Sign in** in the header (a modal on every page).
Sign in with the login the engine generates, `FEEDLOG_ADMIN_EMAIL` (default
`admin@example.com`) and `FEEDLOG_ADMIN_PASSWORD`: read it with
`GET /projects/{name}/app-credentials` (MCP `app_credentials_get`). That
account owns the workspace; the dashboard is at `/dashboard`.

## Why a recipe

FeedLog has no admin of its own. An email listed in `SYSTEM_ADMIN_EMAILS`
becomes the owner of the default workspace when it signs up, and every other
account is a plain user with no dashboard. The `.env` the engine makes from
`.env.example` lists `admin@example.com`, so the install belonged to whoever
signed up with that address first, and nobody deploying it was told.

`BETTER_AUTH_SECRET`, which signs the session cookies, is the published
`replace-me-with-a-32-char-random-string` in `.env.example` as well (not one
of the placeholders the engine replaces by itself).

## What the recipe does

- `credentials:` in `panelalpha.yaml`: the engine generates
  `FEEDLOG_ADMIN_EMAIL` and `FEEDLOG_ADMIN_PASSWORD` and writes them to
  `~/.panelalpha/app-credentials.env`.
- `hooks/prepare.sh` generates `BETTER_AUTH_SECRET` once per account into
  `~/.panelalpha/feedlog/auth.env` (as `FEEDLOG_AUTH_SECRET`). Not in
  `~/project`, which is emptied before every deploy.
- `overrides/docker-compose.override.yml`:
  - `app` reads both files (`env_file`) and runs
    `files/panelalpha/feedlog/start.sh`, which exports
    `SYSTEM_ADMIN_EMAILS=$FEEDLOG_ADMIN_EMAIL` and
    `BETTER_AUTH_SECRET=$FEEDLOG_AUTH_SECRET` and then execs upstream's own
    `node .output/server/index.mjs`. Exported there because `compose.yml`
    sets both under `environment:`, which `env_file` cannot beat. It also gets
    a healthcheck on upstream's `/health`.
  - a one-shot `admin-login` (the same image) runs
    `files/panelalpha/feedlog/create-admin.mjs`: FeedLog's own sign-up endpoint
    (`POST /api/auth/sign-up/email`) with the generated login, so the app
    hashes the password and its own bootstrap hook makes the account the
    owner. When an account with that email exists already it answers
    `USER_ALREADY_EXISTS` and nothing is changed, so a password changed in the
    app is never reset by a restart or a redeploy.
  - a no-op `ready` (`alpine:3`) waits for `admin-login` to finish, so
    `docker compose up -d` returns, and the deploy ends, only once the owner
    exists and nobody can take that email first.

Sign-up stays open to everyone else, as upstream has it; those accounts are
plain users. Changing the secret on an existing install signs its users out
once.

## Checked locally

2026-11-19, `ghcr.io/linkcraftstudio/feedlog:latest`, the repository's
`compose.yml` (a37e0b39) with this override, a fake `app-credentials.env`:

- `admin-login`: "signed up admin@example.com as the owner of the workspace";
  `up -d` returned after it (44 s from cold).
- `POST /api/auth/sign-in/email` with the generated login: 200, and
  `GET /api/admin/onboarding` (needs the `moderate` permission) with that
  session: 200.
- signing up again as `admin@example.com`: `USER_ALREADY_EXISTS_USE_ANOTHER_EMAIL`;
  a wrong password: 401; a visitor who signs up is a plain user (401 on the
  admin API).
- after `restart app`: 200 / 200. A password changed in the app
  (`/api/auth/change-password`) survived `up -d --force-recreate`
  (`admin-login`: "exists already; left alone"), and the generated one was
  refused after it.
- the app's `BETTER_AUTH_SECRET` was the generated one and `SYSTEM_ADMIN_EMAILS`
  the generated email.

## Files

| File | Why |
|---|---|
| `panelalpha.yaml` | the `credentials:` the engine generates |
| `hooks/prepare.sh` | `BETTER_AUTH_SECRET` once per account in `~/.panelalpha/feedlog/auth.env` |
| `overrides/docker-compose.override.yml` | the login and the secret for `app`, the one-shot `admin-login` and the `ready` gate |
| `files/panelalpha/feedlog/start.sh` | exports `SYSTEM_ADMIN_EMAILS` and `BETTER_AUTH_SECRET`, then upstream's command |
| `files/panelalpha/feedlog/create-admin.mjs` | signs the generated login up, only while no account has that email |
