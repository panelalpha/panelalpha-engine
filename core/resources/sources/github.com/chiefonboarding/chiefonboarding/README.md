# ChiefOnboarding (github.com/chiefonboarding/ChiefOnboarding)

Employee onboarding platform, Django.

Plain deploy: the `django` recipe builds the checkout and the container
restart-loops with `ImproperlyConfigured: Set the SECRET_KEY environment
variable`. The repository's `docker-compose.yml` is a development stack
(`Dockerfile-dev`, `./back` bind mount, an S3 emulator).

## What the recipe does

- `overrides/docker-compose.yml`: upstream's `docs/deployment/docker.md` stack
  on the release image `chiefonboarding/chiefonboarding:v2.5.0` (supervisord
  runs collectstatic + migrate + gunicorn on :8000 and the django-q
  scheduler), `postgres:17-alpine` on a named volume. `ALLOWED_HOSTS` and
  `BASE_URL` come from the engine's public address.
- `hooks/prepare.sh` writes `SECRET_KEY`, the database password and
  `DATABASE_URL` once to `~/.panelalpha/chiefonboarding/chief.env`.
- Login lockout (django-axes) reads `X-Real-IP`, which the engine vhost sets
  to the peer it saw. Upstream reads the leftmost `X-Forwarded-For` entry,
  which a visitor can set (engine#319). Behind the shared
  `*.panelalpha.online` front that peer is the front itself; on a custom
  domain it is the visitor.
- A no-op `ready` service waits for gunicorn, so the deploy ends after the
  first-boot migrations.

## First run

Upstream's: the first visit opens `/setup/`, which creates the organisation
and the administrator. File uploads need S3-compatible object storage
(`AWS_*` variables, see upstream `docs/config/objectstorage.md`); the app
starts and runs without it.
