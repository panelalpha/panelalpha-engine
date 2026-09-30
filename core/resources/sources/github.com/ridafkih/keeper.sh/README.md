# Keeper.sh (github.com/ridafkih/keeper.sh)

Calendar syncing tool. Runs the upstream-recommended all-in-one image
`ghcr.io/ridafkih/keeper-standalone` (web, API, cron, worker, MCP,
PostgreSQL 17, Redis and Caddy under s6) on port 80.

## What the recipe does

- `overrides/docker-compose.yml` replaces the repo's `compose.yaml`, which is
  the developer environment (Caddy with `tls internal` proxying to a Vite
  server on the host).
- `hooks/prepare.sh` generates `BETTER_AUTH_SECRET` and `ENCRYPTION_KEY` once
  into `~/.panelalpha/keeper/keeper.env` (0600 in a 0700 dir); the database
  would be unreadable with new keys, so they are never regenerated.
- `BETTER_AUTH_URL` and `TRUSTED_ORIGINS` are the site's address (better-auth
  CSRF check).
- `/var/lib/postgresql/data` is the named volume `keeper-data`.
- `ready` makes `compose up -d` wait for `/api/health` (migrations done).

Optional project env vars: `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`,
`MICROSOFT_CLIENT_ID`, `MICROSOFT_CLIENT_SECRET`. Without them users sign up
with a username and password (self-hosted mode), as upstream ships it.
