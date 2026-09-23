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

Secrets (generated in `hooks/prepare.sh` into `.env`, which compose
interpolates into both services):

- `MYSQL_PASSWORD` — read by the database image and by Ghost, one value.
- `MYSQL_ROOT_PASSWORD` — used by the healthcheck `mysqladmin ping`.

The hook only writes `.env` when there is none. The MySQL data volume outlives
the checkout, so a regenerated password on a redeploy would lock Ghost out of
its own database.

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

## Not configured

Mail. Ghost boots and the owner-setup flow at `/ghost/` completes without it,
but staff invites, member signups and newsletters need an SMTP service the
engine does not provide. Set `mail__*` through the account's env vars to add
one.
