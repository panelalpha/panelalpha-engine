# KaraDAV (github.com/kd2org/karadav)

WebDAV and NextCloud-compatible file server: plain PHP (no Composer) over
SQLite, the application in `www/`, the database and files under `data/`.

## Deploying

Deploy as is, open `/login.php` and sign in with the login the engine
generates (`KARADAV_ADMIN_USER`, default `admin`, and
`KARADAV_ADMIN_PASSWORD`): read it with `GET /projects/{name}/app-credentials`
(MCP `app_credentials_get`). That user is KaraDAV's admin (Manage users); its
files are at `/files/<user>/` over WebDAV.

## Why a recipe

KaraDAV has no installer. `init.php`, on the first request, creates the
database with the admin `demo` / `karadavdemo` (published in
`doc/INSTALL.md`) and signs that request in, showing it the password. On
PanelAlpha the first request is the engine's health check, so a visitor met a
sign-in form whose only account was the published one, which anyone with the
address could use.

## What the recipe does

- `docroot: www`, as detection already finds it (stated, so the recipe's
  `panelalpha/` stays outside the document root).
- `overrides/docker-compose.override.yml` adds the engine's
  `app-credentials.env` to the app service's `env_file`.
- A command at install and upgrade, before Apache serves, runs
  `files/panelalpha/karadav-admin.php`: it loads KaraDAV's own `init.php`
  from the CLI, which creates the database as the first request would, and
  then, only while `demo` still has the password `karadavdemo`, gives it the
  generated username and password through KaraDAV's `User::edit` (which also
  moves its file storage to the new name). A password changed in KaraDAV is
  never touched.

Nothing else changes. As before, `data/` (the database and the files) lives in
the checkout; a database a redeploy starts anew gets the generated login
again.

Checked locally (2026-11-19, 3c242a3e, PHP 8.3 with gettext, the command and
then upstream's `www/_router.php` under `php -S`): the sign-in form with the
generated login answered 302 and `/` 200; `demo` / `karadavdemo` and a wrong
password got the form again; after the password was changed in Manage users,
a restart that ran the command again kept the new one and refused the
generated one.
