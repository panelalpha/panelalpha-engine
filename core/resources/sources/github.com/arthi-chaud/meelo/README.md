# Meelo (github.com/arthi-chaud/meelo)

Personal music server: NestJS API, Next.js front, Go scanner, Python matcher,
transcoder, PostgreSQL, Meilisearch and RabbitMQ behind upstream's nginx on
:5000.

## Deploying

Nothing to set. Open the site and create the first account on Meelo's own
sign-up page (the first account is the admin). Music goes into the `data`
volume (`/data`); scan it from the web UI. `ALLOW_ANONYMOUS`,
`ENABLE_USER_REGISTRATION` and `LASTFM_API_KEY` / `LASTFM_API_SECRET` can be set
as project environment variables and apply on the next deploy.

## What the recipe does

- `overrides/docker-compose.yml` replaces the repository's build-from-source
  compose with upstream's `docker-compose.prod.yml` on the `v3.13.0` images of
  `arthichaud/meelo-{server,front,scanner,matcher}`, keeping upstream's
  transcoder, datastores and nginx (`nginx.conf.template` from the repository).
- `/data`, `/config` (illustrations), PostgreSQL, Meilisearch, RabbitMQ and the
  transcoder cache are on named volumes, kept across redeploys. The
  repository's `settings.json` (upstream's default) is mounted read-only into
  `/config`.
- The database, RabbitMQ, JWT, Meilisearch and internal API secrets are
  generated per account by the engine and stay stable across redeploys.
- `PUBLIC_*_URL` of the front are the site's address.
- `ready` (no-op) holds the deploy until the front answers.
