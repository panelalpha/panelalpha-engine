# DataLens (github.com/datalens-tech/datalens)

BI and dashboards: UI (Node) on :8080, united-storage, auth, meta-manager,
control and data APIs (Python), Temporal and Postgres, all release images from
ghcr.io/datalens-tech pinned by the repository's `docker-compose.yaml`.

Plain deploy: the compose strategy runs that file and it works, but every
secret falls back to a value published in the repository (the auth JWT signing
key, the storage master tokens, the Temporal key pair, the connection crypto
key, the Postgres password), so every install shares them. It also idles at
~2.6 GB, over a 2500 MB account.

## What the recipe does

- `hooks/prepare.sh`: generates those secrets once into
  `~/.panelalpha/datalens/secrets.env` the way upstream's `init.sh` does
  (`openssl genpkey` RSA 4096 pairs, `\n`-escaped in double quotes) and copies
  the file to `.env`, which compose reads for the `${VAR:-default}` values.
- `overrides/docker-compose.override.yml`: 2 uwsgi workers for control-api and
  2 gunicorn workers for data-api (upstream: 4 and 5). The stack then idles at
  ~1.7 GB.

Postgres data (including the demo workbook) lives on the `db-postgres` volume.

## First run

Upstream's quick start: `admin` / `admin` (`AUTH_ADMIN_PASSWORD`), sign-up
open (`AUTH_SIGNUP_DISABLED=false`).
