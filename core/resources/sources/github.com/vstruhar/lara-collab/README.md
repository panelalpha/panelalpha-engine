# LaraCollab (github.com/vstruhar/lara-collab)

Project management (projects, tasks, time logging, invoices): Laravel 13,
Inertia and React on MySQL. The laravel recipe deploys it as is; this recipe
only gives it its first admin.

## Deploying

Deploy as is, open `/login` and sign in with the login the engine generates
(`LARACOLLAB_ADMIN_EMAIL`, `admin@<the site's domain>`, and
`LARACOLLAB_ADMIN_PASSWORD`): read it with
`GET /projects/{name}/app-credentials` (MCP `app_credentials_get`). Change it
under the user menu > My profile.

## Why a recipe

Upstream installs with `php artisan migrate --seed`: `DatabaseSeeder` makes the
roles, permissions, labels, priorities, currencies and countries, then asks
"Seed development data?" and, answered no, `ProductionSeeder` makes the admin
from `ADMIN_EMAIL` / `ADMIN_PASSWORD`, which `.env.example` publishes as
`admin@mail.com` / `password`. The laravel recipe runs `migrate` without
`--seed`, so the database had no roles and no user, and there is no sign-up:
nobody could sign in.

## What the recipe does

- `overrides/docker-compose.override.yml` adds the engine's
  `app-credentials.env` to the app service's `env_file`.
- `laracollab-admin` (install and upgrade, after the laravel recipe's
  `migrate`) runs `files/panelalpha/laracollab-admin.php`, which boots the app
  and, only while the database has no user at all, sets `auth.admin.email` /
  `auth.admin.password` to the generated login and runs upstream's seeders:
  `DatabaseSeeder` non-interactively (so its production branch) when there are
  no roles yet, else `ProductionSeeder` alone.
- A database that has users is left alone, so a password changed in the app
  stays changed. The one exception is the published admin: if
  `admin@mail.com` is still there with `password` (a database seeded the
  upstream way), it gets the generated login instead.

The email is `admin@{host}`, not `admin@example.com`: the profile form
validates it with `email:rfc,dns`, and example.com's null MX fails that, so
the admin could not have saved the profile or a new password.

The development data (demo users, clients, projects, tasks) is not seeded: its
seeders need Faker, a dev dependency the build does not install.

Checked locally (2026-11-19, 17da3fa5, PHP 8.4, MariaDB 11, the laravel
recipe's build, install and start commands): the generated login signs in
(`POST /login` 302 to `/dashboard`, `/dashboard` 200); `admin@mail.com` /
`password` and a wrong password are refused; a password changed under
My profile survived a restart and a redeploy that ran the upgrade stage, and
the generated one was refused after it; a database seeded upstream's way had
its published admin moved to the generated login.
