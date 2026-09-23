# NocoDB (github.com/nocodb/nocodb)

An Airtable alternative. A NestJS server that serves the REST API and a Vue
dashboard on :8080 over a metadata database, with SQLite as its default.

Detection: `railpack` — and that is the failure. The repository root is a
10-package pnpm workspace (`nc-gui`, `nocodb`, `nocodb-sdk`, …) whose
`package.json` has no `start` script, and its only compose files live under
`docker-compose/` (an installer's golden test fixtures and `examples/` for
external Postgres, Redis and Traefik) plus `.github/uffizzi`, names Docker
never auto-loads. Railpack installed the workspace, printed `No start command
detected`, generated a basic compose around `nginx:alpine`, and the deploy
"finished successfully" with the account serving PanelAlpha's placeholder page
on 8080.

Building the checkout is not the alternative, and unusually this is not a
judgement call: **the repository contains no Dockerfile at all** — not at the
root, not under `packages/`, not anywhere. `git ls-tree -r` finds no
`Dockerfile` and no `.dockerignore`. The image is built outside this tree and
published as `nocodb/nocodb`, and upstream's README leads with one container of
it. So the recipe runs that.

## The stack

`overrides/docker-compose.yml`, which becomes the project's compose file.

- **nocodb** — `nocodb/nocodb:<version>`, published on 8080, everything
  stateful on a named volume at `/usr/app/data`.
- **setup** — a one-shot that closes open registration. See *Registration*.
- **ready** — a no-op that exits 0. See *Readiness*.

No database sidecar. With `NC_DB` unset NocoDB keeps its metadata in a `sqlite3`
file under `NC_APP_DATA_DIR` (`utils/nc-config/NcConfig.ts`, `helpers.ts`),
which is the configuration upstream's README leads with, and the one volume
then holds the meta database, attachments and thumbnails together. The Postgres
and Redis in `docker-compose/examples/` belong to the scaled shape: Redis is
only *required* when `NC_WORKER_CONTAINER` is set — `Noco.ts` throws
`NC_REDIS_URL is required` in that branch and nowhere else — and the worker
exists to move jobs off the web process, which one account does not need.
Without Redis, `NocoCache` is in-memory and jobs run through
`modules/jobs/fallback`. A Postgres sidecar is worth adding for an account that
will hold real volume or want point-in-time backups: set `NC_DB` to
`pg://db:5432?u=…&p=…&d=…` and add the service; nothing else in the recipe
changes.

The image tag comes from the checkout rather than from a constant, but it is
not derived the way Ghost's or LinkAce's is: `nocodb/nocodb` publishes
`:latest` and one tag per release, and the product is still 0.x, so there is no
major-branch tag to pin to. `hooks/prepare.sh` reads
`packages/nocodb/package.json` (`"version": "0.301.3"`) and then **confirms
that tag exists on Docker Hub** before using it, because `develop` carries the
next version, which may not be published yet. Anything unreadable, unpublished
or unreachable falls back to `:latest` — a tag whose pull fails takes the whole
deploy with it, and `:latest` at least runs.

Secrets, generated in `hooks/prepare.sh` into `.env`, which compose
interpolates:

- `NC_ADMIN_EMAIL` / `NC_ADMIN_PASSWORD` — the super admin. See below.
- `NC_AUTH_JWT_SECRET` — 32 random bytes. `Noco.ts` persists a `uuidv4()` into
  `nc_store` when this is unset, which works but is 122 bits and lives only in
  the database.
- `NC_CONNECTION_ENCRYPT_KEY` — with this set, `encryptDecrypt.ts` AES-encrypts
  the credentials of every external data source the customer connects; without
  it they are plaintext JSON in `nc_sources`. The helm chart's own words are
  "never rotated", so this is set from the first boot or never.

The hook writes `.env` only when there is none: the data volume outlives the
checkout, and a regenerated JWT secret would log everyone out, a regenerated
encryption key would make stored data-source credentials undecryptable, and a
regenerated admin password would be one nobody was ever told.

## `NC_SITE_URL`

The value the recipe cannot generate. NocoDB builds invitation links, password
resets, webhook callbacks and OAuth redirects from it, and
`services/mail/mail.service.ts` refuses to send anything at all when it is
unset — it mails the admin "NC_SITE_URL is not configured" instead. It has to
be the account's public address, which is not known until the domain exists.

So the compose file ships `NC_SITE_URL: http://localhost`, which is exactly the
shape `ComposePlaceholders::isLocalPublicUrl()` rewrites: the key matches
`/(^|_)(URL|ORIGIN|ENDPOINT|DOMAIN)$/i`, the value is a bare localhost whose
implied port 80 is on the allowlist, and the key is not one of the datastore
names (`REDIS_`, `DATABASE_`, …) the rewrite skips. `UserComposeStrategy`
substitutes the account's `https://…` URL before the stack starts, and the
deploy log says so: *"Pointed the application address at its public URL:
NC_SITE_URL"*.

NocoDB does not validate `Host`, so the loopback probe's `Host: 127.0.0.1:8080`
is not the engine#165 problem. `main.ts` enables `trust proxy`; the only
host-based gate in the tree is `run/cloud.ts`, which self-hosted never loads.

