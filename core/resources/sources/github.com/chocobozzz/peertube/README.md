# PeerTube (github.com/Chocobozzz/PeerTube)

Federated video platform: one Node server on :9000 that serves the API and its
Angular client, with PostgreSQL and Redis.

## Deploying

Required project env var: `PEERTUBE_ADMIN_EMAIL`, the email of the `root`
account. Without it the deploy fails naming the variable. On first start
PeerTube creates `root` and prints its generated password in the container log
(`docker compose logs peertube | grep "User password"`), as upstream does; set
`PT_INITIAL_ROOT_PASSWORD` beforehand to choose it. SMTP, object storage and
the other options take the upstream `PEERTUBE_*` variables.

## What the recipe does

- The repository root is the pnpm source tree; the plain deploy restart-loops
  with `exec: pnpm: not found` and has no database anyway.
  `overrides/docker-compose.yml` runs `chocobozzz/peertube:v8.3.1` with
  `postgres:17-alpine` and `redis:8-alpine`, as `support/docker/production`,
  without its nginx, certbot and postfix sidecars and without the RTMP port.
- `PEERTUBE_WEBSERVER_HOSTNAME` is the site's address (https, 443).
- `hooks/prepare.sh` generates `PEERTUBE_SECRET` and the database password once
  into `~/.panelalpha/peertube/secrets.env`.
- Data: `data` (/data: videos, avatars, logs), `config` (/config: settings
  saved from the admin UI), `db`, `redis` named volumes. A `ready` gate waits
  for `/api/v1/ping`.
- The image's default `trust_proxy` (loopback, link-local, private ranges)
  resolves the visitor from the proxy-added hop; a client-sent
  `X-Forwarded-For` is ignored.
