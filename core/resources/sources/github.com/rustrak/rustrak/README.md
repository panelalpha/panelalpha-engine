# Rustrak (github.com/rustrak/rustrak)

Sentry-compatible error tracking with a web dashboard: one Rust container on
:8080, SQLite in `/data`.

## Deploying

Registration is invite-only, so the first account comes from the optional
project environment variable `CREATE_SUPERUSER` (`email:password`, writing any
`$` as `$$`), as in the upstream docs. It is read at startup and ignored once a
user exists. Sign in at `/login`.

## What the recipe does

- `overrides/docker-compose.yml` runs `rustrak/rustrak-server:v0.15.1` (the
  SQLite build, upstream's default compose) with `/data` on the named volume
  `rustrak-data` and `PUBLIC_URL` set to the site's address.
- `hooks/prepare.sh` writes a 64-character `SESSION_SECRET_KEY` once to
  `~/.panelalpha/rustrak/secrets.env`. Without the recipe the engine fills the
  repo's `${SESSION_SECRET_KEY:?}` with 48 characters and Rustrak exits
  ("at least 64 are required").
