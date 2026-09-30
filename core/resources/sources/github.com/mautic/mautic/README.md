# Mautic (github.com/mautic/mautic)

Marketing automation; PHP/Symfony on Apache with MySQL.

## What the recipe does

- `overrides/docker-compose.yml` runs upstream's docker-mautic "basic" example
  on `mautic/mautic:7.2.1-apache`: `app` (role `mautic_web`, port 80),
  `cron` and `worker` (they wait until Mautic is installed), `db` on
  `mysql:8.4`, and a no-op `ready` gate on the web healthcheck.
- `hooks/prepare.sh` writes the database passwords once to
  `~/.panelalpha/mautic/{app,db}.env` (0600).
- `files/mautic-parameters_local.php` is mounted as `config/parameters_local.php`
  and sets `trusted_proxies`, so Mautic honours the
  proxy's `X-Forwarded-Proto` and builds https URLs.
- Named volumes `config`, `logs`, `files`, `images`, `mysql` survive redeploys.

## First run

Open the site: Mautic's own installer checks the environment, takes the
database settings (prefilled from the environment) and creates the admin.
Sending mail needs an SMTP/DSN configured in Mautic's settings.
