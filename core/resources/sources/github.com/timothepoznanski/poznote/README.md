# Poznote (github.com/timothepoznanski/poznote)

Self-hosted note-taking app: PHP-FPM + nginx in one image, SQLite and
attachments under `/var/www/html/data`, plus an optional MCP server for AI
clients.

## Why a recipe

The repository's `docker-compose.yml` publishes the web server as
`"${HTTP_WEB_PORT}:80"` (the value is only in `.env.template`) and the MCP
sidecar as `"127.0.0.1:${POZNOTE_MCP_PORT:-8045}:8045"`. The engine cannot
resolve the first and drops the loopback host from the second, so it routed the
domain to the MCP sidecar (HTTP 404). Its `./data` bind mount would also be
wiped with `~/project` on every redeploy.

## What the recipe does

`overrides/docker-compose.yml` replaces the compose file with the same two
services and settings, except:

- images pinned to the current release, `6.100.0` (upstream uses `:6`);
- the web server published on `8040:80` (upstream's default port), the only
  published port;
- `mcp-server` not published (upstream keeps it on localhost); it still reaches
  the web server on the compose network;
- `/var/www/html/data` on the named volume `poznote-data` (kept across
  redeploys), mounted read-only into `mcp-server` as upstream does.

The first visit shows Poznote's own first-run/login page. Optional settings
from `.env.template` (OIDC, `POZNOTE_SETTINGS_PASSWORD`, ...) go in the
project's environment variables.
