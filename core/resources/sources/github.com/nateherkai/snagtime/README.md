# Snagtime (github.com/nateherkai/snagtime)

Scheduling app with booking links and Google Calendar sync. Next.js web server,
a dedicated outbox worker and PostgreSQL 18, built from the repository's own
Dockerfile (targets `runtime` and `builder`) and `infrastructure/postgresql`.

## Deploying

Upstream's production mode will not start without these project environment
variables (`apps/web/src/server/auth/session.ts`,
`assertProductionRuntimeSecurity`):

| Variable | Format |
|---|---|
| `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET` | OAuth client, id ends in `.apps.googleusercontent.com` |
| `STRIPE_SECRET_KEY`, `STRIPE_WEBHOOK_SECRET` | test mode only: `sk_test_...`, `whsec_...` |
| `SMTP_HOST`, `SMTP_PORT`, `SMTP_TLS_MODE`, `SMTP_USER`, `SMTP_PASSWORD` | `SMTP_TLS_MODE` is `implicit` or `starttls` |
| `EMAIL_FROM`, `EMAIL_REPLY_TO`, `EMAIL_SENDER_DOMAIN` | the From address must be `@EMAIL_SENDER_DOMAIN` |

Without them the deploy fails at `env-check` naming each missing one.

## What the recipe does

- `hooks/prepare.sh` generates, once, into `~/.panelalpha/snagtime/secrets`
  (0600, dir 0700): the owner and four role-login passwords, the application
  secrets, and a private CA + server certificate for PostgreSQL (upstream
  requires `sslmode=verify-full`). It writes `docker-compose.override.yml`
  with `BUILD_ID` = the checked-out commit (the image refuses any other) and
  runs web/worker as the account uid so they can read the secret files.
- `overrides/docker-compose.yml` is upstream's `compose.production.yml` with
  file secrets: `postgres` (data on the named volume `postgres_data`), `init`
  (one-shot, as owner: `prisma migrate deploy` + upstream's
  `scripts/provision-postgres-logins.mjs`), `worker`, `app` on port 3000, and
  `ready`, which waits on upstream's `/api/health/ready`.
- `TRUST_PROXY=true` is required by the production contract, but the engine's
  proxy does not add `x-tempocove-proxy-secret`, so every visitor shares one
  rate-limit bucket.
