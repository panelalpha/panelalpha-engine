# Zammad (github.com/zammad/zammad)

Web-based helpdesk / ticketing system (Rails, PostgreSQL, Redis, Memcached).

## Why a recipe

The repository's Dockerfile is upstream's CI image build; a plain deploy
fails with `the required build argument $COMMIT_SHA is missing`. Upstream
deploys Docker installs from `zammad/zammad-docker-compose` on its published
image instead.

## What the recipe does

- `overrides/docker-compose.yml`: upstream's `docker-compose.yml` on
  `ghcr.io/zammad/zammad:7.2.0-0016` (current release 7.2.0):
  `zammad-init` (migrations, exits 0), `zammad-railsserver` (health-checked),
  `zammad-scheduler`, `zammad-websocket` and `zammad-nginx` (port 8080,
  `NGINX_SERVER_SCHEME=https` behind the engine's TLS), with
  `postgres:17.11-alpine`, `redis:8.10.2-alpine`, `memcached:1.6.45-alpine`.
  - Elasticsearch is left out (`ELASTICSEARCH_ENABLED=false`, as upstream's
    `scenarios/disable-elasticsearch-service.yml`); search uses the database.
  - The backup service is left out.
  - PostgreSQL creates the `zammad` role and `zammad_production` database
    through the image's `POSTGRES_USER`/`POSTGRES_DB` instead of upstream's
    `configs:` initdb hook.
- `hooks/prepare.sh` generates the database password once into
  `~/.panelalpha/zammad/` (`db.env` for PostgreSQL, `zammad.env` for Zammad).
- Volumes: `postgresql-data`, `redis-data`, `zammad-storage`.

Measured on mariusz: ~1.5 GB in total once running (init, railsserver,
scheduler and websocket are ~250-550 MB each); memory_limit 2500 is enough.

## First visit

Zammad's own getting-started wizard (create the first admin, organisation,
system URL, email). The live-update websocket does not pass the
`*.panelalpha.online` test front (engine#170); test that on a real domain.

Bumping: change the image tag (`7.2.0-00NN` from
`ghcr.io/zammad/zammad`); `zammad-init` migrates on start.
