# MediKeep (github.com/afairgiant/MediKeep)

Personal medical records app: FastAPI serving its React build on :8000,
PostgreSQL only.

## What the recipe does

- `overrides/docker-compose.yml` runs `ghcr.io/afairgiant/medikeep:v0.71.0`
  with a `postgres:17-alpine` sidecar, as upstream's `docker/docker-compose.yml`
  does. The image runs the Alembic migrations on boot; `ready` makes
  `compose up -d` wait until `/health` answers.
- `hooks/prepare.sh` writes the DB password and `SECRET_KEY` once to
  `~/.panelalpha/medikeep/secrets.env` (reused on every redeploy).
- Database, uploads, logs and backups are named volumes.

The repository's `docker/Dockerfile` is not built: its React build was killed
for memory in a 2000 MB account. First-run behaviour is upstream's: the app
creates its default `admin` account on first boot (see the MediKeep docs).
