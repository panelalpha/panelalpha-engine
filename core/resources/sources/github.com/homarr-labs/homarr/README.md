# homarr-labs/homarr

Homarr — a self-hosted dashboard: boards of draggable tiles, widgets that poll
other applications, and integrations that hold API keys for them. Apache-2.0,
a pnpm/turbo monorepo that builds one Next.js standalone server.

## What the repository is, and what goes wrong without this recipe

The repository ships no compose file. Detection reads the root `Dockerfile` and
the `dockerfile` strategy builds the monorepo. Three things then go wrong.

### 1. The build is enormous, and on a normal account it does not finish

Most of the build is one `pnpm turbo build` layer, and within an ordinary
account's memory limit that layer dies:

    failed to solve: ResourceExhausted: process "/bin/sh -c
    TURBO_PLATFORM="${TARGETPLATFORM:-linux/amd64}" pnpm turbo build
    --filter=@homarr/nextjs... --filter=@homarr/cli"
    did not complete successfully: cannot allocate memory

and the deploy fails outright. And because the engine's generated
`docker-compose.yml` sits in the build context and changes every redeploy, a `dockerfile` project pays that build again on every rebuild.

### 2. The 502 is an engine bug, not an application bug

This is the interesting one, because nothing in Homarr's own logs points at it.

`DeployCompose::dockerfile()` writes `env_file: ['.env']` into every generated
compose file (DeployCompose.php:107), and `EnvExampleCopies` creates that `.env`
by copying the repository's `.env.example` (EnvExampleCopies.php:12-18,73).
A compose `env_file` beats an image's own `ENV`, so the running container gets:

    DB_URL=FULL_PATH_TO_YOUR_SQLITE_DB_FILE
    SECRET_ENCRYPTION_KEY=0000000000000000000000000000000000000000000000000000000000000000

overriding the image's `ENV DB_URL='/appdata/db/db.sqlite'`. The database path
becomes a relative filename. `scripts/run.sh` runs the migrations from `/app`
and they succeed — "Migration complete", the seeded board, the seeded groups —
writing `/app/FULL_PATH_TO_YOUR_SQLITE_DB_FILE`. Next.js's standalone
`server.js` then does `process.chdir(__dirname)`, so the server opens the same
relative name from `/app/apps/nextjs` — an empty database:

    SqliteError: no such table: session
    SqliteError: no such table: cron_job_configuration
    Next.js exited with code 1, restarting in 1s...

`run.sh` starts nginx before node and restarts node in a `while true` loop, so
port 7575 answers within a second of the container starting and answers 502 for
as long as the container lives. **Not a boot race**: the Next.js process never
comes up, so the 502 never clears — and it is 502 from inside the container
too, so it is not the proxy.

The second line of that `.env` is the quieter half. `0000…0` is 64 valid hex
characters, so it passes `SECRET_ENCRYPTION_KEY`'s validation
(packages/common/env.ts:13-25) and the server would start with it. A deploy
that got past the database would encrypt every integration API key under a key
published in upstream's repository.

### 3. The first visitor owns the instance

Homarr does authenticate — `AUTH_PROVIDERS` defaults to `credentials`, there is
a real user table with bcrypt hashes and group permissions, and **no provider
trusts any proxy-supplied identity header**: there is no `Remote-User`,
`X-Forwarded-User` or forward-auth path anywhere in the tree. (`trustHost: true`
at packages/auth/configuration.ts:61 is NextAuth's callback-URL host, not an
identity.)

What it has is the first-visitor problem, in its strongest form.
`onboardingProcedure` (packages/api/src/trpc.ts:169-183) is `publicProcedure`
with one check — "is the database's current step the step this call belongs
to?" — and `user.initUser` (packages/api/src/router/user.ts:50) creates a user,
creates the `credentials-admin` group, grants it `admin` and joins the user to
it. The migrations seed `onboarding` with step `start`.

So against a stock Homarr two unauthenticated POSTs — `onboard.nextStep`, then
`user.initUser` with a username and password of the caller's choosing — create
an administrator. Against this recipe's deploy `user.initUser` answers
`403 Step denied`, because onboarding is already finished.

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

`init` failing is a failed `up` and a failed deploy, not an open site. `ready`
exists because `docker compose up -d` runs
without `--wait` and the deploy is declared finished the moment it
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
it null on purpose. A private board, and the app tiles on it, are shown only
to a signed-in user.

`/api/health/live` and `/api/health/ready` are unauthenticated: `ready` is
an empty 200, `live` reports whether the database and Redis are up and nothing
else.

## Exposure

`/.git/config`, `/.git/HEAD`, `/docker-compose.yml`,
`/docker-compose.override.yml`, `/.env`, `/.env.example`, `/package.json`,
`/pnpm-lock.yaml`, `/Dockerfile`, `/nginx.conf`, `/node_modules/next/package.json`,
`/panelalpha/homarr/init.sh`, `/panelalpha/homarr/bootstrap.mjs`,
`/appdata/db/db.sqlite`, `/db.sqlite`, `/secrets/admin-password`,
`/secrets/secret-encryption-key`, `/apps/nextjs/server.js` — every one is a
404 from Homarr itself, with no file content. The vhost is a pure proxy; nothing in `~/project` is
web-reachable.

## Redeploy

Everything lives in `~/.panelalpha/homarr/`, which a redeploy does not touch.
`init` finds the existing administrator and the finished onboarding and leaves
both alone.

## Known limits

* No `overrides/app.sh`. Homarr has a user store and a CLI that can list, delete
  and re-password users, but the CLI never exits, so every call would need the
  watch-the-database-and-kill treatment `bootstrap.mjs` uses, and SSO would
  mean minting a NextAuth v5 session.
* No OIDC or LDAP provider (both need an IdP an account does not have).
