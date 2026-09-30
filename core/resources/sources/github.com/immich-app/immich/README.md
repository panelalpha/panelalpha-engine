# Immich (github.com/immich-app/immich)

Self-hosted photo and video library: immich-server (API, jobs and web UI on
:2283), immich-machine-learning, PostgreSQL with VectorChord, Valkey.

## Deploying

No variables are required. Give the account 4096 MB: the server idles at
~0.9-1.2 GB and the machine-learning service loads models on demand. The
first visit opens Immich's admin sign-up (upstream behaviour).

## What the recipe does

- The repository root is a pnpm monorepo with no compose file; Railpack builds
  it but finds no start command and the container restart-loops.
  `overrides/docker-compose.yml` replaces that with the official images of
  v3.2.4 and the release compose's pinned Postgres and Valkey digests.
- `hooks/prepare.sh` generates the database password once into
  `~/.panelalpha/immich/secrets.env`.
- Data: `library` (/data, uploads and thumbnails), `database`, `model-cache`
  named volumes. A `ready` gate waits for `immich-healthcheck`.
- On a `*.panelalpha.online` test domain, uploads fail at the test edge
  (engine#170); they work through the host's own webserver.
