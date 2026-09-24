# Ghost (github.com/TryGhost/Ghost)

Publishing platform. The server is `ghost/core`, a Node app that serves both
the public site and the admin SPA on :2368, backed by MySQL.

Detection: `railpack` — and that is the failure. The repository root is the
Ghost monorepo: a pnpm 12 / nx workspace with no `start` script, and the only
compose files are `compose.dev.yaml` and its `compose.dev.*.yaml` companions,
names Docker never auto-loads. Railpack built all 38 nx targets in ten minutes,
then logged `No start command detected`; the container exited 0 on every boot
and restart-looped, so nothing ever answered.

There is no production image to build in its place either.
`Dockerfile.production` ships two shippable targets: `core` (server and
production deps, **no admin** — the Ghost-Pro base) and `full` (core plus the
built admin, "self-hosting"). `full` ends in

```
COPY --chown=nobody:nogroup ghost/core/core/built/admin core/built/admin
```

and the file's own comment says CI injects that path from a separate admin
build job and that "local `full` builds must populate that path first". A fresh
checkout does not have it, so `full` fails to build; `core` builds but serves no
`/ghost/` admin, which means the site could never be set up. Ghost's published
image is the self-hosting artifact, so the recipe runs that.

`overrides/docker-compose.yml` is therefore the stack:

- **ghost** — `ghost:<major>-alpine`, the major read by `hooks/prepare.sh` out
  of the checkout's own `ghost/core/package.json`, so a clone of a 6.x branch
  gets a 6.x image rather than whatever `latest` is today. Content on a named
  volume at `/var/lib/ghost/content`.
- **mysql** — `mysql:8.4`, the version Ghost's own `compose.dev.yaml` develops
  against. Not optional: `ghost/core/core/shared/config/env/config.production.json`
  pins `database.client` to `mysql` pointed at `127.0.0.1` as root with an
  empty password, and there is no sqlite fallback under `NODE_ENV=production`.
  Without the sidecar Ghost does not boot.
- **ready** — a no-op that exits 0. See *Readiness* below.
- **init** — a one-shot that completes Ghost's owner setup before `ghost`
  starts. See *Owner setup* below.

Secrets, generated once by `hooks/prepare.sh` into `~/.panelalpha/ghost/` —
`~/project` is emptied on every deploy, `~/.panelalpha` is not:

- `database.env` — `MYSQL_PASSWORD` (read by the database image and by Ghost,
  one value) and `MYSQL_ROOT_PASSWORD` (the healthcheck's `mysqladmin ping`).
  Copied into `~/project/.env` on every deploy, which compose interpolates.
  The MySQL data volume outlives the checkout, so these must never change.
  Earlier versions of this recipe wrote them only to `~/project/.env`, which a
  redeploy deletes; on an account deployed that way the hook adopts them from
  the running MySQL container's environment instead of generating new ones.
- `owner.env` — the owner's email and password, read only by `init`.
- `credentials.txt` — the same, for the account owner to read.

## `url`

The one value the recipe cannot generate. Ghost builds every link, redirect and
canonical against `url`, which has to be the account's public address — not
known until the domain exists, and the prepare hook is not told it.

Ghost reads it as a **lowercase** `url` environment variable: its config is
nconf with `separator: '__'` and keys taken verbatim, so the engine's
`PublicUrlEnvironment` aliases (`URL`, `APP_URL`, `SITE_URL`, …) would not match
even if they were set — and they are only set by the dockerfile and ruby
strategies, not for a project's own compose file.

So the compose file ships `url: http://localhost`, which is precisely the shape
`ComposePlaceholders::isLocalPublicUrl()` rewrites: the key matches
`/(^|_)(URL|ORIGIN|ENDPOINT|DOMAIN)$/i`, and the value is a bare localhost whose
implied port 80 is on its allowlist. `UserComposeStrategy` replaces it with the
account's `https://…` URL before the stack starts, and the deploy log says so
("Pointed the application address at its public URL: url"). Note that Ghost's
own port, 2368, is *not* on that allowlist — `http://localhost:2368` would have
been left alone.

With an https `url`, a plain-HTTP request 301s to the canonical origin
(`url-redirects.js`: SSL site URL plus a non-secure request), so the loopback
health probe sees a 301 and the request through the proxy — which forwards
`X-Forwarded-Proto`, trusted because `trust proxy` is on unless
`usingLoopbackReverseProxy` is set — sees 200.

## Readiness

`AppLauncher` runs `docker compose up -d` without `--wait`, and the deploy is
finished when that returns. Compose returns once every service has been
*started*, which for Ghost is well before it answers: its first boot runs
knex-migrator against an empty database. The first run of this recipe deployed
successfully and the health probe, which runs immediately afterwards, still got
`serving: error_page` on a 503 — the containers were up and Ghost was still
migrating.

Compose does honour `depends_on: condition: service_healthy` during startup, so
the fix is a service that depends on Ghost being healthy and does nothing else.
That is `ready`: same image (nothing extra to pull), `entrypoint: exit 0`,
`restart: "no"`. `up -d` now blocks until Ghost's healthcheck passes. A clean
exit 0 is explicitly not a crash loop to `AppHealth::isCrashing()`, so the
finished container does not show up as a failing service.

Ghost's healthcheck is its own dev one from `compose.dev.yaml`: a
`redirect: 'manual'` fetch that passes on any status under 500, which is what
makes the 301 to the canonical https origin count as answering. Observed: mysql
healthy ~15s after start, Ghost healthy ~25s after that, whole deploy 135s
including a 38s image pull.

## Owner setup

Ghost's owner setup is an unauthenticated page at `/ghost/`
(`POST /ghost/api/admin/authentication/setup/`): until it has been submitted,
whoever submits it first owns the site. Measured on a stock deploy of this
recipe: `authentication/setup` answered `{"setup":[{"status":false}]}` on the
public URL (#263).

`init` closes it before anything publishes a port. It runs the Ghost image with
the same environment and content volume as `ghost`, and `ghost` depends on it
with `service_completed_successfully`, so `ghost` does not start until it has
exited 0. `files/panelalpha/ghost/init.sh`:

1. `claim.js check` reads the owner row the way `User.isSetup()` does (owner
   status not `inactive`). A site that already has an owner exits here: one
   SELECT, no Ghost boot. Any error (no tables yet) falls through to 2.
2. Boots Ghost with `server__host=127.0.0.1`, in a container that publishes
   no ports. The first boot runs the migrations.
3. `claim.js setup` waits for Ghost on loopback and submits the setup through
   Ghost's own endpoint, with `Host`, `X-Forwarded-Proto` and `Origin` set to
   the site's `url` so Ghost does not redirect the request away.
4. Stops Ghost. A setup that fails exits non-zero, `ghost` never starts, and
   the deploy fails rather than serving an unclaimed site.

The owner signs in at `/ghost/` with the email and password in
`~/.panelalpha/ghost/credentials.txt` (`owner@example.com`, to be changed to a
real address under Settings -> Staff).

**Staff device verification is off.** Ghost 6 mails a code to every staff
sign-in from a new browser (`security.staffDeviceVerification`, on in its
production config), and this recipe configures no mail. Measured with it on:
the owner's sign-in answered 500 `Failed to send email`, so the owner could
never get in. With mail configured, set `GHOST_STAFF_DEVICE_VERIFICATION=true`
in the account's env vars.

## Not configured

Mail. Ghost boots and signs its owner in without it, but staff invites, member
signups and newsletters need an SMTP service the engine does not provide. Set
`mail__*` through the account's env vars to add one.
