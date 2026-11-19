# LibreTime (github.com/libretime/libretime)

Radio broadcast automation (fork of Airtime): media library, show scheduling
and playout to an Icecast stream, with a PHP web UI behind nginx on port 8080.

## Deploying

Deploy as is, open `/login` and sign in with the login the engine generates
(`LT_ADMIN_USERNAME`, default `admin`, and `LT_ADMIN_PASSWORD`): read it with
`GET /projects/{name}/app-credentials` (MCP `app_credentials_get`).

## What the recipe does

- `overrides/docker-compose.yml` is upstream's compose of release 4.5.0 on the
  published `ghcr.io/libretime` images, with only the web UI published.
- `hooks/prepare.sh` generates the Postgres, RabbitMQ and Icecast passwords and
  the API/secret keys once into `~/.panelalpha/libretime/secrets.env`.
- `files/panelalpha/libretime/render-config.sh` (the `config` one-shot) writes
  `config.yml` with the account's address and those secrets onto a volume.
- The `migrate` one-shot runs upstream's `libretime-api migrate` (a new
  database gets the built-in `admin` / `admin`), then
  `files/panelalpha/libretime/set-admin-login.py` through `libretime-api shell`:
  LibreTime's own User model sets the engine's username and password on that
  admin while its password is still `admin`. A password changed in LibreTime is
  never overwritten, and a failure there leaves `admin` / `admin` with a line
  in the log rather than holding the services back.
- `files/docker-compose.override.yml` stands in for the repository's
  development override, which the engine would otherwise layer over the
  replacement.
