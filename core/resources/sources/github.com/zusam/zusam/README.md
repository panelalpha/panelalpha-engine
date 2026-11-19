# Zusam (github.com/zusam/zusam)

Private forum for friends and family groups: a PHP/Symfony API with the
prebuilt web app, SQLite, nginx and php-fpm under s6, in the published
`zusam/zusam` image.

## Deploying

Deploy as is, open `/login` and sign in with the login the engine generates
(`INIT_USER`, default `admin`, and `INIT_PASSWORD`): read it with
`GET /projects/{name}/app-credentials` (MCP `app_credentials_get`). The first
group is `zusam`.

## What the recipe does

- The repository has no deployable Dockerfile or compose file (upstream builds
  its Dockerfile with `cpp`), so `overrides/docker-compose.yml` runs the image
  of the current release, `zusam/zusam:0.6.1`, with `/zusam/data` (SQLite,
  config, uploads) on the named volume `data`, and a `ready` service that
  makes `compose up -d` wait for the API.
- The image's `run.sh` creates the first user from `INIT_USER` /
  `INIT_PASSWORD` while the database is empty, defaulting to `zusam` /
  `zusam`. The compose file hands it the engine's login from
  `~/.panelalpha/app-credentials.env` (the project's `.env` after it, so
  env_vars still win), so a new installation never has the default.
- `zusam:init` makes a user (and a group) only when no user has that login
  yet, so a password changed in Zusam stays changed; the engine keeps
  reporting the generated one. In 0.6.1 `run.sh` runs it on every start (its
  database path check reads empty), so a user who renames the `admin` login
  in Zusam gets a new `admin` with the engine's password on the next start,
  as upstream does with `zusam` / `zusam`.
- An installation from before the engine owned the login may still have
  `zusam` / `zusam` on its volume; its next start adds `admin` with the
  engine's login next to it (in a new `zusam` group), as above.
  `files/panelalpha-zusam-login.php`, run by `ready` once the API answers,
  signs in with `zusam` / `zusam` and, only if that works, gives that user the
  engine's login through Zusam's own API (`PUT /api/users/{id}`): the login
  and password, or the password alone when the login is taken, as it then is.
  Otherwise it does nothing.
