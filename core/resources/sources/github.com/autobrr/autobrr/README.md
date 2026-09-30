# autobrr (github.com/autobrr/autobrr)

Download automation for the *arr stack: watches IRC announce channels and
RSS/Torznab feeds, matches releases against filters and sends them to torrent
clients, Sonarr, Radarr and friends. One Go binary (UI + REST API `/api`) on
`:7474`, SQLite in `/config`.

## What the recipe does

- `overrides/docker-compose.yml` replaces the repository's dev compose (source
  build + two Postgres services) with `ghcr.io/autobrr/autobrr:v1.87.0`.
  `/config` (config.toml, autobrr.db) is a named volume, so the login, filters,
  indexers and clients survive redeploys.
- **First run is closed:** with no user autobrr sends every visitor to
  `/onboard`. The login is declared in `panelalpha.yaml` (`credentials:`): the
  engine generates `AUTOBRR_ADMIN_USER=admin` and a random password once and
  writes `~/.panelalpha/app-credentials.env` before the prepare hook on every
  deploy (`adopt_from` keeps the password of an account seeded from
  `~/.panelalpha/autobrr/admin.env`). `files/panelalpha-seed.sh`
  runs as the `seed` service: it starts autobrr on loopback (no published
  port), and if `GET /api/auth/onboard` answers 204 creates the user through
  `POST /api/auth/onboard`; it exits non-zero unless onboarding answers 503
  afterwards. The app service starts only after that; `ready` gates
  `compose up` on its health check.
- `AUTOBRR__HOST=0.0.0.0` (env wins over config.toml) and
  `AUTOBRR__CORS_ALLOWED_ORIGINS=${PA_PUBLIC_URL}` instead of the default `*`.
- Sessions are server-side rows in autobrr.db (scs), so there is no session
  secret to generate.

## Login

`GET /projects/{name}/app-credentials` (MCP `app_credentials_get`) returns the
username and password the seed created. API keys are
created in Settings > API keys. `autobrrctl --config /config change-password
admin` inside the app container resets it.
