# Zoraxy (github.com/tobychui/zoraxy)

Reverse proxy and forwarding tool with a web control panel.

## Deploying

Nothing is required. The site opens Zoraxy's management UI; on first visit
`/login.html` shows "Account Setup" to create the admin, as upstream ships it.

Only the UI (:8000) is published. The account's domain is served by the
engine's own proxy, so Zoraxy's listeners on :80/:443 are not reachable from
outside and it cannot terminate traffic for other domains here.

## What the recipe does

- `overrides/docker-compose.yml` replaces `docker/docker-compose.yml`
  (all four ports published, placeholder bind mounts, `latest` tag, Docker
  socket) with `zoraxydocker/zoraxy:v3.3.4`, config and plugins on the
  `zoraxy-config` / `zoraxy-plugin` volumes.
- A no-op `ready` service holds the deploy until the UI port listens.
