# Parseable (github.com/parseablehq/parseable)

Observability server (logs, metrics, traces) with a web console on port 8000.

## Deploying

Nothing is required. Sign in to the console with upstream's default
`admin` / `admin`, or set `P_USERNAME` and `P_PASSWORD` in the project's
environment variables before deploying. Ingest with the HTTP API
(`POST /api/v1/logstream/<name>`) using the same credentials.

## What the recipe does

- `overrides/docker-compose.yml` runs the release image
  `quay.io/parseablehq/parseable:v3.2.2` with `parseable local-store`. The
  repository's Dockerfile compiles the server from source (OOM-killed at
  2500 MB) and its CMD has no storage subcommand, so the binary exits.
- Data (`P_FS_DIR`) and staging (`P_STAGING_DIR`) on named volumes, so
  streams and ingested events survive a redeploy.
- `P_ORIGIN_URI` is the site address.
