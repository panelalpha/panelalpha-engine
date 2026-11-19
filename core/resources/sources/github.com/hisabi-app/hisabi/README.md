# Hisabi (github.com/hisabi-app/hisabi)

Personal finance tracker: Laravel 12 with Inertia and React on MySQL. The
laravel recipe deploys it as is; this recipe only gives it its user.

## Deploying

Deploy as is, open `/login` and sign in with the login the engine generates
(`HISABI_ADMIN_EMAIL`, default `admin@example.com`, and
`HISABI_ADMIN_PASSWORD`): read it with `GET /projects/{name}/app-credentials`
(MCP `app_credentials_get`). Hisabi has no password change in the app.

## Why a recipe

Hisabi has no sign-up. Upstream makes its one user with
`php artisan hisabi:install`, which asks for a name, an email and a password
on a terminal (its `install.sh` runs it from one), so a new instance was a
sign-in form nobody could pass.

## What the recipe does

- `overrides/docker-compose.override.yml` adds the engine's
  `app-credentials.env` to the app service's `env_file`.
- `hisabi-admin` (install and upgrade, after the laravel recipe's `migrate`)
  runs `files/panelalpha/hisabi-admin.php`, which boots the app and, only
  while the database has no user at all, does what `hisabi:install` does with
  its default answers: `db:seed` (the default categories and brands, which
  the command offers with "yes" preselected; skipped when categories exist)
  and the user `Admin` with the generated email and a bcrypt hash of the
  generated password.

A database that has a user is left alone, so a password changed on the server
stays changed. Nothing else changes.

Checked locally (2026-11-19, 73e9d27f, PHP 8.4, MariaDB 11, the laravel
recipe's build, install and start commands, the frontend built with npm): the
generated login signed in (`POST /login` 302 to `/`, `/` 200), a wrong
password was refused; after the password was changed on the server a restart
that ran the install and upgrade commands again kept the new one and refused
the generated one.
