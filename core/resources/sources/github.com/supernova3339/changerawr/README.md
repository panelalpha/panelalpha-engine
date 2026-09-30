# Changerawr (github.com/supernova3339/changerawr)

Changelog publishing app (Next.js, Prisma, PostgreSQL).

## What the recipe does

- `overrides/docker-compose.yml` runs `ghcr.io/supernova3339/changerawr:v1.0.7.4`
  (upstream's `docker-compose-online.yml` image) on port 3000 with
  `postgres:16-alpine`. The repository's own compose builds
  `Dockerfile.compose`; its `next build` was SIGKILLed ("cannot allocate
  memory") in a 4096 MB account.
- The optional AI tag suggester (`ghcr.io/changerawr/tag-ai`, capped at 4 GB
  by upstream) is left out; the app works without it (`CHANGELOG_TAGGER_URL`
  unset).
- `hooks/prepare.sh` generates once into `~/.panelalpha/changerawr/secrets.env`
  (0600): the database password and URL, `JWT_ACCESS_SECRET`,
  `GITHUB_ENCRYPTION_KEY` (64 hex), `ENCRYPTION_KEY` (base64, 32 bytes),
  `ANALYTICS_SALT`, `INTERNAL_API_SECRET`.
- Data: `postgres_data`, `app_uploads` (/app/uploads) and `app_public`
  (/app/public/generated) are named volumes kept across redeploys.
- `ready` waits for `/api/health` (the setup-time maintenance server answers
  503 there; Next.js answers 200 once migrations ran).

The first visitor gets Changerawr's own setup wizard (`/setup`), as upstream
ships it. Extensions installed from the UI live in the container and are not
kept across a redeploy.
