# eLabFTW (github.com/elabftw/elabftw)

Electronic lab notebook: upstream's `elabftw/elabimg` image (nginx + php-fpm
under s6) with MySQL 8.4, served on :8080.

## Deploying

No variables are required. On the first boot the image installs the schema
(`AUTO_DB_INIT`); open the site and register at `/register.php`: the first
account registered becomes the Sysadmin, as upstream ships it.

## What the recipe does

- `overrides/docker-compose.yml` follows upstream's
  `containers/elabimg/docker-compose.yml-EXAMPLE` on `elabftw/elabimg:6.0.5`
  and `mysql:8.4`. The repository is the development tree; the plain PHP
  deploy died on `mkdir '/var/cache/elabftw'`. MySQL, not MariaDB: the schema
  uses `utf8mb4_0900_ai_ci`.
- The image runs as uid 1000 (php-fpm refuses root) with a `/run` tmpfs, as
  upstream's example does; a one-shot `perms` service chowns the cache,
  uploads and exports volumes to that uid before the app starts.
- `hooks/prepare.sh` generates the MySQL passwords and the defuse
  `SECRET_KEY` once into `~/.panelalpha/elabftw/`. Do not delete them: a new
  SECRET_KEY cannot read values already encrypted in the database.
- `AUTO_DB_UPDATE` migrates the schema on later boots. HTTPS ends at the
  engine's proxy (`DISABLE_HTTPS=true`); `SITE_URL` is the public URL.
- PHP children, PHP memory and nginx workers are capped for a small account.
- A no-op `ready` service holds `compose up` until nginx answers.
