# Seafile (github.com/haiwen/seafile)

**This repository is the Seafile desktop sync client daemon, not the Seafile
server.** It is an autotools C/Vala tree — `configure.ac`, `autogen.sh`,
`daemon/seaf-daemon.c`, `lib/`, `app/seaf-cli`, a Visual Studio solution and a
`vcpkg.json` — that builds `libseafile`, `seaf-daemon` and `seaf-cli`. 217
files, no HTTP server, no compose file, no Dockerfile, no `.env`. Its own
README says it in as many words:

> Sync client daemon (this repository): https://github.com/haiwen/seafile
> Server core: https://github.com/haiwen/seafile-server
> Server web UI: https://github.com/haiwen/seahub

Detection: **Unknown**, strategy `fallback`, `nginx:alpine` over the checkout →
`serving-placeholder`. That verdict was correct about the tree.

## Why there is a recipe here anyway

The same README calls this repository *"the front page for Seafile project on
Github"* and routes server, web-interface and desktop-client bugs to its issue
tracker; `haiwen/seafile-docker`'s README links here as "a Seafile server". A
customer who pastes this URL wants Seafile, and the deployable Seafile is the
server Seafile Ltd assembles from `seafile-server` + `seahub` + `ccnet` and
publishes as `seafileltd/seafile-mc`.

So the recipe runs the published image. That is the same conclusion the Ghost,
Mattermost and paperless-ngx recipes reach — but be clear about the difference:
there the checkout is the application's own source and only the *build* is
skipped. Here the checkout is a **different program** and contributes nothing.
Not the source, not the compose file, and deliberately **not the version
either**: `configure.ac` says `9.0.21`, which is the sync client's version and
has no relationship to the server's. `hooks/prepare.sh` pins the `13.0` series
instead.

If you want the C daemon built, this is not what happens. Nobody hosts a sync
client on a web host; the honest reading of the URL is the product.

## The stack

`overrides/docker-compose.yml`, from manual.seafile.com's 13.0
`ce/seafile-server.yml`, rewritten for one account.

- **seafile** — `seafileltd/seafile-mc:13.0-latest`. nginx on :80 in front of
  seahub (gunicorn :8000) and the Go file server (:8082); published as `8000:80`,
  which is the port the engine detects. `/shared` on a named volume: conf,
  ccnet, the block store, seahub-data and logs.
- **db** — `mariadb:10.11`. **Not optional and not swappable**: Seafile has no
  SQLite mode. `seaf-server` links against libmysqlclient and
  `setup-seafile-mysql.py` creates `ccnet_db`, `seafile_db` and `seahub_db`
  before anything starts. Tuned down (64 MB buffer pool, 60 connections, no
  performance schema).
- **cache** — `redis:8-alpine`, no persistence. Also not optional: since
  Seafile 13 the default `CACHE_PROVIDER` is `redis` and
  `seahub/settings.py` ends its cache block with
  `raise ValueError(f'Invalid CACHE_PROVIDER: …')`, so there is no "no cache"
  path. Named `cache`, not `redis`, so `SidecarEngine` does not claim it and
  overwrite the memory figure.
- **ready** — a no-op that exits 0. See *Readiness*.

**Dropped from upstream's file:** Caddy (the engine is the reverse proxy), and
every extension its `.env` enables by default — SeaDoc (`ENABLE_SEADOC=true`
upstream; `sdoc-server` is a ~500 MB image of its own), the notification
server, the metadata server and the AI service. Four more containers and about
a gigabyte for features a file server does not need to answer. Turn any of them
back on by adding the service and flipping the matching `ENABLE_*`.

## The public address

The value the recipe cannot generate, and the one place Seafile does not fit
the engine's shape. Seafile wants a **hostname and a scheme**, not a URL:
`SEAFILE_SERVER_HOSTNAME` and `SEAFILE_SERVER_PROTOCOL`. Neither matches
`ComposePlaceholders::isLocalPublicUrl()`'s key pattern
(`/(^|_)(URL|ORIGIN|ENDPOINT|DOMAIN)$/i` — `HOSTNAME` is not `DOMAIN`), so the
engine cannot write either of them.

So the compose file carries `PA_PUBLIC_URL: http://localhost`, exactly the
shape that *is* rewritten, and `files/panelalpha-seafile-init.sh` — the
container's entrypoint, in front of the image's own
`/sbin/my_init -- /scripts/enterpoint.sh` — splits it and exports the two
variables. `my_init` imports the existing environment without overriding it
(`import_envvars(False, False)`) and re-exports it to
`/etc/container_environment`, so it reaches seahub.

