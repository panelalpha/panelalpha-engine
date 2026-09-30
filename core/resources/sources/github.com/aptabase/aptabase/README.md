# Aptabase (github.com/aptabase/aptabase)

Privacy-first analytics for mobile and desktop apps: the published
`ghcr.io/aptabase/aptabase` image (.NET on :8080), PostgreSQL for accounts and
apps, ClickHouse for events. Upstream's own deployment is the separate
`aptabase/self-hosting` compose, which this recipe follows.

## Deploying

No variables are required. The site opens Aptabase's sign-in / register page.
Sign-in is by a magic link sent by e-mail: set `SMTP_HOST`, `SMTP_PORT`,
`SMTP_USERNAME`, `SMTP_PASSWORD` and `SMTP_FROM_ADDRESS` as project env vars.
Without them upstream logs the link instead (`container_service_logs` of the
`aptabase` service). GitHub/Google OAuth: `OAUTH_GITHUB_CLIENT_ID/SECRET`,
`OAUTH_GOOGLE_CLIENT_ID/SECRET`.

## What the recipe does

- `overrides/docker-compose.yml` replaces the repository's development compose
  (databases and a mail catcher, no app) with upstream's self-hosting stack.
  Upstream publishes only a moving `main` tag, so the image is pinned by
  digest to the build of HEAD `f5b48b28` (2026-09-13).
- `hooks/prepare.sh` writes `AUTH_SECRET` and the PostgreSQL and ClickHouse
  passwords once into `~/.panelalpha/aptabase/`; `BASE_URL` is the site's address.
- A `ready` service waits for the app's `/healthz`.
- Data: named volumes `db-data`, `events-db-data`, and `aspnet-keys` (the
  ASP.NET keys behind the login cookie, so a redeploy does not sign users out).
