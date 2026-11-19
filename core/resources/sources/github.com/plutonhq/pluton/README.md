# Pluton (github.com/plutonhq/pluton)

Backups with restic and rclone behind a web UI: one Node binary in the
published `plutonhq/pluton` image, SQLite and config under `/data`.

## Deploying

Deploy as is, open `/login` and sign in with the login the engine generates
(`PLUTON_USER_NAME`, default `admin`, and `PLUTON_USER_PASSWORD`): read it with
`GET /projects/{name}/app-credentials` (MCP `app_credentials_get`).

## What the recipe does

- The repository's `docker-compose.yml` runs as before (`pluton-data` volume
  on `/data`, port 5173).
- `overrides/docker-compose.override.yml` adds the engine's login as
  `env_file`. Pluton reads `PLUTON_USER_NAME` / `PLUTON_USER_PASSWORD` in place
  of `USER_NAME` / `USER_PASSWORD`, so the template's `admin` /
  `your-secure-password` (what `.env` gets from `.env.template`) no longer
  signs in.
- The web UI has no password change in Docker; the login is the environment's
  on every start. `pluton --reset-password` writes a new hash to
  `/data/keys.json`, but the next start hashes the environment's password over
  it again.
- `ENCRYPTION_KEY` (restic/rclone encryption) is still the template's
  `your-encryption-key-here-min-12-chars`; set it in the project's env_vars
  before the first backup to use your own.
