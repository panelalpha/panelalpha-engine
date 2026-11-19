# Edi Life OS (github.com/edrisranjbar/lifeos)

Personal dashboard (habits, notes, finance, kanban, mail): PHP 8.3 on Apache
with MySQL 8.4, from the repository's `docker-compose.yml`.

## Deploying

Deploy as is, open `/login.php` and sign in with the login the engine
generates (`LIFEOS_USERNAME`, default `admin`, and `LIFEOS_PASSWORD`): read it
with `GET /projects/{name}/app-credentials` (MCP `app_credentials_get`).
Change the password in the app's settings.

## Why a recipe

Life OS takes its first sign-in from `LIFEOS_USERNAME` / `LIFEOS_PASSWORD`,
which `docker-compose.yml` interpolates as `${LIFEOS_USERNAME:-}`. The engine
leaves `.env.example`'s placeholders (`me` / `change-me-to-a-long-password`)
to those empty defaults, so a new instance had no account at all: the first
sign-in failed with `Initial sign-in setup requires configuration: username`.

## What the recipe does

- `overrides/docker-compose.override.yml` runs
  `files/panelalpha/lifeos-start.sh` as the app service's entrypoint (with the
  image's `apache2-foreground` as its command) and mounts the engine's
  `app-credentials.env` read-only at `/pa/app-credentials.env`.
- `lifeos-start.sh` exports that file's `LIFEOS_USERNAME` / `LIFEOS_PASSWORD`
  and execs the image's own `docker-php-entrypoint`. The compose file's
  `environment:` beats an `env_file`, so they are set here.
- Life OS itself keeps the login: it writes it to MySQL only while it has no
  credentials yet, so a password changed in the app survives restarts and
  redeploys (the database is on the `mysql` volume).

Checked locally (2026-11-19, ae9308e1, the repository's compose file with
`.env` as the engine writes it, the placeholders commented out, and this
override): the generated login signs in (`POST /login.php` 302,
`/credentials.php` 200), `me` / `change-me-to-a-long-password` and a wrong
password are refused (200 with the form, `/credentials.php` 401); a password
changed through `/credentials.php` survived a restart and a recreate, and the
generated one was refused after it.
