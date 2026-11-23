# Resin (github.com/resinat/resin)

Sticky proxy pool: one Go binary serving the forward, SOCKS5 and reverse
proxies, the API and the React console on port 2260, state in `/var/lib/resin`.

## Deploying

Deploy as is, open `/ui/login` and enter the admin token the engine generates
(`RESIN_ADMIN_TOKEN`): read it with `GET /projects/{name}/app-credentials`
(MCP `app_credentials_get`). The same answer has `RESIN_PROXY_TOKEN`, the
token proxy clients authenticate with.

## What the recipe does

- `overrides/docker-compose.yml` builds the repository's Dockerfile on port
  2260 with `/var/cache/resin`, `/var/lib/resin` and `/var/log/resin` on the
  named volumes `resin-cache`, `resin-state` and `resin-log` (kept across
  redeploys). Its environment is the `.env` the engine makes from
  `.env.example` (as the dockerfile strategy used it) and then the engine's
  `app-credentials.env`, so `RESIN_ADMIN_TOKEN` and `RESIN_PROXY_TOKEN` are
  generated tokens instead of `.env.example`'s `change-me-admin-token` and
  `change-me-proxy-token`, which anyone could read and use on every install.
- Resin reads the admin token from its environment only; the console cannot
  change it, so there is nothing a restart could overwrite.
- A project env var `RESIN_PROXY_TOKEN` (or `RESIN_ADMIN_TOKEN`) is used
  instead of the generated one.
