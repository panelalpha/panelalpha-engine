# Wiki.js (github.com/requarks/wiki)

A wiki. A Node server that renders pages and serves its Vue admin SPA on
:3000, backed by PostgreSQL.

Detection: `express`, Node 20 — and that is the failure. The repository is the
source tree, and `package.json` does say `"start": "node server"`, so nothing
about the guess is unreasonable. It just cannot run:

- `assets/` is webpack output and is gitignored. The pug views render against
  a bundle that is not in the checkout.
- There is no `config.yml`. The repo ships `config.sample.yml` (PostgreSQL on
  `localhost`, password `wikijsrocks`), and `server/core/config.js` prints
  `>>> Unable to read configuration file! Did you create the config.yml file?`
  and `process.exit(1)` when the file is missing.

So the container exited on every boot and restart-looped; nothing ever bound
3000 and the probe returned `serving: unknown` after a deploy that had already
spent 248s compiling on the host.

Building the repository is not the alternative either. `dev/build/Dockerfile`
is not at the root — detection would never pick it — and it is a two-stage
`node:24-alpine` build: a full `yarn --frozen-lockfile`, a webpack production
build, `rm -rf node_modules`, then a second production-only install. Minutes of
build and a gigabyte of layers per account, to arrive at an image upstream
already publishes. The recipe runs the published one.

## The stack

`overrides/docker-compose.yml`, which becomes the project's compose file (the
repo ships none Docker would load: `dev/examples/docker-compose.yml` is an
illustration from the docs and `dev/containers/docker-compose.yml` is a
devcontainer).

- **wiki** — `ghcr.io/requarks/wiki:2`, published on 3000, content on a named
  volume at `/wiki/data/content` (the one path the image declares as a
  `VOLUME`).
- **db** — `postgres:15-alpine`. Not a preference: Wiki.js 2.5 dropped SQLite,
  and upstream's example compose, Helm chart and docs are all Postgres.
  `sqlite3` is still in `package.json` and `dev/build/Dockerfile` still
  `apk add`s sqlite, which is misleading — `db.type: sqlite` is not a
  supported configuration any more.
- **ready** — a no-op that exits 0. See *Readiness* below.

The image tag is pinned to the major rather than derived from the checkout.
`package.json` on `main` carries `"version": "2.0.0"` and
`"releaseDate": "2026-01-01T01:01:01.000Z"` — placeholders CI substitutes at
release time — so the checkout genuinely cannot say which 2.5.x it is. `2` is
the tag upstream's own example compose uses.

Secrets, generated in `hooks/prepare.sh` into `.env`, which compose
interpolates:

- `POSTGRES_PASSWORD` — read by the database and by Wiki.js, one value.
- `WIKI_ADMIN_EMAIL` / `WIKI_ADMIN_PASSWORD` — the root administrator the setup
  finalizer creates. Written once: the volume outlives the checkout, and a
  regenerated password would be one the `users` table never learns.

Wiki.js generates its own `sessionSecret` and RSA keypair during `/finalize`
and keeps them in the `settings` table, so there is nothing else for the hook
to write.

## The setup wizard

The part of this app the engine cannot infer anything about.

A fresh Wiki.js has no admin and no site URL. `server/core/config.js` reads one
YAML file and merges the rest from the `settings` table; that table is empty,
so `WIKI.config.setup` is true and `kernel.js` boots `server/setup.js` instead
of the application. The account's domain then serves a form whose `siteUrl`
field defaults to `https://wiki.yourdomain.com`.

There is no way around it from outside the app. Wiki.js does **not** read
arbitrary environment variables: the image's `config.yml`
(`dev/build/config.yml`) is a template of `$(VAR)` placeholders covering the
database and TLS and nothing else, and every other setting lives in the
database. No CLI, no seed, no `--admin-password` flag.

So `files/panelalpha-setup.sh` completes the wizard the way a browser would:
`POST /finalize` with a JSON body of `adminEmail`, `adminPassword`,
`adminPasswordConfirm`, `siteUrl` and `telemetry`. That route has no CSRF
token, no session and no other guard, because it belongs to an application that
is by definition not yet configured. It writes the admin user, the guest
account, the Administrators and Guests groups, the default English locale, the
session secret, the RSA keypair, the navigation and `host`, then tears its own
HTTP server down and reboots into master mode.

Two properties of that route shaped the script:

- **It only exists in setup mode.** Once `WIKI.config.setup` is false,
  `master.js` mounts a different Express app and `POST /finalize` 404s. That is
  what makes the script safe to re-run — and it has to be safe, because
  `/finalize`'s own error path runs `knex('settings').truncate()`. The script
  checks first: `GET /healthz` answers `{"ok":true}` in master mode and the
  wizard's HTML in setup mode (the setup app answers `app.get('*')` with the
  setup page), which is the one question that tells the two apart.
- **The site URL comes from the request body**, not the environment. The
  compose file ships `WIKI_SITE_URL: http://localhost` — the shape
  `ComposePlaceholders::isLocalPublicUrl()` rewrites: the key ends in `_URL`
  and the value is a bare localhost on the implied port 80. The account's
  `https://…` address is substituted before the stack starts, and the script
  posts it as `siteUrl`. Wiki.js stores it as `config.host` and builds
  canonical URLs, password-reset links, invitation links and the `secure` flag
  on its JWT cookie from it.

`host` being wrong is not fatal — pages still render — but every link out of
the wiki would point at `wiki.yourdomain.com`.

## Readiness

`AppLauncher` runs `docker compose up -d` without `--wait`, and the deploy is
finished when that returns. For Wiki.js that is well before it answers: first
boot runs the knex migrations against an empty database, comes up in setup
mode, gets finalized, and then restarts its HTTP server.

The gate is `ready`: `depends_on: wiki: condition: service_healthy`,
`entrypoint: exit 0`, `restart: "no"`, on the database's image so nothing extra
is pulled. A clean exit 0 is explicitly not a crash loop to
`AppHealth::isCrashing()`.

Two details that are specific to this app:

- **The finalizer runs inside the wiki service, not beside it.** `up -d`
  returns as soon as a service has been *started*, so a sibling container still
  posting to `/finalize` would race the health probe exactly the way the
  unguarded deploy did. Backgrounded next to the server
  (`bash /wiki/panelalpha-setup.sh & exec node --no-deprecation server`), it is
  inside the thing the gate waits on.
- **The healthcheck asks two questions.** `curl /healthz` alone returns 200 in
  setup mode as well, so it would call an unconfigured wiki healthy and open
  the gate in the middle of the finalize. The second condition is
  `/wiki/data/.panelalpha-ready`, a marker the finalizer writes once the wiki is
  configured and answering again — **or** once it has given up. That bound
  matters: without it, a finalize that failed would leave a health condition
  that is never true and `up -d` would hang until compose gave up on the
  dependency. With it, a failed finalize still ends with the wizard served.

Observed: db healthy ~6s after start, Wiki.js listening in setup mode ~10s
later, `/finalize` answered `{"ok":true}` in 2s, master mode answering 3s after
that, `ready` exited 0 and the whole deploy took 60s. Resident memory once up
is ~140 MB for the wiki and ~38 MB for the database, so the limits above are
headroom rather than a fit.

A restart re-runs the finalizer, which logs `already configured, nothing to do`
and writes the marker without touching `/finalize`.

## Not configured

Mail. Wiki.js boots, the administrator logs in and pages can be written without
it, but invitations, password resets and comment notifications need an SMTP
server the engine does not provide. Administration → Mail.
