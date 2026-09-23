# TandoorRecipes/recipes

Tandoor Recipes — a Django recipe manager with meal planning, shopping lists
and ingredient scaling, and a Vue 3 single-page frontend.

Tracker: `panelalpha/playground/supported-apps#442`.

## What the repository is, and what goes wrong without this recipe

The repository is the source tree. Detection reads the root `Dockerfile` and
the `dockerfile` strategy builds it, in about 150 s. That build **succeeds**,
and what it produces is not Tandoor.

**1. There is no frontend in a locally built image.** The `Dockerfile` copies
the source in and installs `requirements.txt`; it never touches `vue3/`. The
frontend is built in CI — `.github/workflows/build-docker.yml` runs
`yarn install --frozen-lockfile` and `yarn build` in `vue3/` as a separate step
*before* `docker build` — so only the published image carries
`cookbook/static/vue3/`. Measured on the test host:

    published image:  /opt/recipes/cookbook/static/vue3/{manifest.json, assets/, service-worker.js}
    locally built:    ls: /opt/recipes/cookbook/static/vue3/: No such file or directory

and the container logs, on every boot:

    (django_vite.W001) Cannot read Vite manifest file for app default at
    /opt/recipes/cookbook/static/vue3/manifest.json

**2. Nothing answers.** `recipes/settings.py:125` reads `ALLOWED_HOSTS` from the
environment with an empty default and `DEBUG` is `0`, so Django refuses every
request:

    $ curl -o /dev/null -w '%{http_code}' https://<account>.panelalpha.online/
    400
    django.security.DisallowedHost Invalid HTTP_HOST header: '<account>.panelalpha.online'

every path, not only `/`. The engine's own probe reports `serving: "ok",
healthy: true` for that account, because 400 is neither a 5xx nor a placeholder
page — so the failure is invisible from the API.

**3. `SECRET_KEY` has a published default.** `settings.py:49` falls back to the
literal `INSECURE_STANDARD_KEY_SET_IN_ENV`, which ships in every copy of the
repository and signs every session cookie and password-reset token.

**4. `/setup/` is an anonymous superuser form.** `cookbook/views/views.py:349`
renders a username/password form that makes its submitter `is_superuser=True`,
guarded by nothing but `User.objects.count() > 0`. With a working
`ALLOWED_HOSTS`, `GET /` on a fresh deploy is a 302 straight to it. The control
deploy only hides it behind the 400.

## What the recipe does

- Runs `ghcr.io/tandoorrecipes/recipes`, the image upstream publishes. `latest`
  by default — `docs/install/docker.md` calls it "the one you should use if you
  don't know that you need anything else" and warns, in a danger box, that the
  database **cannot be migrated back down**. A checkout parked on a release tag
  gets that release instead, if ghcr.io has it. Branch images are never chosen
  implicitly: the repository's default branch is `develop`, which is exactly
  the tag upstream marks "not recommended". `TANDOOR_IMAGE` in the account's
  env_vars overrides all of it.
- Derives `ALLOWED_HOSTS` and `CSRF_TRUSTED_ORIGINS` from `PA_PUBLIC_URL` at
  container start (`files/panelalpha/tandoor/entrypoint.sh`), which then execs
  the image's own `tini`/`boot.sh` unchanged.
- Generates `SECRET_KEY`, the PostgreSQL password and an administrator into
  `~/.panelalpha/tandoor/`, once, and never again.
- Creates that administrator in a one-shot `init` service that runs to
  completion **before** the web container is allowed to start, which closes
  `/setup/` before anything is listening.
- Blocks the deploy until the site actually answers.

## PostgreSQL, not MySQL, and not SQLite

`database: mysql` cannot work. The published image has no MySQL driver —
`requirements.txt` ships `psycopg2-binary` and nothing else — and
`settings.py:511` raises `Unsupported database schema` for any `DATABASE_URL`
whose scheme is not `postgres*` or `sqlite`.

SQLite does work. Measured in the published image: all 245 `cookbook`
migrations apply cleanly against a fresh SQLite file, including the `GinIndex`
ones (`django.contrib.postgres.operations.CreateExtension` is a no-op off
PostgreSQL). It costs Tandoor's full-text and trigram search —
`cookbook/helper/recipe_search.py:199` falls back to `icontains`/`istartswith`
— and the app puts a "not recommended" banner in its own system page
(`cookbook/views/views.py:193`). `postgres:16-alpine`, the version upstream's
own `docs/install/docker/plain/docker-compose.yml` pins, measured at 70 MiB
resident, is cheaper than losing the app's headline feature.

Note that `DATABASE_URL=sqlite:///...` does not work either, whatever the
documentation implies: the regex at `settings.py:503` requires a non-empty
host segment, so the URL form raises `AttributeError: 'NoneType' object has no
attribute 'groupdict'`. Only `DB_ENGINE` + `POSTGRES_DB` selects SQLite.

## Persistence

