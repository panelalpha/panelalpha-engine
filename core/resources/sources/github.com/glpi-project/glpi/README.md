# GLPI (github.com/glpi-project/glpi)

IT asset management and helpdesk: PHP on Apache (:80) with MariaDB.

## Deploying

Deploy as is, open the site and sign in with the login the engine generates
(`GLPI_ADMIN_USER`, default `admin`, and `GLPI_ADMIN_PASSWORD`, the
Super-Admin): read it with `GET /projects/{name}/app-credentials` (MCP
`app_credentials_get`).

## What the recipe does

- `overrides/docker-compose.yml`: upstream's `docker-compose.example.yml`
  (glpi-project/docker-images) on `glpi/glpi:11.0.9` and `mariadb:11.8`,
  replacing the repository's development compose.
- The database password is generated per account by the engine and stays
  stable across redeploys.
- With the database variables set, the image installs the schema on the first
  boot and updates it on later ones.
- A new database has upstream's default accounts (`glpi`/`glpi` Super-Admin,
  `tech`/`tech`, `normal`/`normal`, `post-only`/`postonly`). The app service's
  command, `files/panelalpha/glpi-login.sh`, runs after the image's install or
  update and then execs the image's `supervisord`. While an account still has
  its default password (`files/panelalpha/glpi-default-logins.php`, GLPI's own
  `User::checkDefaultPasswords` check):
  - `glpi` gets the engine's password (`bin/console user:reset_password`) and
    is renamed to the engine's username;
  - `tech`, `normal` and `post-only` are disabled (`bin/console user:disable`).
  An account whose password was changed in GLPI is never touched. One enabled
  again in GLPI with its default password is disabled again on the next start.
- Data on the `glpi-data` (config, files, marketplace) and `db-data` volumes.
- A no-op `ready` service waits for Apache to answer, so the deploy ends after
  the install.
