# Journiv (github.com/journiv/journiv-app)

Private journaling app: FastAPI + React on :8000, Celery worker and beat,
PostgreSQL and Valkey.

## Deploying

Nothing to set. Open the site and create the first account on Journiv's own
sign-up page. Any other Journiv setting (`DISABLE_SIGNUP`, `OIDC_*`, SMTP, ...)
can be set as a project environment variable and applies on the next deploy.

## What the recipe does

- `overrides/docker-compose.yml` replaces the repository's compose with the same
  services from `swalabtech/journiv-app:0.1.0-beta.26`, `postgres:18.1` and
  `valkey/valkey:9.0-alpine`, with `/data`, the database and Valkey on named
  volumes (kept across redeploys). The upstream `./data` bind mount is
  root-owned and the uid-1000 image cannot write it.
- `SECRET_KEY` and the database password are generated per account by the
  engine and stay stable across redeploys.
- `DOMAIN_NAME` is the site's host and `DOMAIN_SCHEME=https`.
- `CELERY_WORKER_CONCURRENCY=1`: the image ignores `command:` for the Celery
  roles, and uncapped the worker forks one child per host CPU and restart-loops
  at its memory limit.
- `ready` (no-op) holds the deploy until `/api/v1/health` answers.
