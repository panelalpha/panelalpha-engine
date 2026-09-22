# Budibase (github.com/Budibase/budibase)

A low-code platform for internal tools: a builder UI, a client runtime for the
apps it builds, automations, and connectors to external databases. CouchDB is
its database, Redis its cache and job queue, MinIO its object store.

Detection: `railpack` — and that is the failure. The repository root is a
13-package yarn-1 Lerna workspace whose `package.json` has no `start` script,
and every compose file it ships lives under `hosting/` or under
`packages/server/scripts/integrations/`, names Docker never auto-loads. Railpack
installed the workspace, printed `No start command detected`, generated a basic
compose around `nginx:alpine`, and the deploy "finished successfully" with the
account serving PanelAlpha's placeholder page on 8080.

## The checkout does not build

Unlike NocoDB, this repository *does* contain Dockerfiles. None of them builds
this tree:

| Dockerfile | What it copies |
|---|---|
| `hosting/single/Dockerfile` | `packages/server/dist`, `packages/server/client`, `packages/server/builder`, `packages/worker/dist` |
| `packages/server/Dockerfile` | `packages/server/dist`, `packages/server/client`, `packages/server/builder` |
| `packages/worker/Dockerfile` | `packages/worker/dist`, `packages/worker/dist/yarn.lock` |

`packages/server/.gitignore` line 8 ignores `dist`; `builder/` and `client/` do
not exist anywhere in the tree. Every one of those `COPY` lines fails on a fresh
clone. Each Dockerfile also ends with `RUN test -n "$BUDIBASE_VERSION"`, a
`--build-arg` nothing in a deploy supplies — the version is injected at release
time, which is also why `lerna.json` is `"version": "independent"`, every
`packages/*/package.json` says `0.0.0`, and `charts/budibase/Chart.yaml` carries
`# populates on packaging` above both its `version` and its `appVersion`.

These are packaging steps that run *after* `lerna run build` over the whole
workspace. Producing them inside an account means a full TypeScript + Svelte
build of 13 packages (the root build script asks for a 1500 MB Node heap by
itself), and then a Docker build on top. So the recipe runs the published
images, which is what upstream's own `hosting/docker-compose.yaml` does.

## Why not the all-in-one image

Upstream also publishes `budibase/budibase`, a single container of the whole
stack, and `hosting/single/Dockerfile` shows what is in it: CouchDB with
Clouseau (a JVM) and SQS, Redis, MinIO, nginx, **a PostgreSQL 15 server**, **a
LiteLLM proxy in a Python venv**, and the Node server and worker, all under pm2.
`hosting/single/runner.sh` has no flag to leave LiteLLM out — it mints
`LITELLM_MASTER_KEY` itself when unset, `initdb`s a cluster under
`${DATA_DIR}/litellm/postgres` and starts the proxy unconditionally, waiting up
to 120s for it.

Measured on `mariusz`, `budibase/budibase:latest` in a 2000 MB container with no
swap (`--memory 2000m --memory-swap 2000m`), with `BB_ADMIN_USER_*` set and
nothing else:

- image, uncompressed: **4.73 GB** (1.20 GB to pull)
- 580 MiB at 15s, **1.952 GiB of 1.953 GiB (99.95%) by 3½ minutes**
- `State.OOMKilled` true from ~3½ minutes on. `dmesg`: `Memory cgroup out of
  memory: Killed process … (node)` four times and `… (python)` once — the
  LiteLLM proxy at 434 MB anon-rss.
- at **10 minutes**: healthcheck `unhealthy`, `curl http://localhost/` inside the
  container still **502**. It never served a page.

So it does not fit, and it is not close: the deficit is roughly the LiteLLM
proxy plus its PostgreSQL. The split stack below is the same application without
that pair, and it is what this recipe runs — 1441 MiB steady state, measured the
same way.

## The stack

`overrides/docker-compose.yml`, which becomes the project's compose file. It is
the shape of upstream's `hosting/docker-compose.yaml` minus `litellm-service`
and `litellm-db`.

