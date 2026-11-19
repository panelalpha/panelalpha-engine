# FreeITSM (github.com/edmozley/freeitsm)

IT service desk: PHP on Apache with MySQL 8, the repository's own
`docker-compose.yml` (services `app` and `db`).

## Deploying

Deploy as is, open `/auth/login.php` and sign in with the login the engine
generates (`FREEITSM_ADMIN_USER`, default `admin`, and
`FREEITSM_ADMIN_PASSWORD`): read it with `GET /projects/{name}/app-credentials`
(MCP `app_credentials_get`). That analyst is FreeITSM's administrator; change
the password under the account menu.

## Why a recipe

The schema the `db` service imports on its first start (`database/freeitsm.sql`)
seeds the analyst `admin` with the password `freeitsm`, published in the
README, and a must-change flag: whoever signs in first with it chooses the new
password, so anyone with the address could take a new instance, and nobody
deploying it was told the login.

## What the recipe does

- `overrides/docker-compose.override.yml` runs
  `files/panelalpha/freeitsm-start.sh` under tini as the app's entrypoint and
  mounts the engine's `app-credentials.env` read-only at
  `/pa/app-credentials.env`.
- `freeitsm-start.sh`, before the image's own `entrypoint.sh`, waits for the
  `analysts` table (the db service is healthy while it is still importing the
  schema) and, only while `admin` still has the password `freeitsm`, gives it
  the generated username and a `password_hash()` of the generated password
  (as FreeITSM's own password change stores it) and clears its must-change
  flag. A password changed in FreeITSM no longer verifies as `freeitsm`, so it
  is never touched. The login is read in a subshell; Apache's environment
  never has it.

Nothing else changes. The image's `COPY . /var/www/html/` puts this recipe's
`panelalpha/freeitsm-start.sh` in the web root as it does every other file of
the checkout; it is a shell script Apache serves as text, and it holds no
secret.

Checked locally (2026-11-19, 4973a1ed, the repository's compose file with this
override): the generated login signs in (`POST /auth/login.php` 302 to
`index.php`, `/index.php` 200), `admin` / `freeitsm` and a wrong password are
refused; after the password was changed in the app
(`api/myaccount/change_password.php`) a restart and a recreate kept the new
one and refused the generated one.
