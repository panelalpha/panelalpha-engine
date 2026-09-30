# FredBet (github.com/fred4jupiter/fredbet)

Spring Boot web app for friendly football betting pools, with user
administration, rankings and an image gallery. One Java process on `:8080`;
all data (users, bets, uploaded images) lives in the database.

## What the recipe does

- `overrides/docker-compose.yml` replaces the repository compose, which builds
  from source behind `profiles: ["app"]` (a plain `compose up` starts only
  Postgres, published with fred/fred). It runs `fred4jupiter/fredbet:4.8.3`
  against `postgres:17-alpine` on the `db_data` volume; only `:8080` is
  published.
- **admin/admin never exists:** FredBet creates the admin from
  `fredbet.admin-username` / `fredbet.admin-password` only when that user is
  missing (`DatabaseInitializer.createAdminUser()` ->
  `UserService.createUserIfNotExists()`). The engine generates
  `FREDBET_ADMIN_USERNAME` / `FREDBET_ADMIN_PASSWORD` (`credentials:` in
  `panelalpha.yaml`) into `~/.panelalpha/app-credentials.env`, so the first
  start creates the admin with the generated password, and a password changed in the UI survives
  redeploys.
- Self-registration is off by default (runtime setting) and even when enabled
  needs a random registration code, so no first-visitor account is possible.
- `SERVER_FORWARD_HEADERS_STRATEGY=native`: TLS ends at the proxy, redirects
  are built from `X-Forwarded-*`.
- `ready` gates `compose up` on `/actuator/health` reporting `UP`.

## Login

`GET /projects/{name}/app-credentials` (MCP `app_credentials_get`) returns it. The
admin creates the players under Users. A user reset by an admin gets the
"password for reset" runtime setting; change it under Administration >
Runtime settings before resetting anyone.
