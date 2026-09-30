# LiteLLM (github.com/berriai/litellm)

AI gateway: an OpenAI-compatible proxy to 100+ LLM providers, with an admin UI
(`/ui`), virtual keys and spend tracking (Python, Prisma, PostgreSQL).

## What the recipe does

- `overrides/docker-compose.yml` runs `ghcr.io/berriai/litellm:v1.103.0` on
  port 4000 with `postgres:16-alpine`, the topology of upstream's
  `docker/docker-compose.quickstart.yml`. The repository's own compose builds
  the Dockerfile; its UI `next build` was SIGKILLed ("cannot allocate memory")
  in a 2500 MB account, and an earlier run stalled 15 minutes in `uv sync`.
  The optional Prometheus service is left out.
- `hooks/prepare.sh` generates once into `~/.panelalpha/litellm/secrets.env`
  (0600): the database password and `DATABASE_URL`, `LITELLM_MASTER_KEY` and
  `LITELLM_SALT_KEY`, as the quickstart's own setup step does. The salt key
  encrypts provider credentials stored in the database and must never change.
- `STORE_MODEL_IN_DB=True` (as upstream's compose) so models can be added
  from the UI; `PROXY_BASE_URL` is the site address.
- Data: `postgres_data` is a named volume kept across redeploys.
- `ready` waits for `/health/liveliness`; the first boot applies ~180 Prisma
  migrations.

`/` is the Swagger page, `/ui` the dashboard. The UI login is user `admin`
with the master key as password; the key is in
`~/.panelalpha/litellm/secrets.env`.
