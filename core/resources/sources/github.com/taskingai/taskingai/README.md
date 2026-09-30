# TaskingAI (github.com/TaskingAI/TaskingAI)

Platform for building AI agents, assistants and RAG apps: a React console,
two FastAPI backends (`backend-web` for the console, `backend-api` for the
public API), inference and plugin services, Postgres with pgvector, Redis, and
nginx routing them on :8080.

Plain deploy: the engine picks up `docker/docker-compose.yml` and runs it from
the repository root. Every value comes from `${VAR}` in `docker/.env`, which
compose never reads there (engine#443), so the backends restart-loop with
`Exception: Env OBJECT_STORAGE_TYPE is not set`. Upstream's health checks
call `curl`, which none of the images ship, so they never pass either.

## What the recipe does

- `overrides/docker-compose.yml`: upstream's services and image pins
  (console and server v0.3.0, inference v0.2.14, plugin v0.2.10,
  `ankane/pgvector:v0.5.1`, `redis:7-alpine`, `nginx:1.24` with upstream's
  `docker/nginx/conf`). `OBJECT_STORAGE_TYPE=local` on the `object-storage`
  volume, `HOST_URL` / `ICON_URL_PREFIX` = the site's address, Postgres and
  Redis on named volumes instead of `./data`. Credentials reach the services
  through `env_file: taskingai.env`, never through compose interpolation.
- `hooks/prepare.sh`: `AES_ENCRYPTION_KEY`, `JWT_SECRET_KEY`, the Postgres and
  Redis passwords (and the URLs built from them) generated once into
  `~/.panelalpha/taskingai/taskingai.env`, copied into the project each deploy.
- Health checks call python's `urllib` instead of `curl`, on `/api/v1/health_check`
  for `backend-web` (its routes live under `/api/v1`). A no-op `ready` service
  waits for both backends, so the deploy ends once they have migrated and answer.

## First run

Upstream's `.env.example` default: sign in to the console as `admin` /
`TaskingAI321`. Model providers need the customer's own API keys, added in the
console.
