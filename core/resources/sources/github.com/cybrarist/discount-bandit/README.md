# Discount Bandit (github.com/cybrarist/discount-bandit)

Price tracker: Laravel + Filament on FrankenPHP/Octane (:80 in the
container), SQLite, queue worker and scheduler under supervisord, headless
Chromium for the store scrapers.

## Why a recipe

The repository's `docker-compose.yaml` leaves `APP_KEY:` empty. Deployed
as-is, Octane exits with `MissingAppKeyException` ("No application encryption
key has been specified") every few seconds and nothing answers on :80.

## What the recipe does

- `overrides/docker-compose.yml`: upstream's service on
  `cybrarist/discount-bandit:v4.0.4` (current release), SQLite dir
  `/app/database/sqlite` and `/logs` on named volumes, `APP_URL`/`ASSET_URL`
  set to the site's address.
- `hooks/prepare.sh` generates `APP_KEY=base64:...` once into
  `~/.panelalpha/discount-bandit/app.env`.
- A curl probe of Laravel's `/up` plus a no-op `ready` service gate the deploy.

First visit lands on `/register`, as upstream ships it. `EXCHANGE_RATE_API_KEY`
is optional; without it the app logs "Couldn't get the currencies".
