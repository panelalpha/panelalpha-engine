# Unleash (github.com/Unleash/unleash)

Feature-flag management server: Node on port 4242, PostgreSQL.

## Deploying

Deploy as is, open `/login` and sign in with the login the engine generates
(`UNLEASH_DEFAULT_ADMIN_USERNAME`, default `admin`, and
`UNLEASH_DEFAULT_ADMIN_PASSWORD`): read it with
`GET /projects/{name}/app-credentials` (MCP `app_credentials_get`). The field
labelled "Email" on the login form takes the username.

## What the recipe does

- The repository's `docker-compose.yml` (upstream's demo stack:
  `unleashorg/unleash-server:latest`, `postgres:15`) runs as it is, with
  `overrides/docker-compose.override.yml` layered over it.
- The login: a new database gets the built-in `admin` / `unleash4all`, and the
  login page's "Sign up" link goes to getunleash.io. The override gives the
  `web` service `../.panelalpha/app-credentials.env`, so Unleash's own
  `UNLEASH_DEFAULT_ADMIN_USERNAME` / `UNLEASH_DEFAULT_ADMIN_PASSWORD` are the
  engine's. Unleash creates that admin only while it has no user at all, so a
  password changed in Unleash is never overwritten.
- The `db` service gets the named volume `db-data`: the repo's has none, so the
  database lived in an anonymous volume a redeploy leaves behind, and with it
  every flag and a password changed in Unleash.
- Left as upstream ships it: the demo's `INIT_FRONTEND_API_TOKENS` /
  `INIT_BACKEND_API_TOKENS` (`default:development.unleash-insecure-...`),
  client SDK tokens with public values that read the `development` flags.
