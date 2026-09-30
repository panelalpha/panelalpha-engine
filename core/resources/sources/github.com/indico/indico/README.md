# Indico (github.com/indico/indico)

Event, conference and meeting management (Flask/uWSGI, Celery, Postgres, Redis).

## Deploying

No variables are required; give the project about 2.5 GB of memory (~800 MB
at idle). The first visit redirects to `/bootstrap`, upstream's page that
creates the first administrator.

Optional: `INDICO_TIMEZONE` (UTC), `SMTP_HOST`, `SMTP_PORT` (25), `SMTP_USER`,
`SMTP_PASSWORD`, `SMTP_USE_TLS` (false), `INDICO_NO_REPLY_EMAIL`,
`INDICO_SUPPORT_EMAIL`.

## What the recipe does

- `overrides/docker-compose.yml` is upstream's `indico-prod` setup from
  indico/indico-containers on `getindico/indico:3.3.13` (current release):
  uWSGI web, Celery worker and beat, Redis, Postgres 15 and nginx serving the
  static files. The web container prepares the database on first boot.
- `files/panelalpha-indico/` holds that setup's `indico.conf` (BASE_URL, SMTP
  and secrets read from the environment), `logging.yaml` and `nginx.conf`.
- `hooks/prepare.sh` writes the database password and SECRET_KEY once to
  `~/.panelalpha/indico/indico.env` (0600).
- Attachments, customizations, logs, static files, Postgres and Redis data are
  named volumes.

## Limitations

LaTeX (PDF books of abstracts/contributions) is off: upstream runs it through
podman in a privileged container. Indico answers 404 to requests whose host
is not BASE_URL, so `http://127.0.0.1:8080/` inside the account is a 404.
