# gramps-project/gramps-web-api

Gramps Web, a collaborative genealogy application. This repository is the
Flask/gunicorn REST API that also serves the compiled `gramps-web` frontend.

## Why a recipe

- This repo's own `Dockerfile` builds only the API on top of
  `gramps-web-base` and ships an **empty** `/app/static/index.html` -- no web
  UI. The project publishes an all-in-one image,
  `ghcr.io/gramps-project/grampsweb`, with the frontend and full Gramps runtime
  baked in. The recipe runs that image.
- Left to generic detection the checkout looks like a Python/Django app; the
  `[ai,sentry]` extras (torch, sentence-transformers, a model download) install
  into a base with no Gramps runtime and nothing listens on 5000.

## Strategy: compose-REPLACE (fromWalk)

`overrides/docker-compose.yml` is the upstream docs compose for one account:

- `grampsweb` -- the published image, gunicorn on 5000, the frontend at `/`.
- `grampsweb_celery` -- same image, `celery -A gramps_webapi.celery worker`.
- `grampsweb_redis` -- broker/result backend, no volume (queue is not state).
- `grampsweb_init` -- one-shot owner seed (see below), `service_completed_successfully`.
- `ready` -- gate so `up -d` returns only once the site answers.

No platform block, so the compose probe walks this file and
`ComposePlaceholders` rewrites `GRAMPSWEB_BASE_URL` (`http://localhost/`) to the
account's public https URL.

## Secrets and the owner

`hooks/prepare.sh` writes, once, into `~/.panelalpha/gramps/gramps.env` (0600),
never regenerated:

- `GRAMPSWEB_SECRET_KEY` -- Flask/JWT signing key.
- `GRAMPSWEB_OWNER_USER` / `GRAMPSWEB_OWNER_PASSWORD` -- the owner account.

Gramps Web has no owner until one is created and `GRAMPSWEB_REGISTRATION_DISABLED`
turns off open sign-up. `files/panelalpha/gramps/init.sh` runs
`gramps_webapi user add ... --role 5` **only when the user DB is empty**, so a
redeploy never resets the owner. The password is written to
`~/.panelalpha/gramps/credentials.txt`.

## Persistence

All state is on named volumes, which survive the `~/project` wipe on redeploy:
the family-tree DB (`/root/.gramps/grampsdb`), users (`/app/users`), media
(`/app/media`) and the search index (`/app/indexdir`), plus caches.

## Auth

- `POST /api/token/` with `{"username","password"}` -> access token.
- Every tree endpoint (e.g. `GET /api/people/`) needs the bearer token;
  anonymous requests are rejected.