`seahub/settings.py` (lines 1289-1294 in 13.0.28) reads both from the
environment **on every boot** and rebuilds `SERVICE_URL` and
`FILE_SERVER_ROOT` from them — they are not baked into a config file — so a
renamed project follows its domain on the next redeploy with no reinstall.

Without it the site still answers (`ALLOWED_HOSTS = ['*']` in `settings.py`, so
the loopback health probe is never a 400 — engine#165 does not bite here), but
every absolute link it generates — share links, upload endpoints, avatar URLs —
points at `localhost`. The script logs a warning when that happens.

## Seahub's worker count

`setup-seafile-mysql.py` generates `conf/gunicorn.conf.py` with `workers = 5`
and `threads = 4`: five **preloaded** Django 5.2 processes, sized for a
dedicated server. The init script patches the generator (so first boot writes
2) and the generated file (so every boot after keeps 2), which makes the
ordering irrelevant. Override with `SEAHUB_WORKERS` / `SEAHUB_THREADS`.

Measured idle after a first boot on mariusz: seafile 433 MB, db 132 MB, cache
8 MB — 573 MB of a 2000 MB account.
`mem_limit`s are 1280/384/96/64 m — 1824 m inside a 2000 m account, with the
headroom on the seafile service where uploads and thumbnailing need it.

## Credentials

`hooks/prepare.sh` writes `.env` **once** — the volumes outlive the checkout,
so a regenerated DB password would lock `seaf-server` out of its own schemas
and a regenerated admin password would be one the database never learns.

- `SEAFILE_MYSQL_DB_PASSWORD`, `INIT_SEAFILE_MYSQL_ROOT_PASSWORD` — the root
  one is needed because `setup-seafile-mysql.py` creates the three schemas and
  the `seafile` user itself.
- `JWT_PRIVATE_KEY` — signs the tokens seahub hands the file server. Seahub
  starts without it and then fails every upload and download.
- `INIT_SEAFILE_ADMIN_EMAIL` / `INIT_SEAFILE_ADMIN_PASSWORD` — Seafile has no
  sign-up page and no installer, and upstream's default is
  `me@example.com` / `asecret`. `start.py` writes these to `conf/admin.txt` on
  first boot, `seahub.sh` consumes it creating the superuser, and `start.py`
  deletes the file. Also written to `~/project/.panelalpha-admin-password`
  (0600), which is where a human is pointed.

Written with plain `${VAR}` references, never compose's `${VAR:?…}` form:
`ComposePlaceholders::requiredSecret()` would replace `JWT_PRIVATE_KEY` with a
value of its own and the `.env` would be ignored.

## Readiness

`AppLauncher` runs `docker compose up -d` without `--wait`. `ready` — the
cache's image, `entrypoint: exit 0`, `restart: "no"` — waits on the seafile
healthcheck, so `up -d` returns only once the site answers. A clean exit 0 is
not a crash loop to `AppHealth::isCrashing()`.

The healthcheck is the image's own `curl` probe with a much longer grace:
upstream bakes in 30 s / 3 retries / 10 s start period, and a first boot
creates three schemas, runs every Django migration and builds the admin. Here:
10 s interval, 60 retries, `start_period: 420s`. It probes *through* nginx,
which answers long before gunicorn does — a dead seahub is a 502 and `curl -f`
fails on it.

## Uploads and the tunnel edge (not this recipe)

Verified working: sign-in, library creation, upload through `/seafhttp` and
download, over the account's own address. Over a `*.panelalpha.online` tunnel
domain the upload POST hangs and the edge answers
`302 → withoutdns.com/internal-server-error.html`.

That is **not Seafile**. On the same host a bare `-F a=b` POST to two unrelated
projects (bugsink, swingmusic) also times out, while `GET` and
`application/x-www-form-urlencoded` POSTs to the same URLs answer normally, and
`GET /seafhttp/protocol-version` through the tunnel returns `{"version": 2}`.
Every `multipart/form-data` request on that edge stalls, for every app. It
matters more here than elsewhere only because uploading files is what Seafile
is for.

## Not configured

- **Mail.** Seafile sends password resets and share notifications through SMTP
  the engine does not provide. Set `EMAIL_HOST` and friends in
  `conf/seahub_settings.py` inside the `seafile_data` volume.
- **No `overrides/app.sh`.** Seafile ships `reset-admin.sh` and
  `seahub/manage.py`, and has a full REST API, so user management and SSO are
  both reachable — but nothing here advertises them yet.
- **The desktop clients** connect to this server normally; that they are built
  from the repository this recipe is filed under is a coincidence of naming.
