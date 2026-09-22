# pretix (github.com/pretix/pretix)

Ticket sales and event management. A Django application served by gunicorn
behind an nginx that also serves the static bundle, a celery worker for the
background queue, and PostgreSQL or SQLite underneath.

Detection: `dockerfile` — and that part was right. The repository ships a root
Dockerfile, it builds in about four and a half minutes, and it is what upstream
publishes as `pretix/standalone`. Unlike Ghost or paperless-ngx, whose own
Dockerfiles cannot produce a self-hosting image from a fresh clone, this one
can, so the recipe keeps it. What went wrong was everything around it, which a
Dockerfile has no way to say.

The verdict was `serving-unknown`: the container up, port 8080 answering
`Recv failure: Connection reset by peer`. Nothing was listening because nothing
had got that far.

## Readiness

`ENTRYPOINT ["pretix"]` is `deployment/docker/pretix.bash`, which runs
`pretix migrate` before it starts anything that binds a port. pretix has about
350 migrations and an empty SQLite file to apply them to — a minute on an idle
machine, several on a busy one. `AppLauncher` runs `docker compose up -d`
without `--wait` and calls the deploy finished when that returns, which is
while the migration is still going.

`ready` — `alpine:3`, `entrypoint: exit 0`, `restart: "no"` — waits on the
app's healthcheck and nothing else, and compose does block on a
`depends_on: condition: service_healthy`, so `up -d` now returns only once the
site answers. A clean exit 0 is explicitly not a crash loop to
`AppHealth::isCrashing()`.

The image ships no healthcheck of its own, so the override writes one: `curl`
against `http://127.0.0.1/` (the loopback vhost, below), `start_period: 420s`
to cover the migration.

## An override, not a compose file

The first version of this recipe shipped `overrides/docker-compose.yml`, the
way the Ghost and paperless-ngx recipes do, and it did not become the stack.
Those two repositories ship no Dockerfile the engine will build, so a compose
file written into the checkout is what detection finds. pretix ships a root
`Dockerfile`, detection picks `dockerfile` regardless, and
`DockerfileStrategy::apply()` then reads a compose file beside it only through
`runtimeSidecarsFromProject()` — for the datastores an app needs, not for the
app. What that produced was a generated compose file with the app service's
`command`, `healthcheck`, `mem_limit` and mount dropped, `ready` extracted as a
sidecar, and `depends_on: [ready]` on the app — the readiness gate wired up
backwards. The deploy log says it in one line: `Keeping runtime services from
compose: ready`.

So this is `extends: dockerfile` plus `overrides/docker-compose.override.yml`,
which Docker Compose merges at runtime and the engine never parses.
`extends:` skips the probes, which is why `extra:` has to restate the
`dockerfile` and `port_hint` the dockerfile probe would have found.

## `web`, not `all`

The image's `CMD` is `all` — nginx, gunicorn **and** a celery taskworker under
supervisord. There is no broker here: with no `[celery] broker` configured
`settings.py` sets `CELERY_TASK_ALWAYS_EAGER` and pretix runs its tasks inside
the web process. A taskworker started anyway retries celery's
`amqp://localhost` default forever and restarts under supervisord while doing
it, for no work it would ever be given. `web` is the same supervisord tree
without it.

## Worker count

`pretix.bash` defaults `NUM_WORKERS` to `2 * $(nproc)`, and `nproc` inside the
account reports the host's whole core count rather than the account's slice —
sixteen Django processes on this machine, several gigabytes, in an account
capped at 2.5 GB. Pinned to 2.

## SQLite, no Redis

Unset `[database] backend` already means `sqlite3`; one account's event is not
what a database server is for, and the ~400 MB a Postgres sidecar wants is
memory the web workers want more. Redis would be a cache and a celery broker,
both optional — and configuring the broker would mean running the taskworker
this recipe deliberately does not.

Switch either on through the account's env vars: `PRETIX_DATABASE_BACKEND`,
`PRETIX_DATABASE_HOST`, … and `PRETIX_REDIS_LOCATION` map onto `pretix.cfg`
sections through `EnvOrParserConfig` (`PRETIX_<SECTION>_<OPTION>`).

## The site URL, and why pretix is stricter about it than most

**pretix is multi-tenant by hostname.** `MultiDomainMiddleware` takes every
request's Host, compares it against `urlparse(SITE_URL).netloc` and against the
`KnownDomain` table, and renders `400_hostname.html` — "Unknown host" — for
anything matching neither. `ALLOWED_HOSTS` is `['*']`, so Django never gets a
say; this is pretix's own check and it is not a warning.

Left at pretix's own default (`http://localhost:8000`) the account's domain is
an unknown host and the site serves a 400 to every visitor.

