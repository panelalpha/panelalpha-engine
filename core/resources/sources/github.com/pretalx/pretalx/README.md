# pretalx (github.com/pretalx/pretalx)

A conference planner: call for papers, review, schedule, speaker pages. The
django recipe deploys it as is: `src/manage.py`, `migrate`, SQLite under
`src/data`, the frontend built into `static.dist` by the build, served with
`runserver`.

## Signing in

Open `/orga/login/` and sign in with the login the engine generates,
`PRETALX_ADMIN_EMAIL` (default `admin@example.com`) and
`PRETALX_ADMIN_PASSWORD`: read it with `GET /projects/{name}/app-credentials`
(MCP `app_credentials_get`). The account is an administrator (may create
events and see every event) and is on the team of a first organiser,
"The Conference Organiser" (`conforg`). Create the first event at
`/orga/event/new/`.

## Why a recipe

The public pages worked; the organiser area did not, for three reasons.

1. **No account.** The organiser login form hides its sign-up
   (`hide_register`), speakers only register through an event's call for
   papers and there is no event, and the first administrator only comes from
   `pretalx init`, a server command.
2. **No Redis.** pretalx keeps its cache and its sessions in Redis
   (`PRETALX_REDIS`, default `redis://127.0.0.1:6379/1`) and has no setting for
   another cache backend: `CACHES["default"]` is always Django's `RedisCache`.
   Nothing listens there, so a login, which saves a session, was a 500
   (`ConnectionRefusedError` in `session_login`).
3. **CSRF.** pretalx's only trusted CSRF origin is its `SITE_URL`
   (`http://localhost` by default), and behind the account's web server
   Django sees plain http (pretalx reads no forwarded-proto header). The
   browser sends `Origin: https://<domain>`, which matches neither, so every
   form post, the login included, was a 403.

## What the recipe does

- `credentials:` in `panelalpha.yaml`: the engine generates
  `PRETALX_ADMIN_EMAIL` and `PRETALX_ADMIN_PASSWORD`;
  `overrides/docker-compose.override.yml` adds
  `~/.panelalpha/app-credentials.env` to the app's `env_file`.
- `pretalx-admin` (install and upgrade, after the django recipe's `migrate`)
  runs `files/panelalpha/pretalx-admin.sh`: only while there is no
  administrator at all (read with pretalx's own `shell`), pretalx's own
  `init --noinput` with the generated email and password
  (`DJANGO_SUPERUSER_*`) and upstream's example organiser name and slug.
  An administrator that exists is never touched, so a password changed in the
  app stays changed.
- `overrides/docker-compose.override.yml` adds a `redis:7-alpine` for cache
  and sessions (no persistence: a redeploy signs everyone out), and
  `env:` points `PRETALX_REDIS` at it.
- `env:` sets `PRETALX_SITE_URL` to the site's public address, which is also
  what pretalx builds its links and emails from.

The database is SQLite in `src/data`, inside `~/project`, as the django recipe
had it, so a redeploy (not a restart) starts with an empty database, and the
script then creates the administrator again with the same generated login.

## Checked locally

2026-11-19, 1510dea9, `python:3.13-slim` with the django recipe's steps
(`uv pip install .`, `collectstatic`, `migrate`, this script, `runserver`) and
this override, behind an nginx terminating TLS like the account's web server:

- `init` made the administrator and the organiser `conforg`.
- before the env changes: the login POST with the right password was a 403
  (CSRF), and with `PRETALX_SITE_URL` but no Redis a 500.
- with the recipe: `POST /orga/login/` with the generated login 302 to
  `/orga/event/`, and `GET /orga/event/new/` (administrators only) 200; a
  wrong password re-rendered the form (200) and `/orga/event/new/` 302 to the
  login.
- after `restart app` ("an administrator exists; left alone"): 200 again. A
  password changed in the app survived a recreate that ran the script again,
  and the generated one was refused after it.

## Files

| File | Why |
|---|---|
| `panelalpha.yaml` | `extends: django`, `PRETALX_SITE_URL` and `PRETALX_REDIS`, the `pretalx-admin` command and the `credentials:` |
| `overrides/docker-compose.override.yml` | the engine's login as `env_file`, and the Redis |
| `files/panelalpha/pretalx-admin.sh` | `init --noinput` with the generated login, only while there is no administrator |
