# Superset (github.com/amancevice/docker-superset)

Unofficial Docker packaging of Apache Superset; gunicorn on :8088.

## What the recipe does

- `overrides/docker-compose.yml` runs the published `amancevice/superset:6.1.0`
  image (the upstream README advises against building the Dockerfile).
  `init` runs `superset db upgrade && superset init` once per deploy, then
  `app` starts gunicorn with 3 gevent workers (the image's 10 do not fit the
  account); `ready` makes `compose up -d` wait for `/health`.
- `hooks/prepare.sh` writes `SUPERSET_SECRET_KEY` once to
  `~/.panelalpha/superset/secrets.env`; Superset refuses to start on its
  default key, and the key encrypts saved database connections.
- The SQLite metadata DB (`/var/lib/superset/superset.db`) is a named volume.

First run is upstream's: create the first admin inside the app container with
`superset fab create-admin`, then log in at `/login/`.
