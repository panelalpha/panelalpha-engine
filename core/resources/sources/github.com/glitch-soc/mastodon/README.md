# glitch-soc (github.com/glitch-soc/mastodon)

Mastodon fork with extra features (local-only posts, a second "glitch" UI
flavour, longer posts). Rails (puma) + Sidekiq, Postgres, Redis. Same layout
as upstream Mastodon; this recipe mirrors `github.com/mastodon/mastodon/`.

Plain deploy: the repository's `docker-compose.yml` runs
`ghcr.io/glitch-soc/mastodon:v4.7.2`, but every service reads
`.env.production`, which the repository does not ship. The engine creates it
empty; `web` and `sidekiq` exit with `Mastodon now requires that these
variables are set: ACTIVE_RECORD_ENCRYPTION_*` and restart forever.

## What the recipe does

- `overrides/docker-compose.yml`: upstream's services on the release image
  `ghcr.io/glitch-soc/mastodon:v4.7.2`: `app` (puma on :3000), `sidekiq`,
  `postgres:14-alpine`, `redis:7-alpine`, configured through `environment:`
  (`LOCAL_DOMAIN` is the site's host, Elasticsearch and S3 off).
- One-shot `mastodon-migrate`: `rails db:prepare` loads the schema and seeds
  on an empty database and migrates an existing one.
- `hooks/prepare.sh` writes `SECRET_KEY_BASE`, the three
  `ACTIVE_RECORD_ENCRYPTION_*` keys, a VAPID key pair (openssl, P-256) and
  the database password once to `~/.panelalpha/glitch-soc/mastodon.env`.
- Uploads (`public/system`) on the named volume `mastodon-system`.
- A no-op `ready` service waits for `/health`, so the deploy ends once puma
  answers.

## Not included

- The streaming server (`ghcr.io/glitch-soc/mastodon-streaming`, :4000): the
  engine proxies one port and upstream routes `/api/v1/streaming` with nginx.
  The web UI polls instead of streaming.
- SMTP: without `SMTP_*` variables no confirmation mail is sent. Registrations
  are closed by default (upstream); create accounts with
  `tootctl accounts create <name> --email <addr> --confirmed --approve --role Owner`
  in the `app` container.
