# EGroupware (github.com/egroupware/egroupware)

Groupware suite: calendar, address book, mail client, InfoLog, projects,
timesheet, file manager.

## What the recipe does

- `overrides/docker-compose.yml` is upstream's `doc/docker/docker-compose.yml`
  on `egroupware/egroupware:26.9.20260928-2` (PHP-FPM), `nginx:stable-alpine`
  on port 8080 and `mariadb:11.8`. Rocket.Chat, Collabora, the swoole push
  server and watchtower are left out.
- `files/panelalpha/egroupware-nginx.conf` is upstream's `doc/docker/nginx.conf`
  without the locations of those left-out services; `/` redirects to
  `/egroupware/index.php` honouring `X-Forwarded-Proto`.
- `hooks/prepare.sh` generates the MariaDB root password once into
  `~/.panelalpha/egroupware/db.env`. The image's entrypoint uses it on the
  first boot to create the `egroupware` database and user (random password
  kept in `header.inc.php` on the `data` volume).
- Volumes: `db`, `data` (`/var/lib/egroupware`: files, backups,
  header.inc.php), `sessions`, `push-config`, `sources` (the image's code,
  re-synced by the entrypoint on every start, shared with nginx).

## First login

The entrypoint prints `Setup username/password` and `EGroupware username:
sysop` with its password in the `egroupware` container's log on the first
boot; the same text is in `/var/lib/egroupware/egroupware-docker-install.log`.

Bumping: change the `egroupware` image tag.
