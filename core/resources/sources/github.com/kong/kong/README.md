# Kong Gateway (github.com/Kong/kong)

API gateway. The site is Kong's proxy: until a route is added it answers every
path with Kong's own `{"message":"no Route matched with those values"}` (404).

## Deploying

Nothing is required.

## What the recipe does

- `overrides/docker-compose.yml` runs the official `kong:3.9.3` image (current
  OSS release) in database mode on `postgres:16-alpine`, with the proxy
  (container port 8000) published as 8000.
- `migrations` is a one-shot service: `kong migrations bootstrap` on the first
  deploy, `migrations up` / `finish` on later ones. `app` starts only after it
  exits 0, and `ready` holds `compose up` until `kong health` passes.
- `hooks/prepare.sh` generates the database password once into
  `~/.panelalpha/kong/db.env` (0600). The database is the named volume
  `kong-db`, so services, routes and plugins survive redeploys.
- `KONG_NGINX_WORKER_PROCESSES=2`: `auto` would count every host core.
- The Admin API stays on the container's `127.0.0.1:8001` and Kong Manager on
  its unpublished port, as the image ships them. Configure Kong from inside
  the account, e.g. from a container sharing the app's network namespace
  (`docker run --rm --network container:$(docker compose -p project ps -q app) ...`);
  the kong image has no curl.
