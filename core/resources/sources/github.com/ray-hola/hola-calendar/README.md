# Hola-Calendar (github.com/ray-hola/hola-calendar)

哗啦待办, a team calendar and task board in Chinese: one Python
standard-library server (`server.py`) and a static front end, SQLite under
`/app/data`.

## Deploying

Deploy as is, open `/` and sign in as `superadmin` with the password the
engine generates (`HOLA_ADMIN_PASSWORD`; `HOLA_ADMIN_USER` is always
`superadmin`): read it with `GET /projects/{name}/app-credentials` (MCP
`app_credentials_get`). The superadmin manages the other accounts and resets
their passwords.

## Why a recipe

The first start seeds `superadmin`, `test001`, `test002` and `test003` with
random passwords written only to `data/initial-accounts.txt` inside the
container, so nobody deploying it could sign in. And every POST, the login
included, is refused (403 "请求来源不匹配") when the browser's Origin is not
`127.0.0.1` / `localhost` or listed in `WORK_CALENDAR_ORIGINS`, so even the
right password failed from the site's own address.

## What the recipe does

- Builds the repository's Dockerfile with the dockerfile strategy, as detection
  already did; `/app/data` stays on its volume.
- `env:` sets `WORK_CALENDAR_ORIGINS` to the site's address
  (`${PA_PUBLIC_URL}`).
- `overrides/docker-compose.override.yml` runs
  `files/panelalpha/hola-start.sh` in place of the image's command and mounts
  the engine's `app-credentials.env` read-only at `/pa/app-credentials.env`.
- `hola-start.sh` runs `hola-login.py` with the login (in a subshell, so the
  server's environment never has it), then `python server.py`.
- `hola-login.py` opens the database with the server's own `Store`, which
  seeds a new database exactly as the server would, and sets the superadmin's
  password with `Store.set_password` only while it still has the password it
  was seeded with (a new database, or the one `initial-accounts.txt` lists
  still verifies). A password changed in the app is never touched. On a new
  database it writes `initial-accounts.txt` as the server does, without the
  superadmin's line.

The username stays `superadmin`: the code treats that id as the operator
account. The three test accounts and the sample project are seeded as
upstream seeds them. Nothing else changes.

Checked locally (2026-11-19, cde4b737, the image built from the repository's
Dockerfile, the override over a stand-in for the generated service): the
generated password signs in (`POST /api/login` 200, `/api/state` 200), a wrong
one is 401, an Origin not in `WORK_CALENDAR_ORIGINS` is 403; after the
password was changed in the app (`POST /api/password`) a restart and a
recreate kept the new one and refused the generated one; a database seeded by
the plain image first had its superadmin moved to the generated password.
