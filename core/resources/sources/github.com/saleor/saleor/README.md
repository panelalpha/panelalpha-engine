# saleor/saleor

Saleor — a headless e-commerce backend. Django, PostgreSQL, Celery, and one
GraphQL endpoint.

Tracker: `panelalpha/playground/supported-apps#336`.
Verified against `main` at `4cb1609` (2026-09-17), which reports itself as
`3.24.0-a.0`.

## What the account owner actually gets

**The API, not a shop.** `saleor/urls.py` routes five things and, once `DEBUG`
is off, nothing else:

    /graphql/                  the whole API, and the GraphQL Playground on GET
    /plugins/…                 webhook receivers for installed plugins
    /thumbnail/<id>/<size>/    product images, by redirect into /media/
    /image/<id>/               the original of one
    /.well-known/jwks.json     the public half of this account's token key

There is no storefront and no admin UI in this repository — both are separate
products (`saleor/storefront`, `saleor/saleor-dashboard`) with their own
repositories and their own images. **Nothing in this deployment renders a
shop.**

What the owner *can* do without them is everything, through `/graphql/`. Saleor
has no HTML admin at all — even the Dashboard is only a GraphQL client — so the
Playground served at `/graphql/` is not a developer toy here, it is the
administration surface. It is a schema browser with a query editor, it takes an
`authorization: Bearer` header, and every operation the Dashboard performs is a
mutation it can send. Creating a channel, a category, a product type, a product,
a variant and a price was done that way against the public domain during this
verification, in about forty lines of JSON.

That is the honest summary: **an owner who reads GraphQL can run the whole shop
from the browser; an owner who wants to click "add product" needs to point a
separate Dashboard at `https://<domain>/graphql/`.** That is Saleor's design,
not a gap in this recipe.

The administrator's credentials and this explanation are written to
`~/.panelalpha/saleor/credentials.txt` on the first deploy.

## What goes wrong without this recipe

Detection reads the root `Dockerfile` and the `dockerfile` strategy builds it in
**135 s**. The build succeeds, the container starts, and the deploy is
`completed`. Measured on the control account:

    $ curl -o /dev/null -w '%{http_code} %{size_download}\n' https://<account>/
    400 182660

**400 on every path** — `/`, `/graphql/`, `/.well-known/jwks.json`, `/static/`,
`/media/` alike — because `settings.py:516` defaults `ALLOWED_HOSTS` to
`localhost,127.0.0.1` and the account answers on neither.

And 182 KB of body, because `settings.py:86` defaults **`DEBUG` to `True`**.
The 400 is Django's debug page. It publishes:

| | |
|---|---|
| the settings table | 406 rows: `INSTALLED_APPS`, `DATABASES`, every tunable |
| a traceback | with `Local vars` expanded |
| versions | `Django Version: 5.2.17`, the settings module name |

Django's own filter scrubs the values whose names look like secrets —
`SECRET_KEY` and `DATABASES['default']['PASSWORD']` both come back as
`'********************'` — so this is not a credential leak. It is still the
entire configuration of the account, served to anyone, on every URL.

The engine does **not** miss this one, and the reason is worth knowing: the
account's health reads `healthy: false, serving: error_page`, but from a
**500**, not the 400 a visitor gets. The probe asks `http://127.0.0.1:8000/`,
and `127.0.0.1` *is* in the default `ALLOWED_HOSTS`, so Django accepts the
request, routes `/` to the debug-only `views.home` (`urls.py:55`), and fails
against a database that is not there. Two different failures on two different
hostnames; the tracker's `serving-error_page` and its `HTTP 400` are both
right and are not the same response.

Two more, which only bite once `DEBUG` is off:

- `settings.py:258` reads `SECRET_KEY` from the environment with no default.
  With `DEBUG` on it silently becomes `get_random_secret_key()` **per process**,
  so the image's two uvicorn workers sign with different keys.
- `jwt_manager.py:79` raises `ImproperlyConfigured` without `RSA_PRIVATE_KEY`.
  That key signs every access token and is published as the account's JWKS.

So turning `DEBUG` off is not a one-line change. `settings.py:109` also raises
unless `ALLOWED_CLIENT_HOSTS` is set. All four values have to arrive together.

## What the recipe does

