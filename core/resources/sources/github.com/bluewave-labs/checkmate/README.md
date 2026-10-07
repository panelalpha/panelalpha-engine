# Checkmate (github.com/bluewave-labs/checkmate)

Uptime, page-speed and infrastructure monitoring. One Node server on :52345
(API and web client) with MongoDB.

## Deploying

No variables are required. The first visit opens Checkmate's own registration
page; the first account created becomes the super admin (upstream's first-run
behaviour). `ENCRYPTION_KEY` (optional, for Docker TLS keys) and the other
upstream env vars from the README can be set on the project.

## What the recipe does

- `overrides/docker-compose.yml` is upstream's `docker/docker-compose.yaml`
  with `ghcr.io/bluewave-labs/checkmate:3.12.0` instead of `:latest`, and
  `CLIENT_HOST` set to the site's address (CORS and links in notifications).
- `JWT_SECRET` is `${JWT_SECRET:?}`: the engine generates it per account,
  stable across redeploys.
- `mongo:7.0` instead of upstream's `mongo:8.0`: MongoDB 8 fails on kernel
  6.19+. Data on the `mongo-data` named volume.
- The repository's `docker/Dockerfile` is not built: its frontend build is
  OOM-killed in a 2 GB account.
- Behind the engine every visitor reaches Checkmate from the proxy's address
  and Checkmate has no trust-proxy setting, so its login rate limit
  (15 requests/minute per IP) is shared by all visitors.
