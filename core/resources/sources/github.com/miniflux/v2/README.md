# Miniflux (github.com/miniflux/v2)

Minimalist feed reader. One Go binary serving the UI and API from embedded
assets on :8080, PostgreSQL behind it and nothing else.

Detection: `go` — no root `Dockerfile` and no root compose file, so `go.mod`
decides. The host compile is correct and takes about 25 seconds; the container
then restart-loops (`app (Restarting (1) 17 seconds ago)`, nothing on 8080),
which is the `serving-unknown` this recipe turns into a served page. Everything
that is wrong is outside the checkout:

- `DATABASE_URL` defaults to a developer's local server
  (`user=postgres password=postgres dbname=miniflux2`), and `internal/cli`
  calls `printfAndExit` on `NewConnectionPool` and again on `store.Ping()`.
  There is no SQLite fallback — PostgreSQL is the only backend Miniflux has.
- `RUN_MIGRATIONS` defaults to `0`, so even a reachable empty database stops at
  `database.IsSchemaUpToDate()`.
- There is no sign-up page and no first-run wizard: without `CREATE_ADMIN` plus
  `ADMIN_USERNAME`/`ADMIN_PASSWORD`, a running Miniflux has no account to log
  into.

What the recipe adds:

- `panelalpha.yaml` — `extends: go`, keeping the host compile. The two-stage
  image in `packaging/docker/alpine/Dockerfile` produces the same binary and
  pulls a second toolchain to do it.
- `hooks/prepare.sh` writes `.env` (the generated app service already reads it
  through `env_file:`) with `DATABASE_URL`, `RUN_MIGRATIONS=1`, `CREATE_ADMIN=1`,
  `ADMIN_USERNAME=admin`, a generated `ADMIN_PASSWORD` and the matching
  `POSTGRES_*`. Guarded by `[ ! -f .env ]`: the database volume outlives the
  checkout. The admin credentials are also written to
  `~/project/.panelalpha-admin-password` (0600) — every Miniflux compose file
  published upstream installs `admin` / `test123`.
- `overrides/docker-compose.override.yml` adds `database`
  (postgres:17-alpine, named volume on the `PGDATA` path, healthcheck,
  account-sized settings), makes `app` wait on it, gives `app` a `/healthcheck`
  healthcheck and `mem_limit: 512m`, and adds a no-op `ready` service gated on
  `app: service_healthy` so `docker compose up -d` returns only once the 135
  migrations and the asset bundles are done and the port answers.

Two things the engine already gets right, and the recipe deliberately leaves
alone:

- `LISTEN_ADDR` defaults to `127.0.0.1:8080`, which inside a container is
  unreachable from the published port — but the framework compose writer sets
  `PORT=8080`, and `configParser.postParsing()` rewrites `LISTEN_ADDR` to
  `:8080` whenever `PORT` is set.
- `BASE_URL` arrives as one of the public-URL aliases the same writer sets from
  the account's domain. `environment:` outranks `env_file:`, so the account's
  real URL wins; nothing has to read it back out of the generated compose.

Sidecar mining reads *this recipe's own override file*, and that cost one
deploy. `RuntimeSidecars::exampleComposeFilenames()` globs `docker-compose.*.yml`
in the project directory, which matches `docker-compose.override.yml` — the log
line is `Adding backing services described in docker-compose.override.yml:
database`. The engine then copies `database` into the generated compose and has
`SidecarCredentials` write a DATABASE_URL onto the app service:
`postgres://miniflux:app@database:5432/miniflux` — the password is the fallback
for a bare `${POSTGRES_PASSWORD}` it cannot resolve, and there is no query
string, so lib/pq used its default `sslmode=require` and every boot ended in
`pq: SSL is not enabled on the server`. `environment:` beats `env_file:`, so
.env cannot win; a later compose file beats an earlier one, so the override
restating `DATABASE_URL: ${DATABASE_URL}` can, and does. Renaming the file is not
an escape — every name compose auto-loads matches one of the four globs.

Sidecar mining never sees the repository's own compose files, and should not:
`.devcontainer/docker-compose.yml` runs the app as `sleep infinity` with
`POSTGRES_HOST_AUTH_METHOD=trust`, and the three `contrib/docker-compose/*.yml`
all ship the password `secret` and the admin `admin` / `test123`. Neither path
is on the reader's candidate list (root `compose.yaml`/`docker-compose.yml`, or
a root `*.example.*` template), so the database here comes only from the
override.

No Redis (sessions live in PostgreSQL), no Apprise sidecar, no mail: none of
them is needed for Miniflux to run, and the notification and mail paths need
services the engine does not provide.
