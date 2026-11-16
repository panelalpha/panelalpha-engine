# Rallly (github.com/lukevella/rallly)

Meeting-scheduling polls (a Doodle alternative): Next.js on :3000 and
PostgreSQL.

## Deploying

Deploy as is. `SUPPORT_EMAIL`, which Rallly refuses to start without and shows
users as the support contact, is the project's email, or an address on the
site's domain without one; the engine keeps it with the project's app
credentials (`GET /projects/{name}/app-credentials`). A project env var
`SUPPORT_EMAIL`, set before the first deploy, is used instead.

Rallly signs users in with a one-time code sent by email, so set `SMTP_HOST`,
`SMTP_PORT`, `SMTP_USER`, `SMTP_PWD` (and `SMTP_SECURE`) to be able to log in.
Every other upstream setting (`ALLOWED_EMAILS`, `INITIAL_ADMIN_EMAIL`,
`OIDC_*`, `NOREPLY_EMAIL`, ...) is passed through from the project's
environment as well.

## What the recipe does

- `overrides/docker-compose.yml`: upstream's self-hosted stack
  (lukevella/rallly-selfhosted) on `lukevella/rallly:4.15.2` and
  `postgres:18-alpine`, replacing the repository's from-source dev compose.
- `SECRET_PASSWORD` and the database password are generated per account by
  the engine and stay stable across redeploys.
- `NEXT_PUBLIC_BASE_URL` is the site's address.
- Data on the `db-data` volume.
- A no-op `ready` service waits for the image's `/api/status` health check, so
  the deploy ends after the migrations have run.