`ComposePlaceholders`' `http://localhost` trick — the one the Ghost and
paperless-ngx recipes use — is not available here, because it runs in
`UserComposeStrategy` and this deploy goes through `DockerfileStrategy`. What
that strategy does instead is `ComposeHarden::urlEnvironment()`, which writes
the account's public https URL onto the app service as `URL`, `PUBLIC_URL`,
`BASE_URL`, `APP_URL`, `ASSET_URL`, `SITE_URL` and `ENDURAIN_HOST`. pretix
reads none of those names, so the override's start command copies one across:

    export PRETIX_PRETIX_URL="${SITE_URL:-http://localhost}"

In the command rather than in `environment:` because compose cannot interpolate
one service variable from another, and the generated file is not an app
config's to edit. supervisord passes it on to gunicorn (`sudo -E`, and a
program's own `environment=` adds to the inherited set rather than replacing
it).

It is also what `CSRF_TRUSTED_ORIGINS` is built from — one entry, derived from
`SITE_URL` — so the login POST depends on it too.

## `files/pretix-loopback.conf`

The same Host routing is a problem for the health probe.
`AppHealth::probeScript()` curls `http://127.0.0.1:8080/` from inside the
account **with no Host header**, so pretix sees `Host: 127.0.0.1:8080`, calls
it an unknown host, and the probe reads a 400 error page — while the domain
answers 200 at the same moment.

The fix is one extra nginx `server` block, mounted into the image's `conf.d`,
matched by `server_name 127.0.0.1 localhost ""` so it takes loopback requests
and leaves every other Host to the image's own `default_server`. It proxies to
the same socket with `proxy_set_header Host localhost`, and
`LOCAL_HOST_NAMES` in `multidomain/middlewares.py` is exactly
`('testserver', 'localhost')` — a request for one of those is served the
main-domain urlconf whatever `SITE_URL` says. The probe gets the page a visitor
gets.

It has to be a whole vhost rather than a one-line override because the image's
`location /` sets `proxy_set_header Host $http_host`, and nginx inherits
`proxy_set_header` from an enclosing level **only when the current level sets
none**. Nothing in `conf.d` can reach inside that location. The alternative was
forking the image's `nginx.conf`, which is a larger file to keep in step with
upstream for the same one line.

## The admin account, and the password upstream ships

`pretixbase/0001_initial.py` is not only tables. Its `initial_user` data
migration creates **`admin@localhost` with `make_password('admin')`** and
`is_staff` set, and the squashed `0001_squashed_0028` does the same. So a
pretix that has only ever been migrated is already open to anyone who has read
its repository, on a well-known address with a well-known password — and this
is the one thing about the deploy that is worse than not serving at all.

It also means `createsuperuser` is the wrong tool: for that address it exits 1
with `Error: That Email is already taken`, and for any other address it would
leave the default one in place beside the new account.

So `hooks/prepare.sh` generates a password into `~/project/.env` — once,
because the data volume outlives the checkout — and the start command runs
`files/panelalpha-admin-password.py` through `pretix shell` between the
migration and gunicorn. Django's `shell` execs stdin when stdin is not a tty,
so the script is a readable file rather than a `-c` one-liner, mounted at
`/pretix/panelalpha-admin-password.py`. It reads `PRETIX_ADMIN_EMAIL` and
`PRETIX_ADMIN_PASSWORD`, which the generated service already has from `.env`
through `env_file:`.

The script is **guarded on the password still being the shipped default**
rather than on a first-boot marker:

    if u is not None and u.check_password("admin"):

Re-applying the `.env` password every boot would be harmless — it never
changes — but an operator who has since set their own password in the web
interface would find it reset under them by the next redeploy. Checking for
the default is the narrower statement: rotate what upstream published, never
what somebody chose. Credentials are in `~/project/.env`.

`|| true` on the step, so that a site which would otherwise serve is never held
back by it.

## Proxy headers

`trust_x_forwarded_proto` and `trust_x_forwarded_for` both default to off in
pretix, which behind the engine's TLS-terminating proxy means http:// links in
https pages, no `SECURE_PROXY_SSL_HEADER`, and every client logged as the proxy.
Both are turned on; the only thing that can reach this container is that proxy.

## Memory

`mem_limit` is set per service because an override reaches Docker as written —
the hardener never sees it — and `ServiceLimits`' defaults (512 m, 384 m for an
app-role service) are far below what gunicorn plus nginx plus an eager-celery
request needs. 1792 m for the app, 64 m for `ready` — 1856 m inside a 2500 m
account.

## Not configured

**Mail.** pretix boots, the control panel works and events can be created
without it, but order confirmations, ticket delivery and password resets need
an SMTP server the engine does not provide — `EMAIL_HOST` falls back to
`localhost:25`, where nothing is listening. Point `PRETIX_MAIL_HOST`,
`PRETIX_MAIL_PORT`, `PRETIX_MAIL_USER`, `PRETIX_MAIL_PASSWORD` and
`PRETIX_MAIL_FROM` at a server of your own.

**The periodic cron.** `pretix cron` (`runperiodic`) handles scheduled exports,
reminder mails and ECB exchange-rate updates. It is a second process with
nothing to do on an installation that cannot send mail, so it is left out;
`docker compose exec app pretix cron` runs it by hand.
