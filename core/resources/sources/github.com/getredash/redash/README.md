# Redash (github.com/getredash/redash)

SQL queries, visualizations and dashboards. Flask + RQ, Postgres, Redis.

Plain deploy: the repository's `Dockerfile` is built and the webpack frontend
step is OOM-killed (`Killed`, `ELIFECYCLE ... exit code 137`) in a 2500 MB
account. The repository's `compose.yaml` is the development stack
(`dev_server`, source bind mounts); upstream's production compose lives in
`getredash/setup`.

## What the recipe does

- `overrides/docker-compose.yml`: `getredash/setup`'s `data/compose.yaml` on
  the release image `redash/redash:26.9.0`: `app` (gunicorn on :5000,
  2 web workers), `scheduler`, one `worker` serving every queue upstream
  splits over three workers (`queries`, `scheduled_queries`, `schemas`,
  `periodic`, `emails`, `default`), `postgres:17-alpine`, `redis:7-alpine`.
  The upstream nginx is dropped; the engine proxies :5000.
- One-shot `redash-init`: `manage.py database create_tables` (acts only on an
  empty database) then `manage.py db upgrade` for a newer image.
- `hooks/prepare.sh` writes `REDASH_COOKIE_SECRET`, `REDASH_SECRET_KEY`,
  `POSTGRES_PASSWORD` and `REDASH_DATABASE_URL` once to
  `~/.panelalpha/redash/redash.env`, as upstream's `setup.sh` does.
- `REDASH_HOST` is the site's address (links in emails).
- A no-op `ready` service waits for `/ping`, so the deploy ends once the app
  answers.

## First run

Upstream's: `/setup` asks for the first admin and the organization name.
