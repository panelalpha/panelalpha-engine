# Cherry (github.com/haishanh/cherry)

Bookmark manager: SvelteKit on Node behind nginx in one image (s6-overlay),
SQLite under `/data`.

## Deploying

Deploy as is, open `/signin` and sign in with the login the engine generates
(`CHERRY_ADMIN_EMAIL`, default `admin@example.com`, and
`CHERRY_ADMIN_PASSWORD`): read it with `GET /projects/{name}/app-credentials`
(MCP `app_credentials_get`). That account is an admin (Settings > Admin).

## Why a recipe

Upstream has no default login and public sign-up is off unless
`ENABLE_PUBLIC_REGISTRATION=1`; its README makes the first user with
`cherry create-user <email> <password> --admin`. Without it a new instance is a
sign-in form nobody can pass. And `JWT_SECRET` has no default: with none set,
signing in fails even with a valid user (the session token is an HMAC with no
key).

## What the recipe does

- Builds the repository's Dockerfile with the dockerfile strategy, as detection
  already did; `/data` stays on its named volume.
- `overrides/docker-compose.override.yml` runs
  `files/panelalpha/cherry-start.sh` as the entrypoint and mounts the engine's
  `app-credentials.env` read-only at `/pa/app-credentials.env`.
- `cherry-start.sh`, as root, before the image's own `/init`:
  - sets `PORT` back to the image's own `5173`: the image's nginx listens on
    8000 (what it EXPOSEs and the engine routes) and proxies to Node on 5173,
    and the generated service's `PORT=8000` made Node race nginx for 8000
    (whichever lost crash-looped; a 502 when nginx won);
  - sets `JWT_SECRET` to a SHA-256 of `cherry-jwt:` plus the account's
    `PA_INSTANCE_SECRET` (stable across deploys, so sessions survive) unless
    the project sets `JWT_SECRET` itself;
  - runs `cherry list-users` as the `nodejs` user (which creates and migrates
    the database) and, only while it has no user at all, `cherry create-user
    <CHERRY_ADMIN_EMAIL> <CHERRY_ADMIN_PASSWORD> --admin`. A password changed
    in Cherry, or users added there, are never touched; the login is read in
    a subshell, so the server's environment never has it.

Public sign-up stays off, as upstream ships it; set
`ENABLE_PUBLIC_REGISTRATION=1` in the project's environment to open it.

Checked locally (2026-11-19, 5458bfee, the image built from the repository's
Dockerfile, the override over a stand-in for the generated service): the
generated login signs in (`POST /api/auth/signin` 204, `/api/user/v1` shows
the admin), a wrong password is 401, sign-up is 400 as upstream's; after the
password was changed in Cherry, a restart and a recreate kept the new one and
left the generated one refused; a session cookie survived the restart.
