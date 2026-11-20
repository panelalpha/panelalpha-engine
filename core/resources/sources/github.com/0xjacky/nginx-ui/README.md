# Nginx UI (github.com/0xJacky/nginx-ui)

Web UI for nginx: edit sites and config, issue certificates, view logs. The
container runs its own nginx, which serves the UI and any sites created in it.

## Deploying

Deploy as is, open `/#/login` and sign in with the login the engine generates
(`NGINX_UI_PREDEFINED_USER_NAME`, default `admin`, and
`NGINX_UI_PREDEFINED_USER_PASSWORD`): read it with
`GET /projects/{name}/app-credentials` (MCP `app_credentials_get`).

## What the recipe does

- `overrides/docker-compose.yml` runs `uozi/nginx-ui:v2.8.1` with the bundled
  nginx on container port 80 (published as 8080), `/etc/nginx` and
  `/etc/nginx-ui` on the named volumes `nginx-conf` and `nginx-ui-data`.
- `ready` makes `compose up -d` wait until the UI answers.
- The login: upstream's install page asks for a one-time secret that only the
  `app` log and the hidden `/etc/nginx-ui/.install_secret` hold, for 10 minutes
  after the start. `NGINX_UI_NODE_SKIP_INSTALLATION=true` skips it (Nginx UI
  then generates its own JWT secret into `app.ini` on the volume), and the
  `app` service reads `NGINX_UI_PREDEFINED_USER_NAME` /
  `NGINX_UI_PREDEFINED_USER_PASSWORD` from `../.panelalpha/app-credentials.env`.
  Nginx UI sets them on its first user only while that user has no password
  (a new database), so a password changed in the UI stays changed. An account
  installed before this recipe through the install page keeps its own login.
- Not included: upstream's `/var/run/docker.sock` mount (container control from
  the UI), and host ports 80/443, so sites defined in the UI are reachable only
  through this project's own domain.
