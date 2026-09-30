# Bonfire (github.com/bonfire-networks/bonfire-app)

Federated social platform (Elixir/Phoenix LiveView).

## What the recipe does

- The repo's `docker-compose.yml` is the development stack: the engine builds
  `Dockerfile.dev`, whose `cargo build` of `forks/messctl` fails.
  `docker-compose.release.yml` names no image (`${APP_DOCKER_IMAGE}`).
  `overrides/docker-compose.yml` runs upstream's published
  `bonfirenetworks/bonfire:1.0.7-social` (current release, social flavour)
  with `postgis/postgis:17-3.5-alpine`, upstream's default database image.
- `hooks/prepare.sh` generates `SECRET_KEY_BASE`, `SIGNING_SALT`,
  `ENCRYPTION_SALT`, `RELEASE_COOKIE` and the database password once into
  `~/.panelalpha/bonfire/bonfire.env` (0600), read by both services through
  `env_file` (POSTGRES_* in `environment:` trips engine#419).
- `HOSTNAME` is the project domain, `PUBLIC_PORT=443`, port 4000.
- `DB_MIGRATE_INDEXES_CONCURRENTLY=false`, as upstream's `public.env`
  template sets it: without it the startup migrations fail with
  `CREATE INDEX CONCURRENTLY cannot run inside a transaction block`.
- `MAIL_BACKEND=none`; no search index (meili/sonic are optional profiles
  upstream), so search is unavailable.
- Uploads and the database are on named volumes.

Upstream's first-run behaviour is unchanged: the first sign-up becomes the
instance admin, after which the instance is invite-only.

Upgrading: change both `bonfirenetworks/bonfire` tags.
