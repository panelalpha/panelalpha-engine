# Plausible CE (github.com/plausible/community-edition)

Privacy-friendly web analytics. Upstream's deployment repository: the
`ghcr.io/plausible/community-edition` image on :8000, PostgreSQL for users and
sites, ClickHouse for events.

## Deploying

No variables are required. The first visit redirects to `/register`, where
the first account is created (upstream's first-run behaviour). Mail, Google
integration and geolocation use upstream's optional variables (`MAILER_*`,
`SMTP_*`, `GOOGLE_CLIENT_*`, `MAXMIND_*`, `DISABLE_REGISTRATION`, ...) set as
project env vars.

## What the recipe does

- `overrides/docker-compose.yml` is upstream's `compose.yml` (image
  `v3.2.1`) plus: `ports: 8000:8000` on `plausible` (upstream expects your own
  reverse proxy and publishes nothing), `BASE_URL=${PA_PUBLIC_URL}`, and the
  secrets from `~/.panelalpha/plausible-ce/` instead of a hand-written `.env`.
- `hooks/prepare.sh` writes `SECRET_KEY_BASE` (`openssl rand -base64 48`, 64
  characters; Phoenix needs 64 bytes, more than the engine's generated
  48-character secrets, engine#338) and the PostgreSQL password once. They are
  never regenerated: the database volume keeps the first password.
- A `ready` service waits on `/api/health` (PostgreSQL + ClickHouse), so the
  deploy finishes once the site answers.
- Data: named volumes `db-data`, `event-data`, `event-logs`, `plausible-data`.
