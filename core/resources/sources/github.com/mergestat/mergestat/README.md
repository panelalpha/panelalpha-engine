# MergeStat (github.com/mergestat/mergestat)

SQL over git repositories: PostgreSQL 14, a Go worker that syncs repositories
into it, PostGraphile and a Next.js console (port 3300), from the
repository's `docker-compose.yaml` and its published images.

## Deploying

Deploy as is, open `/login` and sign in with the login the engine generates
(`MERGESTAT_DB_USER`, `postgres`, and `MERGESTAT_DB_PASSWORD`): read it with
`GET /projects/{name}/app-credentials` (MCP `app_credentials_get`). The
console's login is a PostgreSQL user; this one is the database's superuser,
as upstream has it.

## Why a recipe

The compose file defaults the postgres password to `password`, in
`POSTGRES_PASSWORD` and in every service's connection string, so every deploy
signed in as the database superuser with a password published in the
repository. It also defaults `JWT_SECRET` to `secret`: the console signs its
session token with it and PostGraphile runs each query as the token's `role`
claim, so anyone could mint a superuser session without any password
(checked: a token signed with `secret` read the tables). `ENCRYPTION_SECRET`,
which encrypts the git host tokens MergeStat stores, defaulted to `password`.

## What the recipe does

- `hooks/prepare.sh` generates `MERGESTAT_JWT_SECRET` and
  `MERGESTAT_ENCRYPTION_SECRET` once, in `~/.panelalpha/mergestat/secrets.env`
  (kept across redeploys; the encryption secret must never change).
- `overrides/docker-compose.override.yml` gives every service the engine's
  `app-credentials.env` and that file as `env_file` (read by Compose, so file
  permissions inside the non-root containers do not matter) and runs it
  through `files/panelalpha/mergestat-run.sh <service>`, which puts them where
  the service reads them and execs the image's own entrypoint:
  - postgres: `POSTGRES_USER` / `POSTGRES_PASSWORD` (used when the volume is
    new);
  - worker: `POSTGRES_CONNECTION`, `ENCRYPTION_SECRET`; an account whose
    database still has the published `password` gets the generated one
    (`ALTER USER`), once;
  - graphql: the compose file's PostGraphile flags with the connection string
    and `--jwt-secret` replaced, `ENCRYPTION_SECRET`;
  - ui: `POSTGRES_CONNECTION`, `JWT_SECRET`.
- The images, ports, volumes and every other setting are the compose file's.

MergeStat has no password change of its own; a password changed with SQL
(`ALTER USER`) would also have to be the stored one, or the worker and the API
lose the database. An account deployed before this recipe gets a new
`ENCRYPTION_SECRET`, so git host tokens it stored must be entered again.

Checked locally (2026-11-19, 3719edfc, images 2.3.2-beta, the repository's
compose file with its host ports moved, and this override): the generated
login signs in (`POST /api/admin-auth` 200, a `repos` query through
`/api/graphql` answers as `postgres`); `postgres` / `password` is 401 and a
token forged with `secret` is 403 `invalid signature` (without the recipe the
same two signed in and read the tables); the login survived a restart and a
recreate; a database created by the upstream compose file was moved from
`password` to the generated password on the first start with the recipe.
