# Ideon (github.com/3xpyth0n/ideon)

Project workspace on an infinite canvas (Next.js) with PostgreSQL, on :3000.

## Deploying

No variables are required. Open the site: the first visitor is sent to
`/setup` and creates the superadmin, as upstream ships it. SMTP and the
other optional settings from upstream's `env.example` can be set as project
env vars.

## What the recipe does

- `overrides/docker-compose.yml` is upstream's `docker-compose.yml` on
  `ghcr.io/3xpyth0n/ideon:0.9.7` (upstream uses `latest`) and
  `postgres:18-alpine`, with the values upstream reads from a `.env` the
  checkout does not ship. Without them the compose deploy failed on
  `The "DB_USER" variable is not set`.
- `hooks/prepare.sh` generates the database password and `SECRET_KEY` once
  into `~/.panelalpha/ideon/`. SECRET_KEY derives the data encryption keys:
  do not delete it.
- `APP_URL` and `AUTH_URL` are the public URL. `src/auth.ts` copies APP_URL
  into AUTH_URL, but only inside the Node server; the middleware's own
  NextAuth (`src/proxy.ts`) never sees it, logs `UntrustedHost` and redirects
  every logged-in request to `/login`.
- `/app/storage` (uploads, avatars, Yjs documents) and the database are named
  volumes. A no-op `ready` service holds `compose up` until `/api/health`
  answers.
