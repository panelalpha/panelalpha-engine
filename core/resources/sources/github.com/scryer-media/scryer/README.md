# Scryer (github.com/scryer-media/scryer)

Self-hosted media manager (movies, series, anime): one Rust binary with an
embedded web UI, SQLite in `/config`.

## Deploying

As upstream ships it, authentication is **off** and Scryer serves only
private/local clients. Anyone reaching the site from a public address gets
`403 {"error":"Scryer authentication is disabled; public unauthenticated access is blocked"}`.
Release builds refuse `SCRYER_ALLOW_UNAUTHENTICATED_PUBLIC_ACCESS` and
`SCRYER_UNAUTHENTICATED_PUBLIC_ACCESS_ALLOWLIST` ("unavailable in release builds").

To get in, set the project env var `SCRYER_RECOVERY_ADMIN_PASSWORD` and
redeploy. Login is then on for that boot: sign in as `recovery-admin` with
that password, set a password for `admin` in Settings > Users and enable form
login, then remove the variable and redeploy.

## What the recipe does

- `overrides/docker-compose.yml` runs `ghcr.io/scryer-media/scryer:0.21.11` on
  port 8080 with `/config` (database, settings, and the `encryption.key` Scryer
  generates on first run) on the named volume `scryer-config`, kept across
  redeploys. The healthcheck uses `/health/ready`, which is 500 when bootstrap
  failed (`/` still answers 200 with an error page), and `ready` makes
  `compose up -d` wait for it.
- `SCRYER_RECOVERY_ADMIN_PASSWORD` is passed through from the project's env
  vars, empty by default.
- Without the recipe the engine compiles the Rust workspace, which fails: the
  sigstore files `crates/scryer-application` embeds are generated and
  gitignored, and `docker/scryer.Dockerfile` only packages CI-built binaries.
