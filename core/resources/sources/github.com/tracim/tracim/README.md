# Tracim (github.com/Tracim/tracim)

Shared workspaces, documents, threads, files and a kanban for a team. A Python
(Pyramid) backend served by uwsgi behind Apache on :80, a React frontend, redis
and pushpin for live messages, and SQLite or PostgreSQL underneath.

Detection: `railpack` — and it was reading the right file for the wrong half of
the application. The repository root *is* an 18-package yarn workspace
(`frontend/`, `frontend_lib/`, `frontend_app_*`), so Railpack found Node 16 and
yarn 3.2.0, installed them, and then said what it always says about a tree with
no entry point: *"No start command detected."* The server it never saw is in
`backend/`, in Python. With no start command and no compose file at the root the
engine generated a basic compose, started `nginx:alpine` on :8080 and served its
own page: HTTP 200, `serving-placeholder`.

Building the checkout is not the alternative, and the repository says so itself.
Its only production image recipe, `tools_docker/Debian_Uwsgi/Dockerfile`, opens
by cloning tracim back out of GitHub (`ARG REPO` / `BRANCH` / `TAG`) and then
builds the yarn workspace and installs the Python backend on Debian — hundreds
of megabytes and many minutes per account, for an image that is published. So
the recipe runs the published one, `algoo/tracim`, which is what upstream's own
`tools_docker/docker-compose.yml` runs.

