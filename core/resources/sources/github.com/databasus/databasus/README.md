# Databasus (github.com/databasus/databasus)

Scheduled database backups (PostgreSQL, MySQL, MariaDB, MongoDB) to local or
cloud storage, with a web UI. One Go server on :4005 with an embedded
PostgreSQL for its own state.

## Deploying

No variables are required. The first visit opens Databasus's sign-up page;
the first account created administers the instance (upstream's first-run
behaviour).

## What the recipe does

- `overrides/docker-compose.yml` replaces the repository's development
  `docker-compose.yml` with upstream's README compose (Option 3):
  `databasus/databasus:v3.60.0`, `/databasus-data` on a named volume.
- No environment is passed. The repo's `.env.example` is a development file;
  its `DATABASE_DSN` would override the image's generated embedded-database
  password and break the migrations (the upstream Dockerfile strips it from the
  image for that reason).
- A `ready` gate waits for the image's own `databasus healthcheck`.
- Local backups are written inside `/databasus-data` as well, so they live on
  the same volume as the app's state; use cloud storage for off-host copies.
