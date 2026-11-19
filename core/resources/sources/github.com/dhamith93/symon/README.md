# SyMon (github.com/dhamith93/symon)

System monitor: a Go collector (gRPC on 9000) on TimescaleDB, the dashboard
(`client`, port 8080) and an agent reporting on its own container, all built
from the repository's Dockerfile by its `docker-compose.yml`.

## Deploying

Deploy as is, open the site and sign in with the login the engine generates
(`SYMON_ADMIN_USER`, default `admin`, and `SYMON_ADMIN_PASSWORD`): read it with
`GET /projects/{name}/app-credentials` (MCP `app_credentials_get`).

## What the recipe does

- The repository's own `docker-compose.yml` is used as it is;
  `overrides/docker-compose.override.yml` gives the `collector` service the
  engine's login as `env_file` and `files/panelalpha-symon-start.sh` as its
  command.
- Upstream's dashboard stays locked behind "Create the first user" until
  someone runs `collector -add-user <name>` on the server. The wrapper does
  that with the generated username and password (`-password-stdin`), only
  while `collector -list-users` says there are no users yet, then execs
  `./collector`. A database that already has a user is left alone, so a
  password changed in the dashboard (`POST /api/v1/password`) is never
  overwritten.
- `hooks/prepare.sh` makes the wrapper readable by the image's `symon` user.
