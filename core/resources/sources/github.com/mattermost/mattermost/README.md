# Mattermost (github.com/mattermost/mattermost)

Self-hosted team chat. One Go binary serves the REST API, the websocket and the
React webapp on :8065, over PostgreSQL.

Detection: nothing. The repository root is the monorepo — `server/` (Go),
`webapp/` (an npm workspace), `e2e-tests/`, `api/`, `i18n/`, `tools/` — with no
compose file, no Dockerfile and no `package.json` in it. The deploy fell
through to the fallback strategy, which generated an `nginx:alpine` compose on
:8080 and served PanelAlpha's placeholder: verdict `serving-placeholder`,
strategy `fallback`, platform `null`.

## The checkout is not buildable, and upstream says so

`server/build/Dockerfile` is the repository's only production image recipe, and
its entire build stage is

```
ARG MM_PACKAGE="https://latest.mattermost.com/mattermost-enterprise-linux"
RUN ... curl -L $MM_PACKAGE | tar -xvz ...
```

followed by a distroless final stage that copies `/mattermost` out of it. It
downloads a finished release; nothing in it compiles the tree it sits in.
Building the tree properly means a Go toolchain, the webapp's npm workspace and
`make build-cmd dist` — minutes of build, on a 2.5 GB account, for a binary
that is already published under `mattermost/mattermost-team-edition`. So
`overrides/docker-compose.yml` runs that image, which is what upstream's own
deployment repository (`mattermost/docker`) does.

The repository's only compose files are `server/docker-compose.yaml` and its
`build/docker-compose.*.yml` companions: a developer's dependency rig
(postgres, minio, inbucket, openldap, elasticsearch, opensearch, redis, dejavu,
keycloak, prometheus, grafana) one directory below the root, without Mattermost
in it. Out of `RuntimeSidecars`' reach — its glob is the project root only —
but worth knowing about before anyone moves a file.

### Image tag

`hooks/prepare.sh` reads `server/public/model/version.go`, whose release list is
newest-first, and takes the major from the first entry. But master is the
development branch and its major usually has no general release yet: today
version.go says `12.0.0` and Docker Hub has only `12.0.0-rc1` and a `release-12`
tag pointing at it. So `release-<major>` is used **only when a plain
`<major>.<minor>.<patch>` tag exists** for that major, and otherwise the tag is
`latest`. Checked against the registry: major 10 and 11 resolve to `release-10`
and `release-11`; master resolves to `latest`, which deployed 11.11.0.

## PostgreSQL

Not optional and not substitutable. MySQL support was removed in v10, there is
no embedded database, and without `MM_SQLSETTINGS_DATASOURCE` the server exits
on boot. The sidecar is `postgres:16-alpine` — 16 rather than the `18-alpine`
upstream's docker repo moved to, because 18 relocated `PGDATA` under
`/var/lib/postgresql/<ver>/docker` and the conventional
`/var/lib/postgresql/data` mount quietly stops being the data directory.
`POSTGRES_PASSWORD` is generated into `.env` by the prepare hook, which writes
the file only when there is none: the data volume outlives the checkout.

## The two things the engine cannot infer

Both are done by `files/panelalpha-setup.sh`, running in a one-shot `setup`
service once Mattermost answers.

**The system admin.** Mattermost has no installer and no admin environment
variables. With zero rows in `Users`, `POST /api/v4/users` needs no token
(`api4/user.go` permits it when `IsFirstUserAccount()`) and `app/user.go` gives
that account `system_admin`. On a public HTTPS name, that means the instance
belongs to the first stranger who opens it. The recipe makes that request
itself, with a 20-character password generated per account into
`~/project/.panelalpha-admin-password` (0600) — never a default. Doing so also
closes the door: from the second account on the endpoint answers 403
`api.user.create_user.no_open_server`, because `TeamSettings.EnableOpenServer`
is false by default. Verified by asking for a second account from outside.

