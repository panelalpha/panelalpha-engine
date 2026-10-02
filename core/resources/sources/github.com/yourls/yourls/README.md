# YOURLS (github.com/yourls/yourls)

Self-hosted PHP URL shortener with click statistics, an API and plugins.

## Deploying

Deploy as is. YOURLS has no user table; its admin login (`YOURLS_USER` /
`YOURLS_PASS`) lives in its config, and the engine generates it: read it with
`GET /projects/{name}/app-credentials` (MCP `app_credentials_get`). Project env
vars with those names, set before the first deploy, are used instead.

Then open `/admin/` and click **Install YOURLS** (upstream's own first-run
step, it creates the tables), and sign in.

## What the recipe does

- `overrides/docker-compose.yml` runs the official `yourls:1.10.6-apache`
  image (port 8080) and `mariadb:11.8`. Links and stats live in MariaDB on the
  `db` volume; installed plugins on the `plugins` volume.
- `hooks/prepare.sh` generates the database passwords and `YOURLS_COOKIEKEY`
  once into `~/.panelalpha/yourls/` (0600), so a rebuild keeps both the data
  and the login sessions.
- The login reaches the app through `env_file: ../.panelalpha/app-credentials.env`;
  `ready` makes `compose up -d` wait until `/admin/install.php` answers.

`/` answers 403 on purpose: YOURLS ships no front page (short links, `/admin/`
and `/yourls-api.php` are the site). Add your own `index.php` if you want one.
