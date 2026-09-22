# Paperless-ngx (github.com/paperless-ngx/paperless-ngx)

Document management system. A Django app that serves the UI and API on :8000,
a Celery worker that OCRs and indexes what is fed to it, Redis as the broker,
and SQLite or PostgreSQL underneath.

Detection: `django` — and that is the failure. The repository *is* a Django
project, so the probe is right about the tree and wrong about the application.
The django strategy resolved `uv.lock` into a plain `python:3.11-slim`: about
250 wheels, dev extras and all (pytest, ruff, torch), and none of what makes
paperless run. The Angular frontend in `src-ui/` is never built, the OCR
toolchain the parsers shell out to — tesseract, ghostscript, unpaper, qpdf,
jbig2enc, ImageMagick — is not installed, and the s6 supervision tree that runs
gunicorn beside the celery worker, the beat scheduler and the consumer lives in
the image, not in the source. The container died on a Django management command
a bare checkout does not have, restart-looped, and nothing listened on 8000:
`serving-unknown`.

Building the repository's own `Dockerfile` instead is not worth it: a
multi-stage pnpm frontend build plus that whole toolchain, minutes of build and
gigabytes of layers *per account*, to arrive at an image the project publishes
on every release. So the recipe runs the published one.

`overrides/docker-compose.yml` is `docker/compose/docker-compose.sqlite.yml`
rewritten for a single hosting account. Upstream's compose files are all under
`docker/compose/` with names Docker never auto-loads (`docker-compose.sqlite.yml`,
`docker-compose.postgres-tika.yml`, …) and their own headers tell the operator
to copy one out, so the engine's compose probe never sees them and there is
nothing to stash.

- **webserver** — `ghcr.io/paperless-ngx/paperless-ngx:<tag>`, the tag resolved
  by `hooks/prepare.sh`. Data, media, export and consume on named volumes.
- **broker** — `valkey:9-alpine`, the image upstream's own file names. Not
  optional: `src/paperless/checks.py` fails the Django startup system check
  when Redis is unreachable, and every OCR task goes through it.
- **ready** — a no-op that exits 0. See *Readiness*.

**SQLite, not PostgreSQL.** Unset `PAPERLESS_DBHOST` already means SQLite;
`PAPERLESS_DBENGINE: sqlite` just says so out loud. One account's document
archive is not what a database server is for, and the ~300 MB a Postgres
sidecar wants is memory the OCR worker wants more.

**No Tika or Gotenberg.** They add Office-document parsing and nothing else —
two more containers and about a gigabyte. Inside a 2.5 GB account a stack that
serves the web UI beats one that tries to bring up everything and fails. Add
them by pointing `PAPERLESS_TIKA_ENABLED`, `PAPERLESS_TIKA_ENDPOINT` and
`PAPERLESS_TIKA_GOTENBERG_ENDPOINT` at services of your own.

## The image tag

`hooks/prepare.sh` reads `src/paperless/version.py` for the checkout's own
`major.minor`, so a clone of `v2.14.7` gets a 2.14 image rather than whatever
`latest` is today. Two details make that less obvious than Ghost's equivalent:

- paperless-ngx publishes `X.Y.Z`, `X.Y` and `latest`, but **no major-only
  tag** — `ghcr.io/…:3` is a 404.
- the default branch is `dev`, which carries the *next* version. At the time of
  writing `version.py` says `3.2.0` on a branch whose newest published tag is
  `3.2` only by luck; on the day after a version bump the derived tag does not
  exist yet.

So the hook asks the registry (an anonymous ghcr token and a manifest HEAD) and
only uses the derived tag when it resolves; otherwise `latest`. The tag line in
`.env` is rewritten on every deploy, so a redeploy after an upstream release
actually moves.

## Secrets

Written by `hooks/prepare.sh`, **appended** to the `.env` the repository itself
ships (`COMPOSE_PROJECT_NAME=paperless`), each one only if absent:

- `PAPERLESS_SECRET_KEY` — Django's signing key.
- `PAPERLESS_ADMIN_USER` / `PAPERLESS_ADMIN_PASSWORD` — paperless has **no
  sign-up page**. Without these the site comes up on a login form nobody holds
  an account for. `init-superuser` runs `manage_superuser` on first boot and
  refuses to touch a user that already exists, which is why the hook never
  regenerates them: the data volume outlives the checkout, and a new password
  in `.env` would be one the database never learns. The credentials are in
  `~/project/.env`.

## `PAPERLESS_URL`

The value the recipe cannot generate — the account's public address, not known
until the domain exists. The compose file ships `PAPERLESS_URL: http://localhost`,
exactly the shape `ComposePlaceholders::isLocalPublicUrl()` rewrites (key
matches `/(^|_)(URL|ORIGIN|ENDPOINT|DOMAIN)$/i`, value is a bare localhost on an
allowlisted port), and `UserComposeStrategy` replaces it with the account's
`https://…` URL before the stack starts.

It is not what makes the site answer: `ALLOWED_HOSTS` defaults to `["*"]`
(`settings/__init__.py`), so the loopback probe gets its 200 either way. It is
what makes the site *usable* — `_parse_paperless_url()` appends it to
`CSRF_TRUSTED_ORIGINS`, and without that every form POST, the login included,
is rejected with a CSRF failure.

## Readiness

`AppLauncher` runs `docker compose up -d` without `--wait` and calls the deploy
finished when that returns, which is when the containers have been *started*.
Paperless's first boot then runs Django migrations against an empty SQLite file,
builds the whoosh index and creates the superuser. Compose does honour
`depends_on: condition: service_healthy` during startup, so `ready` — the
broker's image, `entrypoint: exit 0`, `restart: "no"` — waits on the webserver's
healthcheck and makes `up -d` block until the site answers. A clean exit 0 is
explicitly not a crash loop to `AppHealth::isCrashing()`.

The healthcheck is the image's own `curl` probe restated in the compose file:
the baked-in one is `--interval=30s --retries=5` with no `start_period`, so a
first boot that takes longer than 150 s would be declared *unhealthy* and fail
the whole `up`. Here: 10 s interval, 30 retries, `start_period: 300s`.

## Memory

`mem_limit` is set per service because an override reaches Docker as written and
`ServiceHardener`'s defaults (512 m, 384 m for an app-role service) are far
below what a six-process s6 tree with an OCR worker needs. 1536 m webserver,
128 m broker, 64 m ready — 1728 m inside a 2500 m account. The worker counts are
pinned to one (`PAPERLESS_WEBSERVER_WORKERS`, `PAPERLESS_TASK_WORKERS`,
`PAPERLESS_THREADS_PER_WORKER`); the defaults size themselves off the host's
core count, which is the whole machine rather than the account's slice.

## Not configured

Mail. `paperless_mail` can pull documents out of an IMAP mailbox and paperless
can send notifications, both of which need a mail server the engine does not
provide. Set `PAPERLESS_EMAIL_HOST` and friends through the account's env vars.
