# Chamilo (github.com/chamilo/chamilo-lms)

E-learning / learning management system (PHP 8.3, Symfony + Vue, MariaDB).

## Why a recipe

The git tree ships no compiled frontend. A plain deploy resolves Composer and
then runs the Encore/webpack build in the host build container, where the
kernel OOM-kills it (`Killed process … (webpack) anon-rss:5080820kB` against a
5202 MB container). Upstream's release packages already contain `vendor/` and
`public/build/`, so the recipe runs the release instead of building git.

## What the recipe does

- `overrides/docker-compose.yml`:
  - `fetch` (one-shot) downloads `chamilo-3.0.1.zip` from the GitHub release,
    checks its sha256 and unpacks it into the `app` volume once per version,
    owned by uid 1000 (`application`, the php-fpm user).
  - `app`: `webdevops/php-apache:8.3` (PHP 8.3 with intl, ldap, soap, gd, zip,
    bcmath, exif, apcu), document root `/app/public`, port 8080.
    `TRUSTED_PROXIES=private_ranges` overrides the installer's `.env` so
    Symfony sees https behind the engine's proxy.
  - `db`: `mariadb:11.8`, credentials from `~/.panelalpha/chamilo/db.env`.
  - `ready`: no-op gate on the app's health check.
- `hooks/prepare.sh` generates the MariaDB credentials once into
  `~/.panelalpha/chamilo/db.env`.
- Volumes: `app` (the installation, including `.env`, `config/jwt` and
  `var/upload`) and `db`. Both survive redeploys.

## First visit

Chamilo's own web installer. Database step: host `db`, port 3306, user
`chamilo`, database `chamilo`, password = `MARIADB_PASSWORD` from
`~/.panelalpha/chamilo/db.env`. The install step takes about a minute; on a
`*.panelalpha.online` address the front cuts the request after ~10s,
but the install carries on in the app: reload after a minute.

Bumping: change `CHAMILO_VERSION` and `CHAMILO_SHA256` together. The new
release is unpacked over the old tree, which keeps `.env`,
`config/jwt` and `var/`.
