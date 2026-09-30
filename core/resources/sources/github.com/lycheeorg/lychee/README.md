# Lychee (github.com/lycheeorg/lychee)

Self-hosted photo management (Laravel on FrankenPHP).

## What the recipe does

- The repository's `docker-compose.yaml` leaves `APP_KEY` empty, pulls the
  optional AI face-recognition image, and relies on `cap_add`, which the engine
  strips while keeping `cap_drop: [ALL]` (engine#349). As a result, MariaDB
  cannot switch to its own user.
- `overrides/docker-compose.yml` runs upstream's shape on
  `ghcr.io/lycheeorg/lychee:v7.10.0`:
  - `app` on port 8000, which runs migrations on start.
  - `worker` (`LYCHEE_MODE=worker`, `QUEUE_CONNECTION=database`).
  - `db` on `mariadb:11`.
  - `ready`, which makes `compose up -d` wait for `/up`.
- `APP_URL` is the site's address. `APP_FORCE_HTTPS=true`, because the engine
  serves the site over https. `AI_VISION_ENABLED=false`, because the
  face-recognition service is not deployed.
- `hooks/prepare.sh` writes `APP_KEY` and the database password once, to
  `~/.panelalpha/lychee/{app,db}.env` (0600).
- Named volumes: `uploads`, `logs`, `tmp`, `mysql`, all kept across redeploys.

The admin account is created on Lychee's own first-run page.