## The admin, and registration

NocoDB has no installer and no setup wizard. `services/users/users.service.ts`
gives the **first account to sign up** `org-level-creator,super` — on a public
HTTPS name, the first stranger who finds it.

`helpers/initAdminFromEnv.ts` is the way out: with `NC_ADMIN_EMAIL` and
`NC_ADMIN_PASSWORD` set, NocoDB creates the super admin itself on first boot
(and refuses to start at all if the email is set and the password is empty).
`hooks/prepare.sh` generates a 20-character password per account into
`~/project/.panelalpha-admin-password` (0600) — never a fixed default. NocoDB's
own rule is only "at least 8 letters" (`nocodb-sdk/passwordHelpers.ts`).

**Creating the admin does not close the door behind it.**
`DEFAULT_APP_SETTINGS.invite_only_signup` is `false`
(`interface/AppSettings.ts`), and `users.service.ts` only refuses a signup when
that flag is on. On a fresh instance anyone who reaches the account's URL can
`POST /api/v1/auth/user/signup`, land as an org-level **viewer** and be added
to the default workspace — where the default base lives. That is less than
Koillection's open first-signup, but it is still open.

The setting lives in `nc_store`, not in the environment: there is no env var
for it, and `POST /api/v1/app-settings` as the super admin is the only way to
change it. So `files/panelalpha-setup.cjs` signs in with the generated
credentials (`POST /api/v1/auth/user/signin`, token read back through the
`xc-auth` header, per `providers/jwt-strategy.provider.ts`) and posts
`{"invite_only_signup": true}`. `Noco.updateAppSettings` merges, so that one
key is enough.

Three properties shaped it:

- **It runs once.** The marker is `/usr/app/data/.panelalpha-signup-closed` on
  the data volume, so an operator who later re-opens registration in Team &
  Settings keeps their choice across the next redeploy.
- **It runs beside NocoDB, not inside it.** The admin already exists by the
  time NocoDB is healthy, so unlike Wiki.js's finalizer there is nothing to
  race — and the readiness gate below waits for this service to finish.
- **It always exits 0.** `ready` depends on `setup` *completing successfully*,
  so a non-zero exit would fail `docker compose up -d` and take a working
  deploy with it. Every failure path logs and returns.

## Readiness

`AppLauncher` runs `docker compose up -d` without `--wait`, and the deploy is
finished when that returns — which is when the container has been *started*,
not when it answers. NocoDB's first boot runs its whole meta migration set
against an empty sqlite file.

Compose does block on `depends_on` conditions, so `ready` waits on
`setup: service_completed_successfully`, and `setup` in turn waits on
`nocodb: service_healthy`. `up -d` therefore returns only once the site answers
*and* registration is closed. A clean exit 0 is explicitly not a crash loop to
`AppHealth::isCrashing()`, so neither finished container shows up as failing.

The healthcheck is upstream's own from `docker-compose/examples/`, on
127.0.0.1: `wget --spider /api/v1/health`. That route has no guard in
`controllers/utils.controller.ts`, and the image has busybox `wget` but no
`curl`.

Observed on `mariusz`: preparing 14s, cloning 11s, running 51s (image pull,
boot, `setup`, `ready`) — 76s by the engine's own timings, 90.6s as the test
harness measures it end to end; 60.3s on a second account once the image was in
the host cache. NocoDB reached healthy **11 seconds** after its container
started, and `setup` and `ready` had both exited 0 five seconds after that.
Verdict `deploy-ok`, `serving: ok`, every baseline check passing,
`https://<domain>/` → 200; the health report's own fetch of the bare path
records a 302, which is NocoDB redirecting `/` to `/dashboard`.

## Verified beyond the status code

All of the following against the account's real HTTPS domain, not the loopback
probe:

- `GET /` 302s to `/dashboard`, which 301s to `/dashboard/` and serves 19 KB of
  the real SPA with a 200.
- `POST /api/v1/auth/user/signin` with the credentials from
  `.panelalpha-admin-password` returns a JWT. A wrong password returns 400, so
  the 200 is not a page that would greet anyone.
- `GET /api/v1/app-settings` with that token returns
  `{"invite_only_signup":true,…}`, and `POST /api/v1/auth/user/signup` for a
  fresh address is refused with *"Not allowed to signup, contact super
  admin."* — the open-registration hole is closed, not merely reported.
- `POST /api/v2/meta/bases` as the admin creates a base and returns its id, so
  the SQLite meta database is written to, not just read.
- `GET /api/v1/meta/nocodb/info` reports
  `ncSiteUrl: https://<account>.panelalpha.online` — the placeholder rewrite
  reached the running app, not only the compose file.
- `docker stats` with the site up: 295 MiB of the 1.5 GiB limit.

## Not configured

Mail. NocoDB boots, the admin signs in and bases can be built without it, but
invitations, password resets and email notifications need an SMTP server the
engine does not provide — and `mail.service.ts` will not send at all until
`NC_SMTP_HOST` and its companions are set through the account's env vars.
