# SourceBans++ (github.com/sbpp/sourcebans-pp)

Ban/admin management panel for Source-engine game servers.

## Deploying

Set in the project's environment variables (upstream's headless install
seeds this Owner admin on the first boot and ignores it afterwards):

- `INITIAL_ADMIN_NAME`, `INITIAL_ADMIN_STEAM` (e.g. `STEAM_0:1:12345`),
  `INITIAL_ADMIN_EMAIL`, `INITIAL_ADMIN_PASSWORD`
- optional: `STEAMAPIKEY`, `SB_EMAIL`

## What the recipe does

- `hooks/prepare.sh` generates the MariaDB passwords and `SB_SECRET_KEY` once
  into `~/.panelalpha/sourcebans/` (`db.env`, `app.env`).
- `overrides/docker-compose.yml` is upstream's `docker-compose.prod.yml`:
  `ghcr.io/sbpp/sourcebans-pp:2.2.1` on 8080 and `mariadb:11.8`, a `check`
  service that fails the deploy when an `INITIAL_ADMIN_*` variable is missing,
  and a `ready` gate on `/health.php`.
- Volumes: `dbdata`, `demos` (uploaded demos), `cache`, `smarty`.

Bumping: change the image tag on `check` and `web`.
