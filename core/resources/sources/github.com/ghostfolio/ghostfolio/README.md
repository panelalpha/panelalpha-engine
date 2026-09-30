# Ghostfolio (github.com/ghostfolio/ghostfolio)

Privacy-focused wealth management dashboard (NestJS API, Angular client,
PostgreSQL, Redis).

## Deploying

No project environment variables are needed. Open the site and click
**Get Started**: the first account created becomes the admin (upstream
behaviour). Market data providers are configured in the admin panel.

## What the recipe does

- `overrides/docker-compose.yml` runs the official
  `ghostfolio/ghostfolio:3.75.0` image on port 3333 with `postgres:15-alpine`
  and `redis:8.2-alpine` (password-protected), the stack of upstream's
  `docker/docker-compose.yml`. PostgreSQL and Redis data are on the named
  volumes `postgres` and `redis`, kept across redeploys.
- `hooks/prepare.sh` generates the PostgreSQL and Redis passwords,
  `ACCESS_TOKEN_SALT` and `JWT_SECRET_KEY` once into
  `~/.panelalpha/ghostfolio/` (0600), so a rebuild keeps logins and data.
- The image runs `prisma migrate deploy` and the seed on boot; `ready` makes
  `compose up -d` wait until `/api/v1/health` answers.

The repository's Dockerfile is not built: its Nx production build of the
Angular client ran out of memory in a 2500 MB account.
