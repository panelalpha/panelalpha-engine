# milesmcc/shynet

Privacy-friendly web analytics. Django 4.1 + gunicorn, PostgreSQL, a dashboard
behind a login, and an ingress that records hits from a `<script>` tag or a
`<noscript>` pixel. No cookies on the tracked site; visitors are hashed.

## What the repository does on its own, and why it is a 502

`docker-compose.yml` is at the repository root, so detection reads the project
as `compose` and deploys that file. It cannot work unedited:

```yaml
  db:
    image: postgres
    environment:
      - "POSTGRES_USER=${DB_USER}"
      - "POSTGRES_PASSWORD=${DB_PASSWORD}"
      - "POSTGRES_DB=${DB_NAME}"
```

`TEMPLATE.env` is the file the operator is told to copy to `.env` and fill in,
and nobody does that here. The engine creates `.env` empty because the compose
file names one in `env_file:` — the deploy log says so in as many words,
`Created empty .env (required by compose env_file)` — so all three variables
resolve to the empty string. `postgres` then refuses to initialise:

```
You must specify POSTGRES_PASSWORD to a non-empty value for the superuser.
```

and restarts forever. Shynet cannot reach a database that never came up and
restarts forever too. The third service, a bundled `nginx` whose conf is
hardcoded to `server_name example.com`, stays up and answers 502 to
everything, which is what the port probe and the domain probe both see.

Measured on a control deploy of the unmodified repository: **78 s to
`Deploy finished successfully`, then 502 continuously**, with
`shynet_database` in `Restarting (1)` and `shynet_main` under a second old on
every look. **This is not a boot race.** The steady state is 502, not a
window. For comparison, the same stack under this recipe, with the `ready`
gate removed, answers 2.0 s after `docker compose up -d` returns — the window
exists and is two seconds wide, which could never produce this verdict.

`ComposePlaceholders` does not rescue it. It fills `${VAR:?message}`, Compose's
fail-closed form (`REQUIRED_VAR_PATTERN`, `ComposePlaceholders.php:87`); a bare
`${VAR}` is left as the empty string it interpolates to.

## The image: `edge`, not `latest`, and that is a security decision

The repository's own compose says `image: milesmcc/shynet:latest`. On Docker
Hub `latest` is **v0.13.1, pushed 2023-07-28**. The repository's master is
**v0.14.0 (2026-03-15)**, and there is no `v0.14.0` tag on Docker Hub at all.

The difference includes this:

```python
# v0.13.1
ALLOWED_HOSTS = os.getenv("ALLOWED_HOSTS", "*").split(",")

# v0.14.0  (shynet/settings.py:41-43)
# Do not default to "*": it disables Host header validation, allowing password
# reset poisoning (attacker-controlled reset URLs sent to users).
ALLOWED_HOSTS = (os.getenv("ALLOWED_HOSTS") or "localhost,127.0.0.1").split(",")
```

`edge` is what `.github/workflows/build-docker-edge.yml` pushes on every commit
to master, and its `org.opencontainers.image.revision` label is
`ca35caba3af2b888acc990b99152c500a1c44461` — the exact commit a plain
`git clone` of this repository checks out. Verified with `docker image
inspect`. So the recipe runs the code the customer cloned, and `latest` would
mean quietly deploying a two-and-a-half-year-old build with Host validation
turned off.

Not built from the checkout: the Dockerfile is `FROM python:alpine3.14`,
installs npm and poetry 1.2.2 and pins `Cython<3.0` and `pyyaml==5.4.1` to get
a build out of an Alpine that left support in 2023. The published image is
upstream's own answer to that, and it is what upstream's compose file uses.

`SHYNET_IMAGE` in the account's env vars overrides the tag.

## What the recipe supplies

Four things nothing else can.

1. **Database credentials that exist**, in `~/.panelalpha/shynet/`.
2. **`ALLOWED_HOSTS` and `CSRF_TRUSTED_ORIGINS`**, derived at container start
   from `PA_PUBLIC_URL`. Without them v0.14.0 answers **400 DisallowedHost on
   every path**, and nothing in the engine notices. Two reasons, both of them
   in the tree:

   - `resources/checks/_baseline/no-server-error.yaml` is `status_not:
     ['5xx']`, and says in its own comment that 4xx stays healthy on purpose.
   - `CheckRegistry::for()` (`CheckRegistry.php:78-86`) selects `_baseline`
     plus the group named for the runtime. There is a `command/` group and no
     `compose/` group, so `resources/checks/command/django-allowed-hosts.yaml`
     — the check written for exactly this failure — cannot run for a Django
     app deployed as `compose`, which is how a Django app that ships a compose
     file is always deployed.

   Measured, not inferred: rebuilding a working deploy with
   `env_vars: {"ALLOWED_HOSTS": "127.0.0.1,localhost"}` makes the public
   domain answer `400 Bad Request (400)` on `/` while the engine reports
   `healthy: true`, `serving: "ok"`, no failed checks, and
   `domain: {"verdict": "ok", "http_code": 400}`. Seven checks ran, all of
   them `_baseline`.