- **proxy-service** — `budibase/proxy`, the only service published, on 10000.
  Not a generic reverse proxy: `hosting/proxy/nginx.prod.conf` is Budibase's URL
  map, splitting `/api/global` and `/api/system` to the worker, `/db/` to
  CouchDB, signed file URLs to MinIO and everything else to the app. Nothing
  answers correctly without it. It is safe from `HostIngress::strip()`, which
  matches on image (`traefik`, `caddy-docker-proxy`, `jwilder/nginxproxy`
  `nginx-proxy`) and not on service name.
- **app-service** — `budibase/apps`, the server, builder and automations.
- **worker-service** — `budibase/worker`, users, auth, tenants, licensing, email.
- **couchdb-service** — `budibase/database`, which is not stock CouchDB: it
  bundles Clouseau and SQS, and `charts/budibase/values.yaml` says "we don't
  support using any other CouchDB image".
- **redis-service** / **minio-service** — cache and job queue, object store.
- **ready** — a no-op that exits 0. See *Readiness*.

No LiteLLM. `waitForLiteLLMReadiness()` in `packages/server/src/startup/index.ts`
returns immediately when `LITELLM_MASTER_KEY` is unset, so leaving it out is a
clean 30s off every boot rather than an error — the log line reads
`Waiting for LiteLLM readiness` and `Server ready!` in the same millisecond.
What the customer loses is Budibase's AI features, which need an LLM provider
key nobody has configured anyway. An account that wants them adds the two
services from `hosting/docker-compose.yaml` and the memory to run them.

### Image tags

There is no version in this checkout to derive a tag from — see above, every
version string in the tree is `0.0.0`. So `hooks/prepare.sh` uses upstream's own
release channel, `stable`, confirmed to exist on Docker Hub before it is used
and falling back to `latest` (the same digest today). The one real version in
the tree is `hosting/couchdb/VERSION` (`2.1.0`), which
`charts/budibase/values.yaml` pins as well, and the CouchDB image is tagged from
it: the database's on-disk layout is not something to have change under a
redeploy.

### Secrets

Generated in `hooks/prepare.sh` into `.env`, which compose interpolates, and
guarded as a whole so a redeploy never rotates them — the volumes outlive the
checkout:

- `COUCH_DB_USER` / `COUCH_DB_PASSWORD` — hex, because they are embedded in
  `COUCH_DB_URL` as userinfo.
- `REDIS_PASSWORD`, `MINIO_ACCESS_KEY` / `MINIO_SECRET_KEY`.
- `INTERNAL_API_KEY` — how app and worker authenticate to each other.
- `API_ENCRYPTION_KEY` — encrypts the credentials of every external datasource
  the customer connects. Rotating it after data exists makes them unreadable.
- `JWT_SECRET` — signs every session cookie and API token.

### The admin, and the window that is open without it

