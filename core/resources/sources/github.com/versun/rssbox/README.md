# RSSBox (github.com/versun/rssbox)

An RSS reader and translator on the Django admin: gunicorn on :8000, SQLite in
`data/db.sqlite3`, cron jobs in the same container for the feed updates.

## Deploying

Deploy as is, open `/login/` and sign in with the login the engine generates
(`RSSBOX_ADMIN_USERNAME`, default `admin`, and `RSSBOX_ADMIN_PASSWORD`): read
it with `GET /projects/{name}/app-credentials` (MCP `app_credentials_get`).
Change the password in the admin's user menu.

## Why a recipe

- **The login.** `scripts/init.py`, run by the image's `entrypoint.sh` on every
  start, creates the superuser `admin` with the password `rssbox` on a new
  database. Nobody deploying it was told, and anyone with the address could
  sign in.
- **CSRF.** `CSRF_TRUSTED_ORIGINS` defaults to `http://*`. A browser posts
  the login form with `Origin: https://<domain>`, the app sees plain http
  behind the engine's proxy, and Django refused every sign-in (403).
- **The database.** Nothing mounted `data/`, so a redeploy started over with a
  new database and the default admin.

## What the recipe does

- `overrides/docker-compose.yml` builds the repository's Dockerfile as the
  dockerfile strategy did, plus: the engine's `app-credentials.env` as
  `env_file`, `CSRF_TRUSTED_ORIGINS` and `SITE_URL` set to `${PA_PUBLIC_URL}`,
  `data/` on the named volume `rssbox-data`, and `panelalpha/rssbox/start.sh`
  as the CMD after the image's own `entrypoint.sh`.
- `files/panelalpha/rssbox/start.sh` runs `set-admin-login.py` through
  `manage.py shell`, then gunicorn. `set-admin-login.py` gives the engine's
  username and password to the user `admin` only while its password is still
  `rssbox`, with Django's own user model. A password changed in RSSBox is never
  touched.

Redis stays absent, as it was: the entrypoint waits 20 seconds for it and
carries on.

Checked locally (2026-11-19, 5f742b2): the admin login form, posted with an
https `Origin` as a browser behind the proxy sends it, reached the admin index
with the engine's login; `admin` / `rssbox` was refused; with the default
`CSRF_TRUSTED_ORIGINS` the same post was a 403; a password changed in the app
survived a restart and a redeploy (down and up, volume kept); a different
username from the engine replaced `admin`.
