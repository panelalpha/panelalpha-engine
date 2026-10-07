# Endurain (codeberg.org/endurain-project/endurain)

Self-hosted fitness activity tracker. FastAPI (uvicorn) backend serving its
built Vue frontend on :8080, PostgreSQL for data, Redis for rate limiting and
auth-security storage (`RATE_LIMIT_STORAGE_URI`, `AUTH_SECURITY_STORAGE_URI`).

Detection: `dockerfile` — the repo's production stack exists only as
`docker-compose.yml.example` templates, which the compose probe declines
(pinned prebuilt app image, host bind mounts). The engine's own
example-compose sidecar reader would adopt the template's postgres and redis,
but pins their credentials itself, so the app's generated `DB_PASSWORD` would
never match: the database would come up as `app`/`app`/`app` and the backend
would crash-loop on its first connection. The hook therefore stashes the
templates under `*.panelalpha-off` (a name neither the compose candidates nor
the sidecar reader consult) and the sidecars are declared explicitly in
`overrides/docker-compose.override.yml`, reading `${DB_PASSWORD}` from the
same `.env` the app's `env_file` uses.

Secrets (generated in `hooks/prepare.sh` into `.env`):

- `DB_PASSWORD` = `POSTGRES_PASSWORD` (hex, one value both sides read)
- `SECRET_KEY` — JWT signing key
- `FERNET_KEY` — must be 32 bytes urlsafe-base64; `openssl rand -base64 32`
  transliterated `+/ → -_` produces one. The app validates the format at
  startup and only logs on mismatch — a bad key surfaces later as an
  encryption failure, so the format matters here.

`ENDURAIN_HOST` is removed from `.env` rather than pointed at the hosting
domain: unset, `docker/start.sh` skips writing `env.js` and the frontend falls
back to same-origin `/api/v1` — the correct shape for a single origin behind
the hosting proxy. Set (via the account's `env_vars`) when a split origin is
wanted; `start.sh` then rewrites `env.js` and hardens the index CSP on boot.

Data lives in bind mounts under `${LOCAL_PATH:-../.panelalpha/endurain}`; the
hook sets `LOCAL_PATH=$HOME/.panelalpha/endurain` and creates `backend/data`,
`backend/logs`, `postgres` and `redis` there, owned by the account user, which
is the uid the container runs as (1000). Migrations run at startup (Alembic in
the FastAPI lifespan); the first boot waits on both sidecars' healthchecks.