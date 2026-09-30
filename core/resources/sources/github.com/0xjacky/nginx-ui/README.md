# Nginx UI (github.com/0xJacky/nginx-ui)

Web UI for nginx: edit sites and config, issue certificates, view logs. The
container runs its own nginx, which serves the UI and any sites created in it.

## Deploying

Nothing is required. Open the site and finish the app's own install page
(admin user). It asks for a one-time install secret, which the app prints in the
`app` service log (`One-time install secret (valid for 10m0s): ...`). The
installer closes 10 minutes after the container starts; a redeploy reopens it
with a new secret.

## What the recipe does

- `overrides/docker-compose.yml` runs `uozi/nginx-ui:v2.8.1` with the bundled
  nginx on container port 80 (published as 8080), `/etc/nginx` and
  `/etc/nginx-ui` on the named volumes `nginx-conf` and `nginx-ui-data`.
- `ready` makes `compose up -d` wait until the UI answers.
- Not included: upstream's `/var/run/docker.sock` mount (container control from
  the UI), and host ports 80/443, so sites defined in the UI are reachable only
  through this project's own domain.
