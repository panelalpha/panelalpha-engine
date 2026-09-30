# Appsmith (github.com/appsmithorg/appsmith)

Low-code builder for internal tools. One all-in-one container: Caddy on :80,
the Java backend, the realtime server and bundled MongoDB, Redis and
PostgreSQL.

## Deploying

Give the project `memory_limit` 4096: the stack uses about 3 GB.

## What the recipe does

- `overrides/docker-compose.yml`: upstream's self-hosting compose on
  `appsmith/appsmith-ce:v2.4.2`, replacing the monorepo source.
- The image generates its encryption password and salt into
  `/appsmith-stacks/configuration/docker.env` on first boot. That directory is
  the `stacks` volume, so apps, data and keys survive a redeploy.
- Optional `APPSMITH_*` settings (mail, OAuth, ...) are passed through from the
  project's environment.
- The first visitor gets upstream's sign-up page and becomes the admin.
- A no-op `ready` service waits for the image's health check and
  `/api/v1/health`, so the deploy ends once the backend answers.
