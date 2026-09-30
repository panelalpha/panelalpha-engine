# Tuwunel (github.com/matrix-construct/tuwunel)

Matrix homeserver in Rust (successor to conduwuit), embedded RocksDB.

## Deploying

Nothing is required. The server name is the site's domain, fixed on first
start (Matrix ids carry it). Registration is closed as upstream ships it; to
open it set `TUWUNEL_ALLOW_REGISTRATION=true` and `TUWUNEL_REGISTRATION_TOKEN`
as project env vars. Any other `TUWUNEL_*` setting works the same way.

## What the recipe does

- `overrides/docker-compose.yml` runs the release image
  `ghcr.io/matrix-construct/tuwunel:v1.9.3` instead of the engine's Rust source
  build (fails on the jevmalloc-sys C dependency).
- Listens on 0.0.0.0:8008; database on the `tuwunel_db` volume.
- `.well-known/matrix/server` points federation at `<domain>:443`, since the
  engine only proxies 443.
- `stop_grace_period: 30m` as upstream: a post-upgrade migration must not be
  killed. A `ready` gate waits for the image's `tuwunel --health-check`.
