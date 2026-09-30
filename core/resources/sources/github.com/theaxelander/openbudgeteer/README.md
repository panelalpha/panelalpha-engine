# OpenBudgeteer (github.com/theaxelander/openbudgeteer)

Bucket-budgeting app: ASP.NET Core Blazor Server on :8080, needing a SQL
database (MariaDB/MySQL or PostgreSQL) and Redis.

## Why a recipe

The repository ships only a Dockerfile. Built as-is the app has no database
configured and exits with `Database provider not defined.` (exit 139) in a
restart loop. Release 1.11 also refuses to start without Redis.

## What the recipe does

- `overrides/docker-compose.yml`: `axelander/openbudgeteer:1.11` (current
  release), `postgres:17-alpine`, `redis:7-alpine`, data on the `db-data` and
  `redis-data` volumes.
- `hooks/prepare.sh` generates the database password once into
  `~/.panelalpha/openbudgeteer/db.env`.
- The app creates its schema on first boot. A healthcheck (bash `/dev/tcp`,
  the image has no curl) plus a no-op `ready` service gate the deploy.

Authentication is off, as upstream ships it (`APPSETTINGS_AUTH_*`).
