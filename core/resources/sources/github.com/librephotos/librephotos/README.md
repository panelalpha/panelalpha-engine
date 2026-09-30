# LibrePhotos (github.com/LibrePhotos/librephotos)

Self-hosted photo manager: timeline, albums, face recognition, captions,
scene tagging and a map.

## Deploying

Nothing is required. The first visit shows LibrePhotos' own first-time setup,
which creates the admin account (as upstream ships it).

Photos go on the `pictures` volume (`/data`); set a user's scan directory
below `/data` in the admin area and run a scan. Upload through the web UI is
off, as in upstream's default (`ALLOW_UPLOAD=false`).

## What the recipe does

- `overrides/docker-compose.yml` is upstream's `deploy/compose/docker-compose.yml`
  (nginx proxy, frontend, Django backend, pgautoupgrade Postgres) on the 1.2.1
  release images; the repo is a monorepo with nothing deployable at its root.
- `hooks/prepare.sh` generates the database password once into
  `~/.panelalpha/librephotos/db.env` (upstream ships `AaAa1234`).
- Photos, thumbnails, logs (with the generated `secret.key`), the model cache
  and the database live on named volumes.
- `WORKER_CONCURRENCY=1`: upstream defaults to one scan worker per host core,
  each loading the ML models. Backend limit 1.8 GB.
- The backend healthcheck sends `Host: backend` (Django's ALLOWED_HOSTS); a
  no-op `ready` service holds the deploy until migrations ran and the API answers.
