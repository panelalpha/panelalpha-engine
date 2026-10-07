# Mattermost (github.com/mattermost/mattermost)

Self-hosted team chat. One Go binary serves the REST API, the websocket and the
React webapp on :8065, over PostgreSQL.

Detection finds nothing. The repository root is the monorepo — `server/` (Go),
`webapp/` (an npm workspace), `e2e-tests/`, `api/`, `i18n/`, `tools/` — with no
compose file, no Dockerfile and no `package.json` in it, so without this recipe
a deploy falls through to the fallback strategy and serves PanelAlpha's
placeholder.

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
development branch and its major usually has no general release yet:
version.go can say `12.0.0` while Docker Hub has only `12.0.0-rc1` and a
`release-12` tag pointing at it. So `release-<major>` is used **only when a
plain `<major>.<minor>.<patch>` tag exists** for that major, and otherwise the
tag is `latest`.

## PostgreSQL

Not optional and not substitutable. MySQL support was removed in v10, there is
no embedded database, and without `MM_SQLSETTINGS_DATASOURCE` the server exits
on boot. The sidecar is `postgres:16-alpine` — 16 rather than the `18-alpine`
upstream's docker repo moved to, because 18 relocated `PGDATA` under
`/var/lib/postgresql/<ver>/docker` and the conventional
`/var/lib/postgresql/data` mount quietly stops being the data directory.
`POSTGRES_PASSWORD` is generated once by the prepare hook into
`~/.panelalpha/mattermost/db.env` (0600), which both services read by
`env_file:`: the data volume outlives the checkout, and `~/project/.env` is
emptied with it on every deploy. An account whose `.env` still holds the old
password has it carried over.

## The two things the engine cannot infer

Both are done by `files/panelalpha-setup.sh`, running in a one-shot `setup`
service once Mattermost answers.

**The system admin.** Mattermost has no installer and no admin environment
variables. With zero rows in `Users`, `POST /api/v4/users` needs no token
(`api4/user.go` permits it when `IsFirstUserAccount()`) and `app/user.go` gives
that account `system_admin`. On a public HTTPS name, that means the instance
belongs to the first stranger who opens it. The recipe makes that request
itself, with the login the engine generates (`credentials:` in
`panelalpha.yaml`, returned by `GET /projects/{name}/app-credentials`, MCP
`app_credentials_get`) — never a default. Doing so also
closes the door: from the second account on the endpoint answers 403
`api.user.create_user.no_open_server`, because `TeamSettings.EnableOpenServer`
is false by default.

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

## Not configured

**Mail.** The admin signs in and teams, channels and posts work without it, but
invitations, password resets and notification email need an SMTP server the
engine does not provide. Set `MM_EMAILSETTINGS_SMTPSERVER` and its companions
through the account's env vars, or from the System Console.

**Nothing about websockets** — but a note, because it looks like a bug and is
not one of this recipe's. The shared `*.panelalpha.online` test-domain front
forwards `Connection: upgrade` **without** `Upgrade: websocket`, so through
such a name `GET /api/v4/websocket` answers 400 with Mattermost's misleading
"URL Blocked because of CORS" (gorilla's generic handshake error carries an
empty `BlockedOrigin`) and the UI shows a permanent "connection lost" banner.
The engine's own vhost sets `proxy_http_version 1.1` and both upgrade headers,
so a customer on a real domain is unaffected.
