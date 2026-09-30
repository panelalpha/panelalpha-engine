# Offen Fair Web Analytics (github.com/offen/offen)

Privacy-friendly web analytics (Go, single binary with embedded UI, SQLite).

## Deploying

No environment variables are needed. After the first deploy open `/setup/`
on the site to create the first account and login; this is the app's own
first-run page.

## What the recipe does

- `overrides/docker-compose.yml` runs `offen/offen:v1.4.2` (`serve`) on port
  8080 with `OFFEN_SERVER_REVERSEPROXY=true` (the engine terminates TLS) and
  `/var/opt/offen` (the SQLite database) on the named volume `offen-data`,
  kept across redeploys. `ready` makes `compose up -d` wait for `/healthz`.
- `hooks/prepare.sh` generates `OFFEN_SECRET` once into
  `~/.panelalpha/offen/app.env`; without it Offen picks a random secret per
  start and every restart logs everyone out.
- The repository's `docker-compose.yml` (developer stack) is not used.
- SMTP is not configured (Offen logs a warning at start); invitation and
  password-reset mail need the app's `OFFEN_SMTP_*` settings.
