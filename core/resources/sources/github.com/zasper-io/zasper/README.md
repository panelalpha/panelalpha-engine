# Zasper (github.com/zasper-io/zasper)

Notebook IDE for Jupyter kernels: one Go binary with the React frontend
embedded, built from `docker/Dockerfile`, `ipykernel` installed for the Python
kernel, the project in `/home/zasper`.

## Deploying

Deploy as is, open `/login` and paste the access token the engine generates
(`ZASPER_ACCESS_TOKEN`; there is no username): read it with
`GET /projects/{name}/app-credentials` (MCP `app_credentials_get`).

## What the recipe does

- `overrides/docker-compose.yml` builds `docker/Dockerfile` from the
  repository root on port 8048, as the dockerfile strategy did, with the
  engine's token and the project's `.env` as `env_file`. The repository's
  `docker/docker-compose.yml` (loopback port, `./workspace` bind) is not used.
- Every route needs Zasper's access token. With no `ZASPER_ACCESS_TOKEN` each
  start makes a random one that only the startup line in the container log
  shows; with it, Zasper uses the engine's, the same on every start. Zasper has
  no form to change it, and its session key is derived from it.
- The project folder is in the container, as before; a redeploy starts it
  empty.