`overrides/docker-compose.yml` is that file rewritten for a single hosting
account. Upstream's compose files all live one directory down — `tools_docker/`
and `backend/` — where neither Docker nor the engine's compose probe looks
(`ComposeFileInspector::COMPOSE_FILE_CANDIDATES` is root-only, and
`RuntimeSidecars`'s `docker-compose.*.yml` glob is a root-only `glob()`), so
nothing was stashed and no sidecar was mined from them. The deploy log confirms
it: no *"Keeping runtime services from compose:"* line.

- **tracim** — `algoo/tracim:latest`, published on 8080 → container :80.
- **setup** — runs once, changes the admin password, exits. See *The admin*.
- **ready** — a no-op that exits 0. See *Readiness*.

## The image tag

A constant, `latest`, which is not how the Ghost and Mattermost recipes do it
and is the registry's doing rather than a shortcut. Upstream's README:

> Docker images for the latest Tracim versions are only available to our paying
> customers.

Docker Hub bears that out. The newest public *release* tag on `algoo/tracim` is
`2025-04.00`, which is what `latest` points at, while this checkout's
`CHANGELOG.md` is already on `2026.10.00`. Nothing in the tree names a tag that
exists publicly, so deriving one from the checkout would only produce a 404 at
`up -d`. `unstable` is rebuilt from `develop` continuously and would match the
checkout, but it is named what it is. Point `TRACIM_IMAGE` at it in
`~/project/.env` if you want that trade.

## SQLite, not PostgreSQL

`DATABASE_TYPE=sqlite` is a first-class option in the image's own entrypoint —
`common.sh` writes `sqlite:////var/tracim/data/tracim.sqlite` for it, and
`check_env_vars.sh` stops asking for `DATABASE_USER` and friends. One team's
document store is not what a database server is for, and the ~300 MB a Postgres
sidecar wants is memory the uwsgi workers want more. Switch by setting
`DATABASE_TYPE=postgresql` and the five `DATABASE_*` variables at a database of
your own.

## WebDAV and CalDAV are off

`START_WEBDAV=0`, `START_CALDAV=0`. Each is a further uwsgi application holding
its own copy of the Python backend, for a WebDAV mount and a CalDAV/agenda
endpoint a first deploy does not need. `START_CALDAV=0` also keeps the enabled
app list consistent, which is upstream's own rule: the entrypoint only appends
`agenda` to `DEFAULT_APP_LIST` when caldav is on, and upstream's docs say the
two *must* agree. Turning either back on means raising `mem_limit` with it.

## The admin

Tracim seeds the same administrator on every first boot.
`tracim_backend/command/database.py`, `InitializeDBCommand._populate_database()`:

```python
user_api.create_user(name="Global manager", username="TheAdmin",
                     email="admin@admin.admin", password="admin@admin.admin",
                     profile=Profile.ADMIN, do_notify=False)
```

Hard-coded, not printed for an operator to change, and on a public HTTPS name
it is an open door with the key in the repository. `files/panelalpha-setup.sh`
runs once the tracim service is healthy and replaces it over Tracim's own REST
API — HTTP Basic is one of the authentication policies the backend installs, and
`PUT /api/users/{id}/password` takes the current password in the body. Over the
API rather than `tracimcli user update` because tracimcli lives inside a
container whose entrypoint ends in `tail -f`, and wrapping that process tree to
run one command is more moving parts than one `curl`.

Three outcomes, all of them exercised against a live deploy:

| situation | what it does |
|---|---|
| the recorded password already logs in | says so, exits 0 — the redeploy case |
| the shipped password logs in | changes it, **logs in again with the new one**, exits 0 |
| neither logs in | somebody changed it inside Tracim; leaves it alone, exits 0, says the credentials file is stale |

A failed change — a non-204 from the setter, or a 204 the login does not agree
with — exits 1, which fails `ready`, which fails the deploy. A Tracim answering
200 on a published default password is not a deploy that succeeded.

The password is generated by `hooks/prepare.sh` into
`~/.panelalpha/tracim.env` (0600) and copied to `~/project/.panelalpha-admin-password`
(0600). **Not** kept in `~/project` alone: `ProjectTree::clearContents()` runs
`find ~/project -mindepth 1 -maxdepth 1 -exec rm -rf` before every clone,
dotfiles included, while the Docker volume holding the SQLite database survives
— so a `.env`-only secret would be regenerated on redeploy into a password the
database never learns.

> **Engine caveat.** `ProjectEnvironment::apply()` copies the prepare hook's
> `.env` to `.env.default` with mode `644`, so the generated password is
> world-readable inside the account however tightly the hook chmods `.env`. It
> applies to every recipe that puts a secret in `.env` (paperless, Ghost,
> Mattermost, SolidInvoice), not just this one.

## `TRACIM_WEBSITE__BASE_URL`

The value the recipe cannot generate. It ships as a bare `http://localhost` —
the shape `ComposePlaceholders::isLocalPublicUrl()` rewrites, since the key ends
in `_URL` and the value is a bare localhost — and the deploy log confirms the
rewrite: *"Pointed the application address at its public URL:
TRACIM_WEBSITE__BASE_URL"*. Tracim reads `TRACIM_<SECTION>__<KEY>` ahead of its
ini file (`config.py`: *"Priority: 1: Environment variable"*), and the
entrypoint dumps `printenv | grep TRACIM` into
`/var/tracim/data/tracim_env_variables`, which is how uwsgi — started by
`service uwsgi restart`, without the container's environment — ever sees it.

What it is *not*: the thing that makes the page render. Checked on the running
deploy — every `<script>` in `index.mak` carries that response's CSP nonce and
the frontend calls the API on relative paths, so the SPA loads with a wrong
`base_url` too. What breaks is every link Tracim puts in an email (notification,
password reset, share, upload permission) and the CSP entry covering the assets
the rich-text editor injects at runtime, which have no nonce.

## Readiness and the healthcheck

`AppLauncher` runs `docker compose up -d` without `--wait`, so the deploy is
"finished" when the containers have been *started*. Tracim's first boot then
writes `development.ini`, runs `tracimcli db init` against an empty SQLite file,
creates the search index, and starts redis, pushpin, cron and supervisord before
uwsgi. `ready` — the curl image, `entrypoint: exit 0`, `restart: "no"` — waits
on `setup`, which waits on tracim's healthcheck, so `up -d` returns only once
Tracim answers *and* the admin password has been changed and re-verified. A
clean exit 0 is explicitly not a crash loop to `AppHealth::isCrashing()`.

The healthcheck curls `/api/system/config` and greps the body for
`instance_name`. `/api/system/config` because it is the one endpoint with no
`@check_right` on it (`system_controller.config`) and it only answers once the
backend has read its config — `/` would go green on the Apache that starts
seconds before uwsgi. Grepping the body rather than trusting the code because an
Apache error page is a perfectly valid HTTP response too.

## Memory

Measured on the host, idle, nobody logged in: **1.16 GiB of a 1.375 GiB limit
(84%)** with the image's stock four uwsgi workers — five uwsgi processes at
~160–185 MB RSS each, plus an rq worker, the mail notifier, the connection-state
monitor, Xvfb, redis, pushpin's five processes and Apache. That is not headroom;
a file preview forking unoconv would have found the OOM killer.

`files/panelalpha-uwsgi-web.ini` is mounted over `/etc/tracim/tracim_web.ini`
with `workers = 2`, which brings it to **691 MiB (49%)** for the same 8
concurrent requests (2 × 4 threads). It works because `common.sh` only writes
that file `if [ ! -f ]`, and because Docker applies the deeper mount after the
`/etc/tracim` volume. The file is byte-identical to the image's own
`uwsgi.ini.sample` apart from the three lines `common.sh` fills in; if a future
image needs an option it does not carry, that file is where to add it.

`pids_limit: 512` because 124 processes and threads at idle is close enough to
`ServiceHardener`'s stock 256 to matter. `mem_limit` 1408 m + 128 m + 64 m =
1600 m of a 2000 m account.

## Not configured

Mail. Notifications, invitations and password resets all need an SMTP server the
engine does not provide; set `TRACIM_EMAIL__NOTIFICATION__ACTIVATED`,
`TRACIM_EMAIL__NOTIFICATION__SMTP__SERVER` and their companions through the
account's env vars. Self-registration is off, which is Tracim's own default
(`user.self_registration.enabled = False`): the admin creates accounts.
ElasticSearch search and Collabora document editing are both single env-var
switches away, each needing a service of its own.
