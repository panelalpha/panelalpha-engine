# Umami for PanelAlpha Engine

Umami ships its own `docker-compose.yml` with hardcoded development credentials.
`panelalpha-after-clone.sh` generates random secrets once into
`~/.panelalpha/umami/secrets.env` (0600 in a 0700 directory, outside `~/project`,
which is emptied on every deploy) and copies them to `.env` on every deploy;
`docker-compose.override.yml` (a file snippet copied verbatim) overrides those
values and mounts the `docker/` helper directory. Docker Compose automatically
merges the override — the upstream `docker-compose.yml` is never modified.

App management (`users:list`, `users:add`, etc.) is handled by a Node.js CLI helper
(`docker/panelalpha-cli.mjs`) that runs inside the `umami` container.  It
bootstraps authentication by generating a stateless Bearer token from the
`APP_SECRET` already present in the container's environment and the admin user's
UUID fetched via the Prisma client (which is explicitly installed in the Umami
runner image for its own scripts).  All user-management operations then go through
the official Umami REST API — no credentials are stored anywhere.

SSO uses Umami's built-in `/sso` page (Pattern A, same as WordPress): `users:sso`
generates an encrypted JWT for the target user inside the container and returns a
URL the browser visits to set `localStorage['umami.auth']` and redirect to
`/websites`.  No engine changes are required.

## PanelAlpha Engine snippets

- `panelalpha-after-clone.sh` — [`hooks/prepare.sh`](hooks/prepare.sh)
- `./docker-compose.override.yml` — [`overrides/docker-compose.override.yml`](overrides/docker-compose.override.yml)
- `docker/panelalpha-cli.mjs` — [`files/docker/panelalpha-cli.mjs`](files/docker/panelalpha-cli.mjs)
- `panelalpha-app.sh` — [`overrides/app.sh`](overrides/app.sh)