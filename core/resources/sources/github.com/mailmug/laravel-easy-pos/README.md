# Laravel Easy POS (github.com/mailmug/laravel-easy-pos)

Point of sale and invoicing: Laravel 11 and Filament 4 on MySQL. The laravel
recipe deploys it as is; this recipe only gives it its first admin.

## Deploying

Deploy as is, open `/admin/login` and sign in with the login the engine
generates (`EASY_POS_ADMIN_EMAIL`, default `admin@example.com`, and
`EASY_POS_ADMIN_PASSWORD`): read it with `GET /projects/{name}/app-credentials`
(MCP `app_credentials_get`). Change it in the panel under the user menu >
Profile.

## Why a recipe

Upstream's installer is the `/` route (`InstallController`): it runs migrate
and `db:seed`, whose UserSeeder makes `admin@admin.com` with the password
`pass@123` published in the README, but only while there is no `settings`
table. The laravel recipe's `migrate` makes that table before the site is
reachable, so nothing was ever seeded and the panel had no account to sign in
with.

## What the recipe does

- `overrides/docker-compose.override.yml` adds the engine's
  `app-credentials.env` to the app service's `env_file`.
- `easy-pos-admin` (install and upgrade, after the laravel recipe's
  `migrate`) runs `files/panelalpha/easy-pos-admin.php`, which boots the app
  and, only while the database has no user at all:
  - runs the installer's `SettingsSeeder` (currency symbol, site name and
    email, as upstream's install sets them);
  - creates the admin with Filament's own `make:filament-user` from
    `EASY_POS_ADMIN_EMAIL` / `EASY_POS_ADMIN_PASSWORD`.

  A database that has users is left alone, so a password changed in the app
  stays changed. The one exception is upstream's published admin: if
  `admin@admin.com` is still there with `pass@123` (a database seeded the
  upstream way), it gets the generated login instead.

The demo products and customers are not seeded: their seeders need Faker,
which is a dev dependency, and the build runs `composer install --no-dev`.

Checked locally (2026-11-19, 40299656, PHP 8.3, MariaDB 11, the laravel
recipe's build, install and start commands): the Filament login (Livewire
`authenticate`) with the generated login reached the dashboard;
`admin@admin.com` / `pass@123` and a wrong password were refused; a password
changed under Profile survived a restart and a redeploy that ran the upgrade
stage, and the generated one was refused after it; a database seeded with
upstream's UserSeeder had its published admin moved to the generated login.
