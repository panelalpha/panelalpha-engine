# Free PMO (github.com/nafiesl/free-pmo)

Project management for freelancers and agencies: Laravel 8 on MySQL. The
laravel recipe deploys it as is; this recipe only gives it its first admin.

## Deploying

Deploy as is, open `/login` and sign in with the login the engine generates
(`FREE_PMO_ADMIN_EMAIL`, default `admin@example.com`, and
`FREE_PMO_ADMIN_PASSWORD`): read it with `GET /projects/{name}/app-credentials`
(MCP `app_credentials_get`). That user has the admin and worker roles, as the
installer gives them. Free PMO's own password change takes 6 to 15
characters; the generated password is 24.

## Why a recipe

Upstream's installer is the `/app-install` form (`InstallationController`):
while there is no user it stores the agency's name, website and email and
creates the admin. Nothing links to it: `/` and every other page send a
visitor to `/login`, which had no account, so nobody deploying Free PMO could
get in (and whoever found `/app-install` first would own the instance).

## What the recipe does

- `overrides/docker-compose.override.yml` adds the engine's
  `app-credentials.env` to the app service's `env_file`.
- `free-pmo-admin` (install and upgrade, after the laravel recipe's `migrate`)
  runs `files/panelalpha/free-pmo-admin.php`, which boots the app and, only
  while the database has no user at all, writes what the installer writes:
  `agency_name` (the app's name, editable under Agency) and `agency_email`
  in `site_options`, and the admin `Administrator` with the generated email
  and password and the admin and worker roles. `/app-install` then sends to
  `/login`, as it does once a user exists.

A database that has users is left alone, so a password changed in the app
stays changed. Nothing else changes.

Checked locally (2026-11-19, a8a0dd83, PHP 8.1, MariaDB 11, the laravel
recipe's build, install and start commands): the generated login signed in
(`POST /login` 302 to `/home`, `/home` 200, `/agency/edit`, admin only, 200),
a wrong password was refused, `/app-install` sent to `/login`; after the
password was changed under Change Password a restart that ran the install and
upgrade commands again kept the new one and refused the generated one.
