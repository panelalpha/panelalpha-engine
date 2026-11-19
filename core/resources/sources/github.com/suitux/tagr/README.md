# Tagr (github.com/suitux/tagr)

Music library tagger: Next.js with Prisma on SQLite under `/data`, in the
published `ghcr.io/suitux/tagr` image.

## Deploying

Deploy as is, open `/login` and sign in with the login the engine generates
(`TAGR_ADMIN_USER`, default `admin`, and `TAGR_ADMIN_PASSWORD`): read it with
`GET /projects/{name}/app-credentials` (MCP `app_credentials_get`). No music
folder is mounted, so the library starts empty.

## What the recipe does

- The repository's `docker-compose.yml` runs as before (`sqlite_data` volume
  on `/data`, port 3000).
- Tagr's admin is `AUTH_USER` / `AUTH_PASSWORD` from the environment, not a
  database row. The compose file sets them to `admin` / `change-me` in
  `environment:`, which beats `env_file`, so
  `overrides/docker-compose.override.yml` adds the engine's login as `env_file`
  under other names and its `command` exports them as `AUTH_USER` /
  `AUTH_PASSWORD`, then runs the image's `./docker-entrypoint.sh`. The compose
  file's `admin` / `change-me` no longer signs in.
- The admin has no password form in the app (Settings > Users manages the
  tagger and listener users in the database), so the login is the
  environment's on every start.
