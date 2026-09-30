# Odoo

<https://github.com/odoo/odoo> — open-source business apps (Python, PostgreSQL).

The plain deploy detects the source tree as `python` and fails in
`pip install -r requirements.txt`: psycopg2 builds from source and stops at
`pg_config executable not found`.

## What the recipe does

- `overrides/docker-compose.yml`: the official `odoo:20.0` image (the repo's
  default branch) on port 8069 with `postgres:17`. The database, filestore
  (`/var/lib/odoo`), extra addons and `/etc/odoo` (Odoo writes the master
  password chosen on the first database creation into `odoo.conf`) are named
  volumes, so a rebuild keeps them.
  - `--http-interface=0.0.0.0`: Odoo 20 binds `127.0.0.1` by default
    (`odoo/tools/config.py`) and the image's `odoo.conf` leaves it.
  - `--proxy-mode`: honour the engine proxy's forwarded scheme and host.
  - A no-op `ready` service gates `compose up -d` on `/web/health`.
- `hooks/prepare.sh`: generates the PostgreSQL password once into
  `~/.panelalpha/odoo/db.env` (`POSTGRES_PASSWORD` for postgres, `PASSWORD` for
  the Odoo entrypoint).

## First run

Open the site: Odoo shows its database manager (`/web/database/selector`),
where you create the first database and its admin account, as upstream ships
it.