- **Builds the checkout** rather than pulling `ghcr.io/saleor/saleor`. Unusual,
  and right here: `.github/workflows/publish-containers.yml` hands *this*
  context and *this* `Dockerfile` to the shared build-and-push action, so the
  image CI publishes and the image built from the clone are the same thing.
  There is no CI-only step — no frontend to build, because there is no
  frontend. Building means a fork, a branch or a pinned commit gets its own
  code, which a tag could not give it.
- Derives `ALLOWED_HOSTS`, `ALLOWED_CLIENT_HOSTS` and Saleor's own `PUBLIC_URL`
  from `PA_PUBLIC_URL` at container start
  (`files/panelalpha/saleor/env.sh`), and turns `DEBUG` off.
- Generates `SECRET_KEY`, a 2048-bit RSA signing key, the PostgreSQL password
  and an administrator into `~/.panelalpha/saleor/`, once, and never again.
- Migrates and creates that administrator in a one-shot `init` that must exit 0
  before anything listens.
- Runs a Celery worker with beat, which is not optional (below).
- Puts nginx in front for `/static/` and `/media/`, which Saleor stops routing
  the moment `DEBUG` is off.
- Blocks the deploy until the API actually answers.

## PostgreSQL, Valkey, and why the worker is not optional

`database: mysql` is doubly impossible: it is inert outside `runtime: php`
(engine#210), and the image carries `psycopg` only. `postgres:15-alpine` and
`valkey/valkey:8.1-alpine` are what upstream's own
`.devcontainer/docker-compose.yml` runs.

The broker is the expensive decision, and it is load-bearing.
`settings.py:643` sets `CELERY_TASK_ALWAYS_EAGER` whenever `CELERY_BROKER_URL`
is empty, so Saleor *does* start with no broker and no worker — and looks fine.
What stops is `CELERY_BEAT_SCHEDULE` (`settings.py:673`), twenty-odd periodic
tasks that eager mode has nothing to do with, because eager mode only inlines
tasks a *request* dispatches. Two of them are not housekeeping:

| task | what only it does |
|---|---|
| `update-products-search-vectors` | writes a product's `search_vector` — without it, products are unsearchable |
| `recalculate-discounted-price-for-products` | applies a promotion to a price — without it, discounts never land |

So the broker, a worker and beat are part of the application. Beat runs *inside*
the worker (`celery worker --beat`): upstream separates them, which is correct
when there are several workers and two beats would double every scheduled task,
and there is exactly one worker in a hosting account. One process instead of two
saves a whole Django interpreter.

## Persistence

`~/.panelalpha/saleor/`, 0600 in a 0700 directory, delivered as the **second**
`env_file:` entry (compose appends, so it wins over `~/project/.env`). Not
`~/project/.env`: every deploy re-clones and `ProjectTree::clearContents()`
(`GitRepository.php:89`) empties `~/project` first (engine#173).

The RSA key is stored as a PEM **and** base64-encoded on one line, because
compose reads an env file a line at a time and a bind mount is not available
here (see the trap below). `env.sh` decodes it back.

Application data is four named volumes: `pgdata`, `media` (product images —
the only data not in the database), `cachedata` (the Celery queue) and
`staticfiles`.

Verified across a real `POST /projects/salrec/rebuild`, twice, plus one
deliberately failed rebuild in between:

| | |
|---|---|
| product, variant, SKU, price (19.99 EUR) | byte-identical GraphQL response |
| administrator password | still accepted |
| JWKS `kid` | `0dgPoJdAdfx0…` before and after — the signing key did not move |
| uploaded product image | same sha256, still served through `/thumbnail/…` |
| `SECRET_KEY`, `POSTGRES_PASSWORD`, `jwt-rsa.pem` | unchanged |

## The gate, and breaking it on purpose

`docker compose up -d` runs without `--wait` (engine#204). Both gates here are
`depends_on` conditions, which compose *does* block on:

    db,cache --(healthy)--> init --(completed successfully)--> api,worker
                                                    web --(healthy)--> ready

Tested by breaking the precondition — a deliberately wrong `POSTGRES_PASSWORD`
in the store, then a real rebuild:

    project-init-1   Exited (1)    "database db:5432 never accepted a connection"
    project-api-1    Created       (never started)
    project-worker-1 Created       (never started)
    project-web-1    Created       (never started)
    project-ready-1  Created       (never started)

    deploy log: service "init" didn't complete successfully: exit 1

The deploy **failed**, the site 502'd, and health read `healthy: false`. Putting
the password back and rebuilding recovered it in **75 s** with all data intact.

`ready` is `alpine:3` deliberately: a no-op gate must not be a second copy of a
datastore image.

## Traps this recipe hit, for whoever writes the next one

**A `build:` service must not bind-mount anything starting with `./`.**
`ComposeFileInspector::serviceBindsProjectRoot()` reads that shape as a
workstation live-reload mount, and one such service makes
`isLocalDevCompose()` true for the whole file. `ComposeUsableProbe` then skips
the file and detection falls through to the root `Dockerfile` — **silently**.
The first deploy of this recipe logged `Detected project type: Dockerfile`,
never mentioned the compose file it had just written, and came up as one `app`
container with `init`, `api` and `worker` reduced to network aliases on it, on
a database whose password the engine had substituted with the literal `app`.
Tandoor's recipe only escapes this because none of its services `build:`.

The fix is to mount nothing on the services that build. The scripts reach the
containers through the build context instead — the `Dockerfile`'s `COPY . /app`
picks up what `files/` wrote, and `.dockerignore` excludes `.*`, `media`,
`static` and `node_modules` but not `panelalpha/`. `web` has no `build:`, so it
keeps its `./panelalpha/saleor/nginx.conf` mount.

**nginx resolves a literal upstream once, at startup.** `proxy_pass
http://api:8000` made nginx exit with `host not found in upstream "api"` on the
first deploy, because `api` waits on the one-shot `init` and nginx does not —
three restart loops and a failed deploy. A `resolver` plus a variable defers
the lookup to the request.

**`location ~ /\.` denies `/.well-known/jwks.json`.** Measured: 403 on the
endpoint that publishes the token-verification key, while the API itself looked
perfectly healthy. The rule needs the `(?!well-known/)` lookahead.

## Exposure

Checked by request against the public domain, comparing bodies. Every
repository path is the same 179-byte Django 404 (identical sha256), every
dotfile and directory is nginx's 146-byte 403:

| path | result |
|---|---|
| `/.env`, `/.env.default`, `/.env.example` | 403, nginx, 146 B |
| `/.git/config`, `/.git/HEAD` | 403, nginx, 146 B |
| `/manage.py`, `/saleor/settings.py`, `/Dockerfile`, `/uv.lock`, `/docker-compose.yml` | 404 Django, 179 B |
| `/panelalpha/saleor/env.sh`, `/panelalpha/saleor/init.sh`, `/panelalpha-after-clone.sh` | 404 Django, 179 B |
| `/media/`, `/static/`, `/static/images/` | 403, nginx, no autoindex |
| `/admin/` | 404 — Saleor has no Django admin |
| `/static/images/logo-light.svg` | 200, 1228 B (static serving works) |
| `/.well-known/jwks.json` | 200, 497 B — public by design, the *public* key |
| `/` | 302 to `/graphql/` |
| anything else | 404, no debug page, no traceback |

`~/project` is not reachable at all: it is not a document root and nothing from
it is inside the nginx container except `nginx.conf`.

**Three things are public and are Saleor's design, not this recipe's:**

1. **Schema introspection is open.** `{__schema{types{name}}}` answers 200 with
   47 KB, unauthenticated. Saleor's schema is published upstream and every
   client is generated from it; this is not a disclosure.
2. **The Playground is open** (`PLAYGROUND_ENABLED`, `settings.py:514`, default
   on). It is left on because it is the only administration surface this
   deployment has. It reads and writes nothing without a token.
3. **`ALLOWED_GRAPHQL_ORIGINS` is `*`** (`settings.py:517`, upstream's
   default). Left alone: a headless commerce API exists to be called from a
   storefront on another origin, and `AUTHENTICATION_BACKENDS`
   (`settings.py:609`) is JWT-only with no session backend, so there are no
   ambient credentials for a permissive CORS policy to abuse.

Authentication was checked in both directions over public HTTPS:

    {me{email}}                    unauthenticated -> {"me": null}
    {customers(first:5){…}}        unauthenticated -> PermissionDenied
    mutation{channelCreate(…)}     unauthenticated -> PermissionDenied
    mutation{tokenCreate(…)}       wrong password  -> INVALID_CREDENTIALS
    mutation{tokenCreate(…)}       real password   -> RS256 JWT, iss = the public URL

**Saleor ships no default credential.** The only account hardcoded anywhere in
the tree is `populatedb.py:86`'s `admin@example.com`, and `populatedb` is a
sample-data command this recipe never runs. A migrated database has an empty
user table, so `init` creates the account's own superuser before anything
listens. `init` also deactivates any `admin@example.com` it finds — which costs
one query and covers a database restored from an upstream demo dump.

Migrations do create a `Default Channel` (USD), a default category and a
default product type. Those are rows, not credentials.

**Saleor phones home.** `SEND_USAGE_TELEMETRY` (`settings.py:1283`) defaults to
true and the API logs `Sending usage telemetry data` on boot with an instance
UUID and the version. Upstream's default, left alone; an operator who does not
want it sets `SEND_USAGE_TELEMETRY=False` in the account's `env_vars`.

## Memory, and whether it fits 2 GB

Measured on the test host with `memory_limit: 2048`, idle, five minutes after a
clean deploy:

| | |
|---|---|
| `worker` (celery + beat, concurrency 1) | 438 MiB of a 640 MiB cap |
| `api` (1 uvicorn worker) | 257 MiB of a 900 MiB cap |
| `db` (postgres:15-alpine) | 79 MiB of a 256 MiB cap |
| `web` (nginx:1.29-alpine) | 15 MiB of a 64 MiB cap |
| `cache` (valkey:8.1-alpine) | 13 MiB of a 96 MiB cap |
| **containers total** | **802 MiB** |

The account cgroup, which is the number that decides whether this fits:

    anon               861 MiB      <- the processes themselves
    file               740 MiB      <- page cache, reclaimable
    slab_reclaimable   386 MiB
    memory.current    2003 MiB  /  memory.max  2048 MiB
    memory.events      max 16154   oom 0   oom_kill 0

**It fits a 2 GB account, and it sits on the ceiling.** Read those two lines
together: only 861 MiB is anonymous — the part the kernel cannot take back —
so nothing is ever OOM-killed (`oom_kill 0` after a clean deploy, a rebuild,
a failed rebuild and a recovery). But `memory.current` is at 98 % of the cap
because page cache and slab fill whatever is left, and the cgroup has hit its
limit and reclaimed **16154 times**. That is not a failure, it is a cache
working set larger than the account: Saleor's image layers and Postgres's
files do not all stay resident. The cost is I/O, not correctness.

Two things drive the anonymous figure. The image's `CMD` is `--workers=2`;
each uvicorn worker is a full Django process with Saleor's dependency tree
resident, so `api.sh` runs one and exposes `SALEOR_WEB_WORKERS` for an
operator with room. And the worker costs *more* than the API (438 MiB against
257) because Celery imports every task module in the project at startup.

**A 1 GB account would not hold this** — 861 MiB of anonymous memory leaves
nothing for the daemon supervising it. 2 GB is the floor, and 3 GB is what
this would want to stop thrashing its cache.

(An earlier reading of 1377 MiB `memory.current` was taken a minute after a
rebuild, before page cache had refilled. The 2003 MiB figure is the steady
state and is the one to plan against.)

## Timings, measured

| | |
|---|---|
| control deploy, no recipe (builds the Dockerfile) | **135 s**, `completed`, then **400 on every path** |
| first deploy with the recipe | **485 s**, `deploy-ok`, `healthy: true`, `serving: ok` |
| `POST /projects/<user>/rebuild` (build cached) | **75–100 s** |

The first deploy is slow and it is not the build: `init` spent **4 m 30 s** in
`manage.py migrate` on an empty database. Saleor's migration history is long and
it is paid once — every rebuild afterwards is a no-op migration.

## Known limitation, and it is the engine's

**GraphQL file upload does not work through `*.panelalpha.online`.** Measured,
the same `productMediaCreate` mutation with the same image:

    inside the container, api:8000     0.1 s, 200, media created
    through the public HTTPS domain    60.1 s stall, then 403

This is engine#170 — `POST` + `multipart/form-data` always stalls 60 s through
the tunnel domain — and it is not specific to Saleor. It matters more here than
elsewhere, because uploading a product image is the GraphQL multipart request,
and it is the one thing an owner cannot do from the Playground on a tunnel
domain. Everything else — creating the product, the variant, the price, and
reading them all back — works over the public domain. The image used in the
persistence check above was uploaded from inside the container for this reason,
and is served back over the public domain perfectly well.