Budibase has no installer. Until a first global user exists, `POST
/api/global/users/init` is unauthenticated — it lives in the worker's
`cloudRestrictedRoutes`, which is a self-host restriction, not an auth one — and
`users.ts` only refuses it *after* a user exists ("You cannot initialise once an
global user has been created."). On a public HTTPS name that means the first
stranger to open the site becomes the admin.

`BB_ADMIN_USER_EMAIL` / `BB_ADMIN_USER_PASSWORD` close that window rather than a
setup service: `packages/server/src/startup/index.ts` calls
`users.UserDB.createAdminUser` from them on first boot when `SELF_HOSTED` is set
and `MULTI_TENANCY` is not. The password is 20 alphanumeric characters generated
per account into `~/project/.panelalpha-admin-password` (0600), never a default.
There is no self-signup afterwards — Budibase self-host has no public
registration at all, so unlike NocoDB and Mattermost nothing further has to be
turned off. Verified on a live deploy: `POST /api/global/users/init` answers
403 once the account is up.

### The public address

`PLATFORM_URL` is the one value Budibase cannot work out for itself:
`getPlatformUrl()` in `backend-core/src/configs/configs.ts` falls back to
`http://localhost:10000`, and that value ends up in password-reset links,
invitation emails and OAuth callback URLs. The compose file ships
`PLATFORM_URL: http://localhost`, exactly the shape `ComposePlaceholders`
rewrites — the key ends in `_URL` and the value is a bare localhost — so
`UserComposeStrategy` substitutes the account's https URL before the stack
starts. The deploy log says `Pointed the application address at its public URL:
PLATFORM_URL`, and `/api/global/configs/settings` afterwards holds the account's
real name.

The other `*_URL` keys in the file (`COUCH_DB_URL`, `WORKER_URL`, `MINIO_URL`,
`APPS_URL`) are service addresses, not localhost, so the same rule leaves them
alone.

### Readiness

The engine runs `docker compose up -d` without `--wait` and takes the deploy to
be finished when that returns — which is when the containers have been
*started*. The gap is large here: CouchDB brings up a JVM and an Erlang VM and
creates `_users`/`_replicator`, then the server runs its workspace migrations
and creates the admin, and only then does the proxy have anything to proxy to.
`ready` does nothing and exits 0, waiting on the proxy's healthcheck, which
waits on app and worker, which wait on CouchDB, Redis and MinIO. A clean exit 0
is explicitly not a crash loop to `AppHealth::isCrashing()`.

### Memory, and the flag that made it fit

The first attempt at this recipe gave `app-service` 640m and failed the deploy
outright: the container was OOM-killed mid-boot, compose saw its dependency
restart and aborted `up -d`, and the whole deploy came back `failed` with the
image-pull progress dump as its error message.

The cause is that the Budibase server is not one process.
`packages/server/src/threads/index.ts` starts a `worker-farm` for queries and
another for automations, each of which **forks a full Node process** loading the
whole server bundle — and `packages/server/docker_run.sh` adds
`--enable-source-maps` to all three, which its own comment prices at "~150MB of
retained source map per process". `DISABLE_SOURCE_MAPS=1` is the switch that
script checks, and it is what this recipe sets. The cost is that a stack trace
in the logs points at `dist/`, not at `src/`.

`NODE_OPTIONS` is written out on both Node services for a second reason: the
images bake `ENV NODE_OPTIONS="--no-node-snapshot"`, which `isolated-vm`
requires on Node 20+ to run any JS binding in an app, and `ServiceHardener`
replaces `NODE_OPTIONS` unless compose already declares it. The heap cap and
that flag have to be set together or the JS runner breaks.

`pids_limit` is raised from the hardener's 256 on the three containers that need
it. It counts threads: a JVM plus an Erlang VM plus SQS in `couchdb-service`,
and three Node processes with their libuv pools in `app-service`.

Measured steady state on a 2000 MB account, signed in, with one workspace
created:

| Service | Limit | Measured |
|---|---|---|
| app-service | 896m | 694 MiB |
| couchdb-service | 640m | 413 MiB |
| worker-service | 384m | 240 MiB |
| minio-service | 192m | 79 MiB |
| proxy-service | 64m | 9 MiB |
| redis-service | 96m | 6 MiB |
| **total** | | **~1441 MiB of 2000** |

The account has no swap (`MemorySwap == Memory`), so that headroom is all there
is. An account given less than 2000 MB will not run this.

## Not covered

- **Mail.** Budibase boots, the admin signs in and workspaces can be built
  without it, but invitations and password resets need an SMTP server the engine
  does not provide. Configure one in the portal under Email.
- **`overrides/app.sh`.** Not written. The worker exposes a full
  `/api/global/users` CRUD behind a session cookie, and `JWT_SECRET` is in the
  account's `.env`, so minting an admin cookie for `users:list` / `users:add` /
  SSO (Pattern B, cookie `budibase:auth`) is a tractable next step; it is simply
  not done here.
- **Scaling.** `CLUSTER_MODE` in both images switches `docker_run.sh` to
  `pm2-runtime` with one instance per CPU. That multiplies the Node processes
  described above and does not belong on an account this size.
