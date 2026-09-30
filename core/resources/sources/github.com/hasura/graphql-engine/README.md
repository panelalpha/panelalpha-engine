# Hasura GraphQL Engine (github.com/hasura/graphql-engine)

Instant GraphQL APIs over PostgreSQL, with a web console at `/console`.

## Deploying

No project environment variables are needed. Open the site: `/` redirects to
the console. To expose the bundled database, add it under **Data > Connect
Database** with the environment variable `PG_DATABASE_URL` (upstream's
quickstart flow). No admin secret is set, as upstream's quickstart ships it.

## What the recipe does

- `overrides/docker-compose.yml` runs the official
  `hasura/graphql-engine:v2.50.3` image on port 8080 with `postgres:15-alpine`,
  the stack of upstream's `install-manifests/docker-compose`, without the
  optional Java data-connector agent. PostgreSQL data is on the named volume
  `db_data`; the console is served from the image (`/srv/console-assets`).
- `hooks/prepare.sh` generates the PostgreSQL password once into
  `~/.panelalpha/hasura/` (0600), with `HASURA_GRAPHQL_METADATA_DATABASE_URL`
  and `PG_DATABASE_URL`.
- `ready` makes `compose up -d` wait until `/healthz` answers.

The repository's root `docker-compose.yaml` is a developer database harness
(PostgreSQL, Citus, CockroachDB, SQL Server, data-connector agents) with no
graphql-engine service, so it is replaced.
