# RUSEON Core (github.com/rusegal/ruseon-core)

A video surveillance and stream server: one Go binary with its React UI
embedded, BadgerDB in `/app/data`, recordings in `/app/recordings`. The
repository's `docker-compose.yml` builds its Dockerfile, and the engine runs it
as is.

## Deploying

Deploy as is, open the site and sign in with the login the engine generates
(`RUSEON_ADMIN_USERNAME`, default `admin`, and `RUSEON_ADMIN_PASSWORD`): read
it with `GET /projects/{name}/app-credentials` (MCP `app_credentials_get`).
Change the password in the users page.

## The login

On a database with no user, `pkg/auth.NewLocalAuthenticator` creates `admin`
with a random 16-character password and prints it only to the container's log,
which nobody deploying it was told. There is no environment variable, flag or
CLI for it.

- `overrides/docker-compose.override.yml` adds the one-shot `admin-seed`,
  which the server waits for (`service_completed_successfully`). It mounts the
  server's `ruseon_core_data` volume and reads `app-credentials.env`.
- `files/panelalpha/ruseon-core/seed.Dockerfile` builds
  `files/panelalpha/ruseon-core/seed` inside the repository's own Go module
  (it imports `internal/models` and `pkg/storage`), with the toolchain and
  base image of the repository's Dockerfile, and runs it as the same uid
  (10001) so the volume stays the server's.
- The seed opens the database the way the server does
  (`storage.NewStorage("data")`) and, only while it has no user, saves the
  admin with a bcrypt hash, as the server would. The server then finds a user
  and generates nothing. A password changed in RUSEON Core is never touched.

BadgerDB is one process at a time, so the seed runs to completion before the
server starts rather than beside it. It always exits 0: a failure leaves the
server to generate its admin as before, with a log line.

Checked locally (2026-11-19, 05b63df): `POST /api/login` with the engine's
login, then the admin-only `GET /api/users` with its token; a wrong password
was refused and the server printed no initial password; a password changed in
the app (`PUT /api/users/admin`) survived a restart and a redeploy (down and
up, volume kept) and the engine's was refused after it; a different username
from the engine was the admin's.

An account deployed before this recipe keeps the admin its server generated:
the seed sees a user and does nothing, so the engine's login does not match it.
