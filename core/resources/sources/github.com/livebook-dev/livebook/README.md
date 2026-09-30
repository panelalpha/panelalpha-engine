# Livebook (github.com/livebook-dev/livebook)

Collaborative Elixir notebooks (Phoenix LiveView) on port 8080.

## Deploying

Upstream's default auth applies: Livebook prints a login URL with a fresh
token (`/?token=...`) in the app's log at every start. To log in with a
password instead, set the project env var `LIVEBOOK_PASSWORD` (at least 12
characters) and redeploy.

## What the recipe does

- `overrides/docker-compose.yml` runs `ghcr.io/livebook-dev/livebook:0.19.10`
  with notebooks (`/data`) and Livebook's settings
  (`/home/livebook/.local/share/livebook`) on named volumes, kept across
  redeploys. `ready` makes `compose up -d` wait for `/public/health`.
- An empty `LIVEBOOK_PASSWORD` makes Livebook abort, so the entrypoint unsets
  it when the project does not set one.
- Without the recipe the engine builds the repo's Dockerfile, which needs the
  `BASE_IMAGE`/`VARIANT` build args upstream's CI supplies, and fails.
