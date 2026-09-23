# homarr-labs/homarr

Homarr — a self-hosted dashboard: boards of draggable tiles, widgets that poll
other applications, and integrations that hold API keys for them. Apache-2.0,
a pnpm/turbo monorepo that builds one Next.js standalone server.

Tracker: `panelalpha/playground/supported-apps#436`.

## What the repository is, and what goes wrong without this recipe

The repository ships no compose file. Detection reads the root `Dockerfile` and
the `dockerfile` strategy builds the monorepo. Three things then go wrong.

### 1. The build is enormous, and on a normal account it does not finish

On the batch host the build was 427.3 s of a 486.2 s deploy, cache-hit ratio 0,
with 260.9 s in one `pnpm turbo build` layer. Reproduced here at 484 s — but
only after the account was given 12 GB. At a 3 GB limit and again at a 7.5 GB
limit, on 8 cores, the same layer died:

    failed to solve: ResourceExhausted: process "/bin/sh -c
    TURBO_PLATFORM="${TARGETPLATFORM:-linux/amd64}" pnpm turbo build
    --filter=@homarr/nextjs... --filter=@homarr/cli"
    did not complete successfully: cannot allocate memory

and the deploy failed outright. And because the engine's generated
`docker-compose.yml` sits in the build context and changes every redeploy
(engine#208), a `dockerfile` project pays that build again on every rebuild.

### 2. The 502 is an engine bug, not an application bug

This is the interesting one, because nothing in Homarr's own logs points at it.

`DeployCompose::dockerfile()` writes `env_file: ['.env']` into every generated
compose file (DeployCompose.php:107), and `EnvExampleCopies` creates that `.env`
by copying the repository's `.env.example` (EnvExampleCopies.php:12-18,73).
Measured on the control: `~/project/.env` was byte-identical to
`~/project/.env.example` (md5 `a88c2b7c37511b425e08809517a58e61`), and a compose
`env_file` beats an image's own `ENV`, so the running container had:

    DB_URL=FULL_PATH_TO_YOUR_SQLITE_DB_FILE
    SECRET_ENCRYPTION_KEY=0000000000000000000000000000000000000000000000000000000000000000

overriding the image's `ENV DB_URL='/appdata/db/db.sqlite'`. The database path
became a relative filename. `scripts/run.sh` runs the migrations from `/app`
and they succeed — "Migration complete", the seeded board, the seeded groups —
writing `/app/FULL_PATH_TO_YOUR_SQLITE_DB_FILE`. Next.js's standalone
`server.js` then does `process.chdir(__dirname)`, so the server opens the same
relative name from `/app/apps/nextjs`:

    -rw-r--r-- 1 root root 364544  /app/FULL_PATH_TO_YOUR_SQLITE_DB_FILE
    -rw-r--r-- 1 root root      0  /app/apps/nextjs/FULL_PATH_TO_YOUR_SQLITE_DB_FILE

An empty database:

    SqliteError: no such table: session
    SqliteError: no such table: cron_job_configuration
    Next.js exited with code 1, restarting in 1s...

`run.sh` starts nginx before node and restarts node in a `while true` loop, so
port 7575 answers within a second of the container starting and answers 502 for
as long as the container lives. **Not a boot race**: the deploy's own window is
about 1.5 s wide (nginx bound at 05:49:25, the deploy declared success at
05:49:27), but measured 35 minutes later the control was still 502, same
157-byte body by md5, after 1026 restarts of the Next.js process — and 502 from
inside the container too, so it is not the proxy.

The second line of that `.env` is the quieter half. `0000…0` is 64 valid hex
characters, so it passes `SECRET_ENCRYPTION_KEY`'s validation
(packages/common/env.ts:13-25) and the server would have started with it. A
control deploy that worked would encrypt every integration API key under a key
published in upstream's repository.

### 3. The first visitor owns the instance

Homarr does authenticate — `AUTH_PROVIDERS` defaults to `credentials`, there is
a real user table with bcrypt hashes and group permissions, and **no provider
trusts any proxy-supplied identity header**: there is no `Remote-User`,
`X-Forwarded-User` or forward-auth path anywhere in the tree. (`trustHost: true`
at packages/auth/configuration.ts:61 is NextAuth's callback-URL host, not an
identity.) This is not Hubleys (#1168).

What it has is engine#200's first-visitor problem, in its strongest form.
`onboardingProcedure` (packages/api/src/trpc.ts:169-183) is `publicProcedure`
with one check — "is the database's current step the step this call belongs
to?" — and `user.initUser` (packages/api/src/router/user.ts:50) creates a user,
creates the `credentials-admin` group, grants it `admin` and joins the user to
it. The migrations seed `onboarding` with step `start`.

Measured, against a stock Homarr with no recipe, from an unauthenticated
client, two POSTs:

    POST /api/trpc/onboard.nextStep   {"json":{}}
      -> 200, step start -> user
    POST /api/trpc/user.initUser
         {"json":{"username":"attacker","password":"…","confirmPassword":"…","email":""}}
      -> 200, step user -> settings

    users:        [{"name":"attacker","provider":"credentials"}]
    admin groups: [{"name":"credentials-admin","permission":"admin"}]

The same two requests against this recipe's deploy:

    POST /api/trpc/user.initUser -> 403 {"message":"Step denied","code":"FORBIDDEN"}
    users: [{"name":"owner","provider":"credentials"}]

## What the recipe does

**It runs upstream's published image and does not build the checkout.** The one
thing taken from the checkout is `version` in `package.json`, which
`hooks/prepare.sh` turns into `ghcr.io/homarr-labs/homarr:v<version>` (with a
pinned fallback if that tag is not published). Stated plainly: after that one
field has been read, this checkout contributes nothing — not the compose file,
not the runtime, not a byte of the image. The same choice the Seafile and
Zabbix recipes make.

**The installer is closed before the application starts.** Tandoor's shape:

    init  (one-shot, root)  migrations -> owner admin -> onboarding = finish
      └ app  (depends_on init: service_completed_successfully)
          └ ready  (depends_on app: service_healthy, exits 0)

`init` failing is a failed `up` and a failed deploy, not an open site — tested
by breaking it (see below). `ready` exists because `docker compose up -d` runs
without `--wait` (engine#204) and the deploy is declared finished the moment it
returns (DeploymentWorkflow.php:73).

**Data lives in `~/.panelalpha/homarr/`.** `/appdata` is `VOLUME` in upstream's
Dockerfile, so a stock deploy gets a named volume nothing in the product can
reach into — and a Homarr board is the whole product. It is replaced by-target
with a bind mount, and `PUID`/`PGID` in the generated
`docker-compose.override.yml` make the image chown everything under it to the
account, so the customer can back it up and read it over SFTP.

## What an anonymous visitor gets

A login page. The migrations seed a board called `dashboard` and make it the
home board of the `everyone` group, but they leave the *server* setting
`board.homeBoardId` null — and `getHomeIdBoardAsync`
(packages/api/src/router/board.ts:1579-1583) reads the everyone group only for
a signed-in user; for `!user` it reads the server setting. Null is NOT_FOUND is
`redirect("/auth/login")` (boards/_layout-creator.tsx:69-73). The recipe leaves
it null on purpose.

Measured on the finished deploy, with a private board `internal` holding an app
tile whose URL is `https://nas.internal.example/backups?token=SECRET-TILE-TOKEN`:

    anonymous  /                              200  242070 b   login page, 0 hits
    anonymous  /boards/internal               200  247029 b   login page, 0 hits
    anonymous  /manage, /manage/users         200             login page, 0 hits
    anonymous  /api/trpc/board.getHomeBoard   404
    anonymous  /api/trpc/board.getBoardByName 404  (NOT_FOUND, not FORBIDDEN:
                                                    board existence is not leaked)
    anonymous  /api/trpc/app.all              401
    anonymous  /api/boards                    200  "[]"
    owner      /                              200  293882 b   2 hits
    owner      /boards/internal               200  298876 b   2 hits

"hits" is occurrences of `nas.internal.example` or `SECRET-TILE-TOKEN` in the
body. `/api/health/live` and `/api/health/ready` are unauthenticated: `ready` is
an empty 200, `live` reports whether the database and Redis are up and nothing
else.

## Exposure

`/.git/config`, `/.git/HEAD`, `/docker-compose.yml`,
`/docker-compose.override.yml`, `/.env`, `/.env.example`, `/package.json`,
`/pnpm-lock.yaml`, `/Dockerfile`, `/nginx.conf`, `/node_modules/next/package.json`,
`/panelalpha/homarr/init.sh`, `/panelalpha/homarr/bootstrap.mjs`,
`/appdata/db/db.sqlite`, `/db.sqlite`, `/secrets/admin-password`,
`/secrets/secret-encryption-key`, `/apps/nextjs/server.js` — every one is 404
and every body is one of Homarr's own two 404 pages, compared byte-wise. None
contains any file content. The vhost is a pure proxy; nothing in `~/project` is
web-reachable.

## Redeploy

`POST /projects/<user>/rebuild`, 21 s. `init` took one second and said
"a credentials administrator already exists; leaving it alone" and "onboarding
was already finished". Byte-identical by md5 afterwards: `admin-password`,
`secret-encryption-key`, `README.panelalpha.md`. Unchanged in the database: the
user, both boards and their visibility, the `Backups NAS` app with its URL and
token, both items on `internal` (the app tile and the clock widget), the
onboarding step, and the owner's password hash. The owner's *session cookie*
still worked, so the `session` table survived too. The board and both items
were confirmed rendering in a browser over the public HTTPS domain after the
rebuild.

## The gate, tested by breaking it

With `bootstrap.mjs` forced to `process.exit(1)`:

    init container         Exited (1)
    app container          Created   (never started)
    POST .../rebuild       HTTP 500
    deploy log status      failed
    the account's site     502

Reverted, rebuilt, 28 s, 200.

## Numbers

|  | verdict | serving | HTTP | time |
|---|---|---|---|---|
| Control (no recipe), 12 GB account | `serving-error_page` | `error_page` | 502 | 484 s |
| Control, 7.5 GB account | `deploy-failed` | — | — | OOM in `pnpm turbo build` |
| Control, 3 GB account | `deploy-failed` | — | — | OOM in `pnpm turbo build` |
| Recipe, fresh account #1 (2 GB) | **`deploy-ok`** | **`ok`** | 200 | 46 s |
| Recipe, fresh account #2 (2 GB) | `deploy-ok` | `ok` | 200 | 51 s |
| Redeploy (warm) | success | `ok` | 200 | **21 s** |

`healthy: true`, seven baseline checks, zero failing. Memory: app container
285 MiB of a 1 GiB cap; whole account 805 MiB of 2048 MiB.

## Left undone

* No `overrides/app.sh`. Homarr has a user store and a CLI that can list, delete
  and re-password users, so `users:*` is writable — but the CLI never exits, so
  every call would need the watch-the-database-and-kill treatment
  `bootstrap.mjs` uses, and SSO would mean minting a NextAuth v5 session, which
  was not attempted.
* **No integration was created**, so the claim that a stable
  `SECRET_ENCRYPTION_KEY` keeps stored credentials readable across a redeploy is
  supported only by the key file being byte-identical by md5, not by decrypting
  a real secret. `integration.create` tests the connection before saving
  (`Unable to connect to the integration … ENOTFOUND`), and there was no
  internal service on the test host to point one at.
* No media upload, so the multipart path (engine#170) was not exercised here.
* No custom domain; no OIDC or LDAP provider (both need an IdP an account does
  not have).
