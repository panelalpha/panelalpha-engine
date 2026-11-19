# Traefik Manager (github.com/chr0nzz/traefik-manager)

Web UI for Traefik's dynamic configuration: Flask on gunicorn in one Alpine
image, settings in `/app/config/manager.yml`, backups in `/app/backups`. No
Traefik runs next to it on the engine, so its routes, certs and logs tabs have
nothing to read; the dynamic config file is edited as is.

## Deploying

Deploy as is, open `/login` and sign in with the password the engine generates
(`TM_ADMIN_PASSWORD`; there is no username): read it with
`GET /projects/{name}/app-credentials` (MCP `app_credentials_get`). The first
sign-in opens Traefik Manager's setup wizard, which asks for the domains, the
Traefik API URL and a new password of your own.

## What the recipe does

- The repository's `docker-compose.yml` runs as before (its Dockerfile, port
  5000); the engine drops its `/path/to/...` host binds.
- With no password set, Traefik Manager's first start writes a random
  temporary one to the container log only. `overrides/docker-compose.override.yml`
  adds the engine's password as `env_file` and runs
  `files/panelalpha-traefik-manager-start.sh` as the command, through the
  image's own entrypoint: while `manager.yml` has no `password_hash`, it pipes
  the engine's password to `flask reset-password --stdin`, Traefik Manager's
  documented way to set your own password (no forced change), so the
  temporary one stops working. Then it runs the image's gunicorn command.
- The app's import still prints a temporary password to the log before the
  reset replaces it; that one never signs in.
- A password set in the setup wizard or changed later is a `password_hash`
  already there, so the next start leaves it alone; the engine keeps reporting
  the password it generated.
- The password is not passed as `ADMIN_PASSWORD`: Traefik Manager would then
  compare that variable on every login and turn off the in-app password
  change and 2FA.
- `/app/config` and `/app/backups` are on the named volumes
  `traefik-manager-config` and `traefik-manager-backups` in place of the
  dropped binds, so settings and the password survive a redeploy.
