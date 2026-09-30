# Browserless (github.com/browserless/browserless)

Headless Chromium as a service: REST endpoints (`/content`, `/screenshot`,
`/pdf`, `/function`, ...), a WebSocket endpoint for Puppeteer/Playwright, and
OpenAPI docs at `/docs`.

## Deploying

Nothing is required. Set `TOKEN` as a project env var to require
`?token=<TOKEN>` on every call (unset, the API is open). `CONCURRENT`,
`QUEUED`, `TIMEOUT` and upstream's other env vars work the same way.

WebSocket clients connect to `wss://<domain>/?token=...` directly; a proxy in
front that strips `Upgrade` breaks them, the REST endpoints still work.

## What the recipe does

- `overrides/docker-compose.yml` runs the release image
  `ghcr.io/browserless/chromium:v2.56.7` on port 3000 instead of the generic
  Node build (which fails on a missing `unzip` and would ship no browser).
- 1 GB `/dev/shm`, 2 GB memory cap; a `ready` gate on `/active` (passes the
  token when one is set). No data is kept.
