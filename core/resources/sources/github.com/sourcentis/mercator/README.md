# Mercator (github.com/sourcentis/mercator)

Information system mapping (ANSSI's method): Laravel 13 on PHP-FPM and nginx
under supervisord in the repository's own image, MySQL beside it.

## Deploying

Deploy as is, open `/login` and sign in with the login the engine generates
(`MERCATOR_ADMIN_EMAIL`, `admin@<the site's domain>`, and
`MERCATOR_ADMIN_PASSWORD`): read it with `GET /projects/{name}/app-credentials`
(MCP `app_credentials_get`). Change the password under the user menu >
Change password.

## Why a recipe

The image's entrypoint (`docker/entrypoint.sh`) migrates on every start but
seeds only with `SEED_DATABASE=1` or `USE_DEMO_DATA=1`, so a new database had
no roles and no user, and sign-up is off: nobody could sign in. Upstream's
seeders make the admin `admin@admin.com` with the password `password`, which
its README publishes.

## What the recipe does

- `overrides/docker-compose.override.yml` adds the engine's
  `app-credentials.env` to the app service's `env_file` and sets its command
  to `files/panelalpha/mercator-start.sh` followed by the image's own
  `supervisord` command. The image's entrypoint migrates, then execs it.
- `mercator-start.sh` runs `files/panelalpha/mercator-admin.php` before
  `supervisord` starts nginx and PHP-FPM, so the site never answers with the
  published admin in place. The PHP file boots the app and, only while the
  database has no user, runs upstream's `DatabaseSeeder` (perimeters,
  permissions, roles, their links and the admin with the Admin role; no demo
  data unless `USE_DEMO_DATA` is set), then gives that admin the generated
  login as both its `login` and its email, through the User model.
- A database that has users is left alone, so a password changed in the app
  stays changed. The one exception is the published admin: if
  `admin@admin.com` is still there with `password` (a database seeded the
  upstream way), it gets the generated login instead.
- `hooks/prepare.sh` makes the two mounted files readable by the image's
  `mercator` user.

The admin keeps upstream's other values (granularity 3, language `fr`); the
language can be changed in the profile. The email is `admin@{host}` rather
than `admin@example.com` so it is an address whose domain exists.

A new password set in the app must have upper and lower case letters, digits
and a symbol (upstream's rule); the generated one is only letters and digits,
which is fine for signing in.

Checked locally (2026-11-19, d43a0b6d, the image built from the repository's
Dockerfile, `.env` from `.env.example` with a MariaDB 11 sidecar, the override
over a stand-in for the generated service): the generated login signs in
(`POST /login` 302 to `/home`, `/profile/password` 200, `/admin/users` 200),
`admin@admin.com` / `password` and a wrong password are refused; a password
changed under `/profile/password` survived a restart (the entrypoint migrated
again) and a recreate, and the generated one was refused after it; a database
seeded upstream's way (`SEED_DATABASE=1`) had its published admin moved to the
generated login before the site answered.
