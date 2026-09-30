# GLPI (github.com/glpi-project/glpi)

IT asset management and helpdesk: PHP on Apache (:80) with MariaDB.

## What the recipe does

- `overrides/docker-compose.yml`: upstream's `docker-compose.example.yml`
  (glpi-project/docker-images) on `glpi/glpi:11.0.9` and `mariadb:11.8`,
  replacing the repository's development compose.
- The database password is generated per account by the engine and stays
  stable across redeploys.
- With the database variables set, the image installs the schema on the first
  boot and updates it on later ones. Upstream's default accounts apply
  (`glpi`/`glpi`, `tech`/`tech`, `normal`/`normal`, `post-only`/`postonly`);
  GLPI warns about them on the dashboard until they are changed.
- Data on the `glpi-data` (config, files, marketplace) and `db-data` volumes.
- A no-op `ready` service waits for Apache to answer, so the deploy ends after
  the install.
