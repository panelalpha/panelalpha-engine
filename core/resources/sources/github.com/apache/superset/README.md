# Apache Superset (github.com/apache/superset)

Data exploration and dashboards, Flask + React.

Plain deploy: the repository's `Dockerfile` is built and the frontend webpack
step dies with `cannot allocate memory` in a 2500 MB account. The repository's
compose files build the checkout or run a development stack.

## What the recipe does

- `overrides/docker-compose.yml`: upstream's `docker-compose-image-tag.yml` on
  the release image `apache/superset:6.1.0`: `app` (gunicorn on :8088),
  one-shot `superset-init` (`docker-init.sh`: migrations, admin user, roles),
  `superset-worker` and `superset-worker-beat` (Celery, alerts and reports),
  `postgres:17`, `redis:7`. The containers use the image's own
  `/app/docker/*.sh` scripts, not the checkout's (HEAD differs from 6.1.0).
- `files/panelalpha/superset_config.py`: upstream's 6.1.0
  `docker/pythonpath_dev/superset_config.py`, unchanged (Postgres from the
  `DATABASE_*` variables, Redis cache, Celery config).
- `files/panelalpha/requirements-local.txt`: the release image ships no
  Postgres driver; `docker-bootstrap.sh` installs it for the web app and init
  only, so the worker and beat get the same pin (`psycopg2-binary==2.9.9`,
  6.1.0's `[postgres]` extra) through upstream's `requirements-local.txt` hook.
- `files/panelalpha/set-admin-login.py`: see "The admin's login" below.
- `hooks/prepare.sh` writes `SUPERSET_SECRET_KEY` and the database password
  once to `~/.panelalpha/superset/superset.env`.
- `SUPERSET_ENV=production`, `FLASK_DEBUG=false`; example datasets are not
  loaded (`SUPERSET_LOAD_EXAMPLES=no`, they download from GitHub on first
  boot).
- A no-op `ready` service waits for `/health`, so the deploy ends after init.

## The admin's login

Sign in at `/login/` with the login the engine generates: `admin`
(`SUPERSET_ADMIN_USERNAME`, only reported: `docker-init.sh` always names its
admin `admin`) and `ADMIN_PASSWORD`, read with
`GET /projects/{name}/app-credentials` (MCP `app_credentials_get`).

- `superset-init` reads `../.panelalpha/app-credentials.env`, so upstream's
  `docker-init.sh` runs `fab create-admin` with the engine's `ADMIN_PASSWORD`
  instead of its default `admin`. On later deploys it reports
  `User already exists admin` and leaves the account alone, so a password
  changed in Superset is never overwritten.
- After it, `files/panelalpha/set-admin-login.py` (Flask-AppBuilder's own
  security manager) gives the engine's password to an admin an older deploy of
  this recipe created as `admin` / `admin`, only while it still has `admin`.
  A failure there leaves `admin` / `admin` and says so in the `superset-init`
  log; it never holds the services back.
