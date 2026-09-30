# YOURLS (github.com/yourls/yourls)

Self-hosted PHP URL shortener with click statistics, an API and plugins.

## Deploying

Set two project environment variables, the admin login (YOURLS has no user
table; the login lives in its config):

- `YOURLS_USER`: the admin username.
- `YOURLS_PASS`: its password (write any `$` as `$$`).

Without them the deploy fails with:

```
yourls: missing project environment variable(s): YOURLS_USER YOURLS_PASS. ...
```

Then open `/admin/` and click **Install YOURLS** (upstream's own first-run
step, it creates the tables), and sign in.

## What the recipe does

- `overrides/docker-compose.yml` runs the official `yourls:1.10.6-apache`
  image (port 8080) and `mariadb:11.8`. Links and stats live in MariaDB on the
  `db` volume; installed plugins on the `plugins` volume.
- `hooks/prepare.sh` generates the database passwords and `YOURLS_COOKIEKEY`
  once into `~/.panelalpha/yourls/` (0600), so a rebuild keeps both the data
  and the login sessions.
- `env-check` (one-shot) fails the deploy naming a missing variable; `ready`
  makes `compose up -d` wait until `/admin/install.php` answers.

`/` answers 403 on purpose: YOURLS ships no front page (short links, `/admin/`
and `/yourls-api.php` are the site). Add your own `index.php` if you want one.
