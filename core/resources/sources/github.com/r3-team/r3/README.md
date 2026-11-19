# REI3 (github.com/r3-team/r3)

A low-code business application platform: one Go binary with its web UI
embedded, on PostgreSQL. The recipe is the vendor's Docker deployment
(rei3.de/docker_x64): `postgres:16` and an image built from
`github.com/r3-team/r3_docker`, release 3.12.8.

## Deploying

Deploy as is, open the site and sign in with the login the engine generates
(`REI3_ADMIN_USERNAME`, default `admin`, and `REI3_ADMIN_PASSWORD`): read it
with `GET /projects/{name}/app-credentials` (MCP `app_credentials_get`). A new
instance starts in maintenance mode, in which only admins can sign in; switch it
to production in the admin pages.

## The login

A new database gets REI3's built-in admin `admin` with the password `admin`
(`db_initialize`: `login.CreateAdmin("admin", "admin")`), which nobody
deploying it was told and anyone with the address could use.

- The `admin-login` one-shot in `overrides/docker-compose.yml` runs once the
  app is healthy (REI3 creates that admin before it opens its port) and runs
  `files/panelalpha/rei3/admin-login.sql` with psql against the database.
- The SQL gives the engine's username and password to the login `admin` only
  while its password is still `admin`, hashed the way REI3 does it (hex
  sha256 of a 32-character salt and the password, `login.GenerateSaltHash`).
  A password changed in REI3 is never touched.
- `ready` waits for it, so the deploy ends with the login in place. A failure
  leaves `admin` / `admin`, logs a line and does not hold the site back.

SQL rather than REI3's CLI: `-newadmin user:pass` only adds a second admin and
leaves `admin` / `admin` in place, and REI3 has no other way to change a login
from outside the running app.

Checked locally (2026-11-19, 38f5823, release 3.12.8): `POST /api/auth` (the
same `login_auth.User` the web UI's login uses) with the engine's login, then
the REST API with its token; `admin` / `admin` refused; a password changed in
the database the way REI3 stores it survived a restart and a redeploy (down
and up, volumes kept); a different username from the engine replaced `admin`.