3. **A `DJANGO_SECRET_KEY`.** `settings.py:36` otherwise falls back to the
   literal `onlyusethisindev`, which ships in every copy of the repository and
   would sign every session cookie and password-reset token.
4. **An administrator.** Shynet has no first-run setup form and no sign-up
   page by default, so an instance with no user is an instance nobody can log
   into. Created by the `init` gate before the web container starts; the
   password is written to `~/.panelalpha/shynet/credentials.txt`.

## Shape of the stack

`db` → `init` → `app` → `ready`, and the arrows are `depends_on` conditions.

- `db` — `postgres:16-alpine`, named volume, `pg_isready` healthcheck, 256 MB.
- `init` — one-shot. Waits for postgres through psycopg2 (the image has
  `postgresql-libs`, not the client, so there is no `pg_isready` in it), runs
  `migrate`, creates the administrator if the user table is empty, points the
  `django.contrib.sites` row at the account domain. Exits non-zero on any of
  those failing.
- `app` — gunicorn on 8080, `service_completed_successfully` on `init`, health
  checked against `/healthz/?format=json` (django-health-check: 200 only when
  Django booted, postgres answered a write and the cache answered), 768 MB.
- `ready` — `alpine:3`, `exit 0`, waits for `app`'s healthcheck. The engine
  runs `docker compose up -d` with no `--wait` (`AppLauncher.php:45`), and
  Compose *does* block on a `depends_on` health condition, so this is what
  makes the deploy finish when the site answers rather than when containers
  have been started.

**The chain fails the deploy, and that was tested by breaking it.** Blanking
`POSTGRES_PASSWORD` in `db.env` and rebuilding gives
`status: failed`, `dependency failed to start: container project-db-1` — the
same broken precondition the unmodified repository has, reported as a failure
instead of as a success in front of a 502.

## Queue and scheduler: there are none

`settings.py:274` defaults `CELERY_TASK_ALWAYS_EAGER` to `True`, so
`ingress_request.delay(...)` (`analytics/views/ingress.py:35`) runs inside the
request that collected the hit. `celeryworker.sh` exists but is for a
deployment that sets `CELERY_BROKER_URL`; there is no beat schedule anywhere
in the tree. Redis is optional in the same way — `settings.py:233` only builds
a Redis cache when `REDIS_CACHE_LOCATION` is set. So this is two long-running
containers, not the five the tracker row's "PostgreSQL + Redis" implies.

Because the beacon is handled inline, `NUM_WORKERS` matters: the image's
default of 1 sync gunicorn worker serves one request at a time, and a tracked
page's 5-second heartbeats would queue in front of the dashboard. The recipe
defaults it to 2.

## Where things live

| Path | What |
|---|---|
| `~/.panelalpha/shynet/db.env` | `POSTGRES_PASSWORD`, 0600. Read by the database container and nothing else |
| `~/.panelalpha/shynet/app.env` | `DJANGO_SECRET_KEY`, `DB_PASSWORD`, administrator email and password, 0600 |
| `~/.panelalpha/shynet/credentials.txt` | the administrator login, for the account owner |
| `~/project/.env` | the tunable settings, 0644, merged over by the account's env vars |
| `project_pgdata` volume | every session and hit ever recorded |

Not `~/project/.env` for the secrets: every deploy re-clones and
`ProjectTree::clearContents()` (`GitRepository.php:89`) empties `~/project`
first, so a new key would be generated on every rebuild — logging everyone out
— and a new database password would lock the app out of the volume that still
holds the old one. `ProjectEnvironment::apply()` also republishes `~/project/.env`
as `.env.default` at mode 644.

## Settings the recipe chooses, and why

Written to `~/project/.env` by `hooks/prepare.sh`, so the panel can override
any of them:

| Key | Value | Reason |
|---|---|---|
| `TIME_ZONE` | `UTC` | the image ships `America/New_York`; it buckets every date in the dashboard |
| `ACCOUNT_SIGNUPS_ENABLED` | `False` | upstream's default. There is no invitation flow and no approval step; on a public address the first stranger to fill the form gets an account, and a registered user can see every other registered user (`settings.py:338-340`) |
| `ACCOUNT_EMAIL_VERIFICATION` | `none` | only meaningful with sign-ups on, and there is no SMTP: `settings.py:297` falls back to the console backend, so a verification mail goes to the container log |
| `NUM_WORKERS` | `2` | see above |
| `AGGRESSIVE_HASH_SALTING` | `True` | `settings.py:365` defaults it off; upstream's own `TEMPLATE.env` ships it on. With it on the visitor hash includes the date and the service id, so a visitor cannot be correlated across two services or across a day. The cost is that a session cannot span midnight |
| `SHOW_SHYNET_VERSION` | `False` | the footer otherwise names the exact build to anyone who opens the login page |

