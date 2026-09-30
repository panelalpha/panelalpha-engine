# Homechart (github.com/candiddev/homechart)

Household organizer (budgets, calendar, recipes, tasks). One Go server on
:3000; everything it stores lives in PostgreSQL. The repository only carries
docs; the server ships as the `ghcr.io/candiddev/homechart` image.

## Deploying

No variables are required. Open the site and sign up: the first account is
created from Homechart's own sign-up page. Households need a Homechart subscription even when
self-hosted; the rest of the app does not.

## What the recipe does

- `overrides/docker-compose.yml` is upstream's documented compose: the
  `v2026.09.24` image plus `postgres:16` with its data on the named volume
  `homechart-db` (kept across redeploys); `homechart_app_baseURL` is the site's
  address.
- `hooks/prepare.sh` generates the database password once into
  `~/.panelalpha/homechart/` (`db.env` for PostgreSQL, `app.env` with
  `homechart_database_uri` for the app).