`~/.panelalpha/tandoor/tandoor.env`, 0600 in a 0700 directory, delivered to both
Tandoor services as the **second** `env_file:` entry. Not `~/project/.env`:
every deploy re-clones and `ProjectTree::clearContents()`
(`GitRepository.php:89`) empties `~/project` first, so a secret written there is
regenerated on every rebuild — a fresh `SECRET_KEY` logs everyone out and a
fresh `POSTGRES_PASSWORD` locks the app out of a `pgdata` volume that still
holds the old one. `ProjectEnvironment::apply()` also republishes
`~/project/.env` as `.env.default` at mode 644.

Application data lives in three named volumes: `pgdata`, `media` (user uploads
— recipe photos and imported images, the only data not in the database) and
`staticfiles` (regenerated by `collectstatic --clear` on every boot).

Verified across a real `POST /projects/<user>/rebuild`: recipes, meal plan and
shopping-list entries intact, the uploaded image byte-identical at the same URL
(md5 match), the pre-rebuild **session cookie still valid** (so `SECRET_KEY` did
not move), and the original administrator password still accepted.

## The gate, and why it is a chain

`docker compose up -d` runs without `--wait` (engine#204), so a one-shot
service that exits 1 would otherwise leave the deploy green. Both gates here
are explicit `depends_on` conditions, which compose *does* block on:

    db --(service_healthy)--> init --(service_completed_successfully)--> app --(service_healthy)--> ready

Tested by breaking the precondition: with a deliberately wrong
`POSTGRES_PASSWORD` in the store, `init` exited 1, `app` and `ready` stayed in
`Created` and were never started, and the rebuild came back **failed** with
`service "init" didn't complete successfully: exit 1` and the real
`django.db.utils.OperationalError: ... password authentication failed` in the
deploy log. The site 502'd rather than serving an open `/setup/`.

`ready` is `alpine:3` deliberately: a no-op gate must not be a second copy of a
datastore image.

## Exposure

Nothing in `~/project` is reachable. The account's proxy talks to port 8080
inside the container and `~/project` is not a docroot; only
`./panelalpha/tandoor` is mounted into the container, read-only. Checked by
request against the public domain, comparing bodies:

| path | result |
|---|---|
| `/.env`, `/.env.default`, `/.git/config`, `/.git/HEAD` | 302 → `/accounts/login/?next=…` (zero-byte body) |
| `/panelalpha/tandoor/init.sh`, `/panelalpha-after-clone.sh` | 302 → login |
| `/manage.py`, `/recipes/settings.py`, `/db.sqlite3` | 302 → login |
| `/media/`, `/media/recipes/` | 403, nginx, no autoindex |
| `/static/`, `/staticfiles/` | 302 → login |
| `/admin/` | 302 → login |
| `/api/` | 403 `{"detail":"Authentication credentials were not provided."}` |
| `/setup/` | 302 → login, once the administrator exists |

**One thing is public and is upstream's design, not this recipe's:** an
individual file under `/media/…` is served by the container's nginx with no
authentication at all (`http.d/Recipes.conf.template`, `location /media`), so
anyone holding a recipe image's URL can fetch it — measured, 200 with no
cookies. The filenames contain a UUID, so this is unguessable rather than
listable, and `/media/` itself is 403. An operator who needs recipe photos to
be private cannot get that from Tandoor.

There is no sign-up page: `ENABLE_SIGNUP` defaults to false
(`settings.py:281`). Further users are invited from the app's own space
settings.

## Memory, and what does not run

Measured on the test host with one recipe and an image, idle:

| | |
|---|---|
| `app` (2 gunicorn workers × 2 threads + nginx) | 583 MiB of a 1024 MiB cap |
| `db` (postgres:16-alpine) | 70 MiB of a 256 MiB cap |
| account cgroup, anonymous | 665 MiB (plus ~1.4 GiB reclaimable page cache from the image layers) |

A single gunicorn worker is ~312 MiB resident: Django plus `litellm`, `boto3`,
`lxml` and `python3-saml`. The image's default of 3 workers × 2 threads would
not fit the cap, which is why the compose file sets 2 × 2. A 2200×2200 PNG
upload was processed in 2.2 s with no measurable growth.

**There is no queue and no scheduler.** Tandoor ships no Celery, no django-q
and no cron entries; everything happens inside the request. `REDIS_HOST` is
optional and only switches the Django cache from `LocMemCache`, so no broker
runs here.

## Timings, measured

| | |
|---|---|
| control deploy, no recipe (builds the Dockerfile) | 164 s, `deploy-ok`, then **400 on every path** |
| first deploy with the recipe (pulls a 1.4 GB image) | 173 s, `deploy-ok`, `serving: ok` |
| `POST /projects/<user>/rebuild` (image cached) | 77–87 s |

`/` is a 302 to `/accounts/login/`, which is 200. A logged-in `/` is 200 and
11.7 KB of SPA shell; `/static/vue3/assets/main-*.js` is 600 KB and 200.