`SCRIPT_USE_HTTPS` is not written here: `overrides/entrypoint.sh` derives it
from the scheme of `PA_PUBLIC_URL`, because it is what the tracking snippet's
protocol is built from (`dashboard/views.py:68`) and an http-only account would
otherwise be handed an https URL that does not answer.

## Exposure

Checked over the public domain on a running account:

| Path | Answer |
|---|---|
| `/`, `/dashboard/`, `/dashboard/service/<uuid>/`, `.../sessions/` | 302 to `/accounts/login/`. No analytics data without a session |
| `/accounts/signup/` | 200 **"Sign Up Closed — Public sign-ups are not allowed at this time."** `dashboard/apps.py:9` replaces allauth's `is_open_for_signup` with one that always refuses |
| `/api/v1/dashboard/` | 403 without a token |
| `/.env`, `/.env.default`, `/.git/config`, `/docker-compose.yml`, `/TEMPLATE.env`, `/static/` | 404. Nothing from `~/project` is served; the app container serves Django and whitenoise only |
| `/ingress/<uuid>/script.js`, `/ingress/<uuid>/pixel.gif` | 200, by design — this is the tracker, and a service's origins list is what restricts it |
| `/healthz/`, `/healthz/?format=json` | 200 unauthenticated, `{"Cache backend: default": "working", "DatabaseBackend": "working"}`. Upstream's own endpoint and the image's own `HEALTHCHECK` uses it; it names subsystems but no data |
| `/admin/login/` | 200. Django's admin is mounted unconditionally at `shynet/urls.py:22`. It is a second login surface, by username, and the administrator's username is a uuid |

One finding that is not a URL. **With no SMTP configured, a password-reset
mail is printed to the app container's log, reset link included.**
`settings.py:297` falls back to Django's console backend whenever `EMAIL_HOST`
is unset, which is the default. Measured: posting the reset form for
`admin@localhost` put

```
http://<domain>/accounts/password/reset/key/1-df82k7-080f7133a43ee8e1c021559dbcaf7e3d/
```

into `docker logs` for the app. Anyone who can read that log can take the
administrator account over without the password. It is upstream's behaviour and
the fix is to configure SMTP; `hooks/prepare.sh` says so in
`credentials.txt`, and this is the reason the recipe does not advertise
password reset as working. (The link is `http://` because Shynet sets no
`SECURE_PROXY_SSL_HEADER` and has no setting to, so `request.is_secure()` is
false behind the proxy.)

The dashboard holds visitor IP-derived data — ASN, country, city, user agent —
so the 302s above are the line that matters, and they hold.

## Verified

On `mariusz.panelalpha.tools`, over the real public HTTPS domain:

- Deploy **63 s** with the image already on the host cache, **143 s** on a
  cold account that had to pull it (93 s of that was account preparation on a
  busy host; the `running` stage, image pull included, was 46 s). Both
  `serving: ok`, `healthy: true`, HTTP 200 — `/` 302s to `/dashboard/`, which
  302s to `/accounts/login/`, which is 200 and renders. The control (same
  repository, no recipe) was 78 s, `serving: error_page`, HTTP 502.
- Rebuilding the *control* account, which had been serving 502, with the
  recipe in place: 49 s and `serving: ok`.
- Logged in as the generated administrator; created a Service; took its
  snippet; served it on a second PanelAlpha account and loaded four pages in a
  real browser.
- The dashboard then showed **4 sessions, 9 hits**, per-page hit counts for
  `/`, `/about.html` and `/pricing.html`, `Chrome`, `Linux`, `Desktop`, a live
  "online" badge, load time, bounce rate, and the ASN and country flag of the
  visiting network — so `X-Forwarded-For` survives the proxy chain and the
  bundled MaxMind databases resolve.
- `POST /projects/shyrec/rebuild`: **29 s**, and afterwards the Service, the
  users, the recorded sessions and hits were all still there, the three files
  in `~/.panelalpha/shynet/` were byte-identical (same sha256), `init` logged
  `an account already exists; leaving it alone`, and **a session cookie issued
  before the rebuild still authenticated** — which is the cheap proof that
  `DJANGO_SECRET_KEY` did not move, since Django signs session *data* with it.
- Memory at idle: `app` 113 MiB of 768, `db` 61 MiB of 256, 209 MiB for the
  whole account.

## Known rough edges

- `manage.py migrate` prints `Your models in app(s): 'account', 'core',
  'socialaccount' have changes that are not yet reflected in a migration` on
  every run. That is upstream's tree, not this recipe; nothing fails.
- `/admin/` is reachable. Upstream mounts it unconditionally.
