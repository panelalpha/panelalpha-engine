# wger (github.com/wger-project/wger)

Workout, nutrition and body-weight manager, Django.

Plain deploy: the `django` recipe builds the checkout and the container
restart-loops with `ImproperlyConfigured: Set the DJANGO_DB_ENGINE environment
variable`. The repository is the source tree; the supported deployment is
`github.com/wger-project/docker`.

## What the recipe does

- `overrides/docker-compose.yml`: that repository's production stack on the
  release image `wger/server:2.7.0`: `app` = nginx (upstream's `nginx.conf`
  minus the PowerSync location, shipped as `files/panelalpha/wger-nginx.conf`)
  serving `/static` and `/media` and proxying to `web` (gunicorn :8000),
  `celery_worker` and `celery_beat` (exercise/ingredient sync from wger.de),
  `postgres:15-alpine`, `redis:7-alpine`. Environment values are upstream's
  `config/prod.env`, with `SITE_URL`/`CSRF_TRUSTED_ORIGINS` set to
  `${PA_PUBLIC_URL}` and `X_FORWARDED_PROTO_HEADER_SET=True`.
- PowerSync (the mobile app's offline mode) is not included; the web UI and
  the REST API do not need it.
- 2 gunicorn workers instead of 3.
- Client IP: nginx takes the last `X-Forwarded-For` hop (the peer the engine
  vhost saw; a client-supplied value stays first) and passes wger
  that single address, with `AXES_IPWARE_PROXY_COUNT=0`. With upstream's
  count of 1 and the engine's longer chain, ipware returned `None`, so every
  failed login would have landed in one shared axes lockout bucket.
- `hooks/prepare.sh` writes `SECRET_KEY`, the database password and a fresh
  RS256 JWT keypair (the format of `manage.py generate-jwt-keys`, built with
  openssl + perl because the account has no python) once to
  `~/.panelalpha/wger/wger.env`. Upstream's `prod.env` ships a fixed keypair
  that the app itself warns about.
- `app` waits for `web` to be healthy (`/api/v2/version/`), so the deploy
  ends after the first-boot fixtures and migrations.

## First run

Upstream's: `wger bootstrap` creates the default admin account (see the wger
docs); registration is open (`ALLOW_REGISTRATION=True`).
