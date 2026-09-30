# Koffan (github.com/pansalut/koffan)

Shared grocery list (Go, SQLite in `/data`). One password for the household,
no user accounts.

## What the recipe does

- `overrides/docker-compose.yml` replaces the source-build compose with
  `ghcr.io/pansalut/koffan:v2.15.0`. `/data` is a named volume.
- **The default password never applies.** Upstream falls back to
  `shopping123` when `APP_PASSWORD` is empty. `hooks/prepare.sh` writes a
  random `APP_PASSWORD` into `~/.panelalpha/koffan/app.env` once; the compose
  loads it with `env_file` and sets nothing that could shadow it. Koffan checks
  the login against the environment each time and stores no password.
- `API_TOKEN` is left unset (REST API off) and `DISABLE_AUTH` unset.

## Login

The password in `~/.panelalpha/koffan/app.env`. To change it, edit that file
and redeploy.

## Login limiter is off

`LOGIN_MAX_ATTEMPTS=0`. Koffan's limiter counts failures per client IP and has
no proxy-header option; behind the engine every visitor arrives from the same
internal address (measured: `IP 172.25.0.1 blocked until ...`), so six wrong
guesses from anyone locked out every member of the household for 30 minutes.
The generated password is 32 hex characters (128 bits), which no guess limit
needs to protect. If you replace it with a short password, reconsider this.
