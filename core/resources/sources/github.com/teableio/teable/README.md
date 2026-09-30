# Teable (github.com/teableio/teable)

No-code database with a spreadsheet interface over Postgres; Next.js
frontend and NestJS backend in one image.

Plain deploy: the `nextjs` recipe builds and runs only the Next.js frontend
of the pnpm monorepo, with no backend or database, and every page answers 500.

## What the recipe does

- `overrides/docker-compose.yml`: upstream's
  `dockers/examples/standalone/docker-compose.yaml` on the published release
  image `ghcr.io/teableio/teable:release.2026-09-27T13-21-53Z.3275` (upstream
  publishes `release.<timestamp>` tags and `latest`, no semver image tags;
  this is the digest `latest` pointed at, app version 1.10.0), with
  `postgres:15.4` and `redis:7.2.4`. `PUBLIC_ORIGIN` is the engine's public
  address. The database port upstream publishes (42345, for its
  "connect with a SQL client" feature) is not published.
- `hooks/prepare.sh` writes `SECRET_KEY` (signs JWTs and session cookies),
  the Postgres and Redis passwords and the URLs built from them once to
  `~/.panelalpha/teable/teable.env`.
- Attachments (`/app/.assets`, local storage), Postgres and Redis are named
  volumes.
- The app container gets 2 GB; the image sizes the V8 heap from it
  (`--max-old-space-size=1433`) and idles at ~1.3 GB.
- A no-op `ready` service waits for the server to listen, so the deploy ends
  after the Prisma migrations.

## First run

Upstream's: `/` redirects to the sign-up page and the first account to sign up
becomes the instance admin.
