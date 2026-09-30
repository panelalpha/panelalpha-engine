# InvenTree (github.com/inventree/InvenTree)

Inventory and parts management, run as upstream's container stack
(`contrib/container/docker-compose.yml`) from the release image.

## What the recipe does

- `overrides/docker-compose.yml`: `postgres:17`, `redis:7-alpine`, a one-shot
  `update` (`invoke update --skip-backup`: migrations and static files), the
  gunicorn `server`, the background `worker`, and `app` = Caddy on :80 with the
  repository's own `contrib/container/Caddyfile` (serves `/static`, `/media`
  behind InvenTree's auth, proxies the rest). All on `inventree/inventree:1.5.6`.
- Site URL and allowed hosts come from `${PA_PUBLIC_URL}` / `${PA_PUBLIC_HOST}`.
- Workers scaled to fit 2.5 GB: 2 gunicorn, 1 background worker. The worker
  alone sits at ~1 GB (qcluster forks a sentinel, pusher, monitor and worker,
  each importing Django); with two it was OOM-killed at 1 GB.
- `hooks/prepare.sh` writes the database password once to
  `~/.panelalpha/inventree/db.env`. The data directory (config, secret key,
  media, plugins) and the database are named volumes.

A deploy takes ~4-9 minutes, most of it `invoke update`.

## First run

Upstream's own: create the admin with
`docker compose -p project exec server invoke superuser`.
