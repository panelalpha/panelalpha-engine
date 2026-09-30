# Notifuse (github.com/notifuse/notifuse)

Newsletter, email marketing and transactional email platform (Go server with
a React console, PostgreSQL).

## What the recipe does

- `overrides/docker-compose.yml` runs `notifuse/notifuse:v41.0` on port 8080
  with `postgres:17-alpine` (the version upstream pins; 18 moves PGDATA). The
  repository's `compose.yaml` builds the Dockerfile, whose console
  `lingui compile && tsc -b && vite build` was SIGKILLed ("cannot allocate
  memory") in a 2500 MB account (and in a 2048 MB one after 17 minutes).
- `hooks/prepare.sh` generates once into `~/.panelalpha/notifuse/secrets.env`
  (0600): the PostgreSQL password (`POSTGRES_PASSWORD` = `DB_PASSWORD`) and
  `SECRET_KEY`, which signs sessions and encrypts stored provider
  credentials, so it must never change.
- Notifuse connects as the `postgres` superuser because it creates its
  system database and one database per workspace itself (`DB_PREFIX`
  default `notifuse`), as upstream's compose does.
- Data: `postgres_data` and `app_data` (/app/data, a bind mount upstream) are
  named volumes kept across redeploys.
- The SMTP bridge port 587 (inbound mail) is not published.
- `ready` waits for `/healthz`.

The first visitor gets Notifuse's own setup wizard (`/console/setup`: root
email, API endpoint, SMTP), as upstream ships it.
