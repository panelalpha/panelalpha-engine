# Jellyswarrm (github.com/llukas22/jellyswarrm)

Reverse proxy that merges several Jellyfin servers into one library: a single
Rust binary serving the bundled Jellyfin web client on `/` and its management
UI on `/ui`, port 3000, SQLite and `jellyswarrm.toml` in `/app/data`.

## Deploying

Deploy as is, open `/ui/login` and sign in with the login the engine generates
(`JELLYSWARRM_USERNAME`, default `admin`, and `JELLYSWARRM_PASSWORD`): read it
with `GET /projects/{name}/app-credentials` (MCP `app_credentials_get`).

## What the recipe does

- `overrides/docker-compose.yml` runs the published image of release 0.3.0
  (pinned by digest) instead of building the Dockerfile, which is OOM-killed in
  its webpack stage, with `/app/data` on the named volume `data`.
- The engine's login reaches the container as `env_file`
  (`../.panelalpha/app-credentials.env`); upstream reads `JELLYSWARRM_USERNAME`
  and `JELLYSWARRM_PASSWORD` over `jellyswarrm.toml` on every start, so the
  built-in `admin` / `jellyswarrm` never works. `/ui` has no form to change the
  admin login, so nothing a user chose is overwritten. The project's own
  `.env` comes last and still wins; the engine then reports those values.
- Keep the password once Jellyfin servers are added: Jellyswarrm encrypts the
  server and user-mapping passwords it stores with it.
