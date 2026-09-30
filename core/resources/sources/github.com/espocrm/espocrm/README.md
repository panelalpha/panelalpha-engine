# EspoCRM (github.com/espocrm/espocrm)

CRM on PHP/Apache with MariaDB, served on :8080.

## Deploying

No variables are required. On the first boot the official image installs the
schema and creates the admin user with the image's defaults (`admin` /
`password`; it logs a warning about them). To choose the password instead,
set `ESPOCRM_ADMIN_PASSWORD` in the project's env vars before the first
deploy; it is applied only at install time.

Do not set `ESPOCRM_ADMIN_USERNAME` to anything but `admin`: upstream's
entrypoint creates the user under that name but then runs
`set-password admin`, so any other name fails the install (the container
restart-loops on `Running "install" action`).

## What the recipe does

- `overrides/docker-compose.yml` follows upstream's espocrm-docker compose on
  `espocrm/espocrm:10.0.9` and `mariadb:11.8`. The repository is the
  development tree; its grunt build failed in `npm ci` because
  package-lock.json does not match package.json at HEAD.
- The database password is `${ESPOCRM_DB_PASSWORD:?}`, generated per account
  by the engine and shared by the app and MariaDB.
- `data/`, `custom/` and `client/custom/` are named volumes (the EspoCRM 10
  layout; mounting `/var/www/html` whole is the legacy layout the image warns
  about). Later boots run the image's `migrate` action.
- The `daemon` service is upstream's `docker-daemon.sh` (scheduled jobs).
  The websocket service is left out.
- A no-op `ready` service holds `compose up` until Apache answers, which the
  image does only after the install has finished.
