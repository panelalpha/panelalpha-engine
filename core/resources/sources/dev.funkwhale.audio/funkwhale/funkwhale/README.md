# Funkwhale

Multi-user music/audio server: a Django/DRF API (gunicorn), a Celery worker and
beat, a compiled Vue frontend served by nginx, and PostgreSQL + Redis behind
them. Upstream tracker: `panelalpha/playground/supported-apps#1282`.

## Strategy: compose-REPLACE, official images

`overrides/docker-compose.yml` replaces the repository's own compose files (a
live-reload root `compose.yml` that builds `../api`, and a host-bind-mount
`deploy/docker-compose.yml`) with a self-contained stack that pulls upstream's
published `funkwhale/api` and `funkwhale/front` images, pinned to a release
(`FUNKWHALE_VERSION`, default `2.0.11`). No platform block, so detection is
`fromWalk` over the replaced file. Images are pulled, not built, because the
published image is what upstream's install docs deploy and a build would cost
the account a full yarn+poetry compile on every redeploy.

## What the recipe supplies

- **Secrets** (`hooks/prepare.sh` → `~/.panelalpha/funkwhale/funkwhale.env`,
  0600): `DJANGO_SECRET_KEY` and the PostgreSQL password, generated once and
  reused — a new secret key logs everyone out, a new DB password locks the app
  out of its volume (engine#173).
- **Public name**: `PA_PUBLIC_URL=http://localhost` in the compose is rewritten
  to the account's public https URL by `ComposePlaceholders`; `env.sh` splits it
  into `FUNKWHALE_HOSTNAME`/`FUNKWHALE_PROTOCOL` and sets `DJANGO_ALLOWED_HOSTS`.
- **Superuser**: `init.sh` creates one non-interactively with
  `funkwhale-manage fw users create --superuser` before the API serves,
  idempotent on re-run.
- **Persistence** (named volumes only): `pgdata`, `redisdata`, `media`
  (`MEDIA_ROOT=/data/media`, uploaded + transcoded audio, shared rw with the
  worker and ro with the front), `static`.
- **Front**: upstream's nginx image serves the SPA, proxies `/api/`, serves the
  whitelisted `/media/` subtrees and the `/_protected/media` internal redirect
  Django streams authorised tracks through (`REVERSE_PROXY_TYPE=nginx`).
- **Gates**: a one-shot `init` (migrate + collectstatic + superuser); a `ready`
  gate that holds `up -d` until the API answers through the front (engine#204).

## Files

| File | Purpose |
|---|---|
| `panelalpha.yaml` | Manifest (description only; compose auto-detected) |
| `hooks/prepare.sh` | Generates/stores secrets and the credentials note |
| `overrides/docker-compose.yml` | The replacement stack |
| `files/panelalpha/funkwhale/env.sh` | Derives hostname/protocol/allowed-hosts |
| `files/panelalpha/funkwhale/init.sh` | Migrate, collectstatic, seed superuser |
| `files/panelalpha/funkwhale/api.sh` | gunicorn entrypoint wrapper |
| `files/panelalpha/funkwhale/worker.sh` | Celery worker |
| `files/panelalpha/funkwhale/beat.sh` | Celery beat |
| `files/panelalpha/funkwhale/front.sh` | nginx entrypoint wrapper |

## Limitations

- **Registration/email**: registration closed and email verification off by
  default (no SMTP needed for the owner). Open public registration with email
  confirmation, and any outbound system mail, need an operator-supplied
  `EMAIL_CONFIG` in the account env_vars. Inbound mail is out of scope.
- **S3/object storage** and **in-place music import** are supported by the
  images but off by default; local disk on the `media` volume is used.
