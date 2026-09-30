# Steel Browser (github.com/steel-dev/steel-browser)

Self-hosted browser API and sandbox for AI agents (Puppeteer/Playwright/CDP)
with a session-viewer web UI.

## Deploying

The default 2500 MB is enough (about 600 MB with one live session). No
project environment variables are needed. Browsers opening the site are
redirected to the UI at `/ui`; API clients use `https://<domain>/v1/...`.
There is no authentication (upstream behaviour).

## What the recipe does

- `overrides/docker-compose.yml` replaces upstream's two-container compose
  (API on 3000, UI on 5173) with upstream's all-in-one image
  `ghcr.io/steel-dev/steel-browser`, which serves the UI under `/ui` from the
  API port. A plain deploy routes the site to the API image, which has no UI
  and answers 404 on `/`.
- Upstream publishes only `latest`; the image is pinned by digest to the build
  of main e5902fc5 (2026-09-28).
- `DOMAIN` is the site's host and `USE_SSL=true`, so the session websocket and
  debug URLs the API returns are `wss://`/`https://` on the site.
- Session logs (DuckDB), exports and the browser cache are on named volumes.
- `ready` makes `compose up -d` wait until `/v1/health` answers.

The CDP devtools proxy on 9223 is not published (one port per account).
