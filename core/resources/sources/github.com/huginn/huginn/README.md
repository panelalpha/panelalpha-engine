# Huginn (github.com/huginn/huginn)

Agents that watch the web and act on events: a Rails app on :3000, a threaded
background worker and PostgreSQL.

## What the recipe does

- `overrides/docker-compose.yml`: upstream's
  `docker/single-process/postgresql.yml` on
  `huginn/huginn-single-process:v2026.09.22` and `postgres:17-alpine`.
- The web container creates, migrates and seeds the database on boot, as the
  image ships it: the seed creates the `admin` user with a random password
  printed once in the `app` container's log (set `SEED_USERNAME` /
  `SEED_PASSWORD` in the project's environment before the first deploy to
  choose them). Sign-up needs the invitation code, `try-huginn` unless
  `INVITATION_CODE` is set.
- `APP_SECRET_TOKEN` and the database password are generated per account by
  the engine and stay stable across redeploys. `DOMAIN` is the site's address.
- Optional settings (`SMTP_*`, `INVITATION_CODE`, `TIMEZONE`, ...) are passed
  through from the project's environment.
- Data on the `db-data` volume.
- A no-op `ready` service waits for Huginn to answer, so the deploy ends after
  the migrations.