**SiteURL.** Every permalink, invitation, password reset and OAuth redirect is
built from it, and it is not known until the account's domain exists, so the
prepare hook cannot write it. The environment variable that would carry it,
`MM_SERVICESETTINGS_SITEURL`, is *not* a name `ComposePlaceholders` recognises:
`PUBLIC_URL_KEY_PATTERN` is `/(^|_)(URL|ORIGIN|ENDPOINT|DOMAIN)$/i` and this key
ends in `SITEURL`, not `_URL`. Nor can a shell wrapper copy one name to the
other — the image is distroless, with no `/bin/sh` at all. So the `setup`
service carries `PA_PUBLIC_URL: http://localhost`, which *is* the shape the
engine rewrites (bare localhost, implied port 80, on its allowlist), signs in as
the admin it just made, and sets SiteURL through `PUT /api/v4/config/patch`.
Through the API rather than the environment on purpose: a setting supplied by an
env var is locked read-only in the System Console.

The same script then creates a `Main` team, so the first sign-in lands in a
channel rather than on the "create a team" screen. Best effort, and the script
**always exits 0** — `ready` depends on it completing successfully, so a
non-zero exit would fail `docker compose up -d` and take a working deploy with
it.

## Readiness

`AppLauncher` runs `docker compose up -d` without `--wait`, so the deploy is
"finished" when containers have *started*. `ready` waits for `setup` to exit,
which waits for Mattermost's healthcheck, so `up -d` returns only once the
server has answered, an admin exists, that admin has actually signed in and
SiteURL is set. A clean exit 0 is explicitly not a crash loop to
`AppHealth::isCrashing()`.

Two details are specific to this image being distroless:

- The healthcheck has to be the image's own — `mmctl system status --local`
  over the server's local-mode unix socket. There is no curl, no wget and no
  shell inside to probe HTTP with. (`MM_SERVICESETTINGS_ENABLELOCALMODE=true` is
  baked into the image.) The interval is tightened from the image's 30s.
- `ready` and `setup` run `curlimages/curl`, not the app's own image the way
  most of these recipes do. There is no `/bin/sh` in the Mattermost image to
  exit 0 from.

## Verified

`mattermost/mattermost` at `master`, on a 2500 MB account:

- deploy `completed` in 105s (warm image cache) and 181s (cold pull), verdict
  `deploy-ok`, `serving: ok`, all seven baseline checks pass
- `GET /` → 200, `<title>Mattermost</title>`, 698 KB of webapp
- the loopback probe the engine runs (`http://127.0.0.1:8065/`, `Host:
  127.0.0.1:8065`) → 200; Mattermost does not validate `Host`
- `POST /api/v4/users/login` over the public domain → 200, `roles: system_admin
  system_user`
- `SiteURL` in `/api/v4/config/client` is the account's `https://…` name
- `POST /api/v4/users` with no token → 403 "This server does not allow open
  signups"
- posting a message to `town-square` → 201
- resident memory: mattermost 205 MiB of 1.25 G, postgres 79 MiB of 384 M

## Not configured

**Mail.** The admin signs in and teams, channels and posts work without it, but
invitations, password resets and notification email need an SMTP server the
engine does not provide. Set `MM_EMAILSETTINGS_SMTPSERVER` and its companions
through the account's env vars, or from the System Console.

**Nothing about websockets** — but a note, because it looks like a bug and is
not one of this recipe's. Through the `*.panelalpha.online` test domain the
batch harness uses, `GET /api/v4/websocket` answers 400 with Mattermost's
misleading "URL Blocked because of CORS" (gorilla's generic handshake error
carries an empty `BlockedOrigin`). Capturing what actually reaches the container
shows `Connection: upgrade` arriving **without** `Upgrade: websocket`. That name
resolves to `eu1.withoutdns.com` (159.69.7.81), not to the engine host; the same
request sent straight at the engine with `--resolve` gets `101 Switching
Protocols`. The engine's own vhost sets `proxy_http_version 1.1` and both
upgrade headers, and works. It is the shared test-domain front that strips
`Upgrade`, so a customer on a real domain is unaffected — but anyone testing
Mattermost (or any websocket app) through a `*.panelalpha.online` name will see
a permanent "connection lost" banner in the UI and should not chase it into the
recipe.
