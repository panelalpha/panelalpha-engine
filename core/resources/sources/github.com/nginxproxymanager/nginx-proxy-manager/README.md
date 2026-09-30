# Nginx Proxy Manager (github.com/NginxProxyManager/nginx-proxy-manager)

Web UI for Nginx proxy hosts, redirections, streams and Let's Encrypt
certificates. Node API and React UI in front of OpenResty, SQLite by default.

Plain deploy: the repository has no deployment compose (only CI and
development stacks under `docker/`), detection reports `Unknown` and the
engine serves its placeholder.

## What the recipe does

- `overrides/docker-compose.yml`: upstream's setup compose
  (`docs/src/setup/index.md`) on the release image
  `jc21/nginx-proxy-manager:2.16.0`, SQLite, publishing only the admin UI
  (:81). `/data` (database, JWT keys, generated nginx configs) and
  `/etc/letsencrypt` are named volumes instead of `./data` and
  `./letsencrypt` in the wiped checkout.
- The proxy listeners on 80/443 run inside the account only; the account's
  domain reaches the admin UI, not the proxy (same as the Zoraxy recipe).
- Health: the image's own `/usr/bin/check-health` (`/api/` reports `OK`); a
  no-op `ready` service gates the deploy on it.

## First run

Upstream's: `/api/` reports `"setup": false` and the UI asks the first
visitor to create the admin account.
