# Grafana (github.com/grafana/grafana)

Observability and dashboards: one Go binary serving the web UI and HTTP API on
:3000, SQLite and plugins under `/var/lib/grafana`.

## Deploying

Deploy as is, open `/login` and sign in with the login the engine generates
(`GF_SECURITY_ADMIN_USER`, default `admin`, and `GF_SECURITY_ADMIN_PASSWORD`):
read it with `GET /projects/{name}/app-credentials` (MCP `app_credentials_get`).

## What the recipe does

- `overrides/docker-compose.yml` runs the official `grafana/grafana:13.2.2`
  image instead of the repository's source build, with `/var/lib/grafana` on
  the named volume `grafana-data` and `GF_SERVER_ROOT_URL` set to the site's
  public URL.
- The engine's login reaches Grafana as `env_file`. `GF_SECURITY_ADMIN_USER` /
  `GF_SECURITY_ADMIN_PASSWORD` are Grafana's own settings for the admin it
  creates on a new database, so the built-in `admin` / `admin` never exists
  and a password changed in Grafana is never overwritten.
- `files/panelalpha/grafana-login.sh` runs in the `ready` gate once Grafana is
  healthy. A database made before the recipe had the engine's login still has
  `admin` / `admin`; while that signs in, the script sets the engine's
  username and password on that admin through Grafana's API
  (`PUT /api/user`, `PUT /api/user/password`). Otherwise it does nothing.
