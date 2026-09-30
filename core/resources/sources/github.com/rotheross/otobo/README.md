# OTOBO (github.com/rotheross/otobo)

Help desk / ITSM ticketing system.

## What the recipe does

- `overrides/docker-compose.yml` is upstream's `RotherOSS/otobo-docker`
  (branch `rel-11_0`, `otobo-base.yml` + `otobo-override-http.yml`) on
  `rotheross/otobo:rel-11_0_18` (services `web` on port 8080 and `daemon`),
  `rotheross/otobo-elasticsearch:rel-11_0_18` (512 MB heap),
  `mariadb:11.8` with upstream's server flags and `redis:8-bookworm`, plus a
  `ready` gate on the web container's `/robots.txt` health check.
  `cap_drop`/`cap_add` are removed (engine#349 would keep only the drop).
- `hooks/prepare.sh` generates the MariaDB root password once into
  `~/.panelalpha/otobo/db.env`.
- Volumes: `opt_otobo` (`/opt/otobo`: the installation and its config),
  `mariadb_data`, `elasticsearch_data`.

## First visit

OTOBO's web installer (`/otobo/installer.pl`). Database: MariaDB, host `db`,
user `root`, password = `MARIADB_ROOT_PASSWORD` from
`~/.panelalpha/otobo/db.env`. Elasticsearch: host `elastic`, port 9200.

Bumping: change the `otobo` and `otobo-elasticsearch` tags together
(`rel-11_0_<n>`).
