# Prefect (github.com/PrefectHQ/prefect)

Workflow orchestration server and web dashboard (Python/FastAPI, SQLite).

## Deploying

Nothing to set. Open the site for the dashboard. Point workers and flows at
`https://<domain>/api` (`PREFECT_API_URL`). The self-hosted server has no login
of its own, as upstream ships it.

## What the recipe does

- `overrides/docker-compose.yml` runs `prefecthq/prefect:3.8.7-python3.12` as
  `prefect server start --host 0.0.0.0 --port 4200`, with `PREFECT_HOME`
  (`prefect.db`) on the named volume `prefect-data`, kept across redeploys.
- `PREFECT_UI_API_URL` is the public URL + `/api`, so the dashboard calls the
  API through the account's domain.
- `ready` makes `compose up -d` wait for `/api/health`.
- The repository's Dockerfile is not used: it is a CLI base image whose
  entrypoint falls through to an interactive shell, so it exits at once.
