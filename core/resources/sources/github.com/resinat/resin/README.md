# Resin (github.com/resinat/resin)

Sticky proxy pool: one Go binary serving the forward, SOCKS5 and reverse
proxies, the API and the React console on port 2260, state in `/var/lib/resin`.

## Deploying

Deploy as is, open `/ui/login` and enter the admin token the engine generates
(`RESIN_ADMIN_TOKEN`): read it with `GET /projects/{name}/app-credentials`
(MCP `app_credentials_get`).

## What the recipe does

- `overrides/docker-compose.yml` builds the repository's Dockerfile on port
  2260 with `/var/cache/resin`, `/var/lib/resin` and `/var/log/resin` on the
  named volumes `resin-cache`, `resin-state` and `resin-log` (kept across
  redeploys). Its environment is the `.env` the engine makes from
  `.env.example` (as the dockerfile strategy used it) and then the engine's
  `app-credentials.env`, so `RESIN_ADMIN_TOKEN` is the generated token instead
  of `.env.example`'s `change-me-admin-token`.
- Resin reads the admin token from its environment only; the console cannot
  change it, so there is nothing a restart could overwrite.
- `RESIN_PROXY_TOKEN` stays `.env.example`'s `change-me-proxy-token` unless the
  project sets its own as an env var.
