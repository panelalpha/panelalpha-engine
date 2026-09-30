# Sharry (github.com/eikek/sharry)

File sharing with resumable up- and downloads, for registered users and
anonymous uploaders (via alias pages).

## What the recipe does

- `overrides/docker-compose.yml` runs `eikek0/sharry:v1.16.0` on port 9090
  beside `postgres:18-alpine`, and a no-op `ready` service so the deploy
  finishes only once `/api/v2/open/info/version` answers.
- `files/sharry.conf` sets only the base URL (`SHARRY_BASE_URL`, filled from
  the site's address), the bind address and the JDBC connection. Everything
  else is Sharry's own default.
- `hooks/prepare.sh` generates the PostgreSQL password once into
  `~/.panelalpha/sharry/db.env`.
- Sharry stores uploaded files in the database by default, so everything
  lives on the named volume `db_data` and survives redeploys.

## First use

Sign-up is open by default (`signup.mode = "open"`): open the site, choose
"Register" and create an account. Change the sign-up mode or maximum upload
size by editing `sharry.conf` in a fork if needed.
