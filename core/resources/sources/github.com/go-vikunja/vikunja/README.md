# Vikunja (github.com/go-vikunja/vikunja)

To-do and project management. One Go binary that serves the REST API and the
Vue frontend embedded into it on `:3456`, with SQLite, MySQL or PostgreSQL
behind it.

Detection: `dockerfile` — and that part was right. The repository ships a root
`Dockerfile`, it is what upstream publishes as `vikunja/vikunja`, and it builds
from a fresh clone: a pnpm frontend build, then an xgo cross-compile of the
API, about eight and a half minutes and no cache. The recipe keeps building it.

The verdict was `serving-unknown`, and unlike Ghost or pretix it was not a slow
boot: `app (Restarting (1) 10 seconds ago)`. The container exited 1 on every
start and nothing ever listened on 3456.

## Why it exited

The final stage is `FROM scratch`. Three things Vikunja needs on its first boot
are outside what that Dockerfile can state, and the first of them is fatal
before anything else is even attempted.

**1. `cors.enable` + empty `service.publicurl`.** `config.InitConfig()` ends in

```go
if CorsEnable.GetBool() && ServicePublicURL.GetString() == "" {
    log.Fatalf("service.publicurl is required when cors.enable is true")
}
```

`cors.enable` defaults to **true** and `service.publicurl` defaults to **empty**,
so a Vikunja started with no configuration at all cannot boot. That is the
exit 1.

**2. Nowhere to write.** The Dockerfile sets `VIKUNJA_DATABASE_PATH=/db/vikunja.db`
and a scratch image has no `/db`; `files.basepath` defaults to `files`, which
`config.ResolvePath` anchors to `service.rootpath` → `/app/vikunja/files`, a
directory `WORKDIR` created as root in an image whose `USER` is 1000. Both are
fatal on first boot — `could not open database file [uid=1000, gid=0]` from
`db.initSqliteEngine`, and `Could not init file handler` from
`files.InitFileHandler`, which calls `storage.Ensure()` → `os.MkdirAll`.

**3. The public URL.** Not a crash, but the thing the engine cannot infer. See
below.

## What the recipe adds

- `overrides/docker-compose.override.yml` mounts a named volume at each of the
  two paths — the same two upstream's documented compose binds — and runs an
  `init` service that `chown`s them to 1000 first. Docker initialises a named
  volume from the image's directory **when the mount point exists there**,
  ownership included, and creates it root-owned `0755` when it does not; a
  scratch image has neither path, so without the chown the volumes only move
  the permission error rather than fix it.
- `files/panelalpha-init.sh` is what that `init` service runs: the chowns, and
  Vikunja's config file with the public URL (below).
- `hooks/prepare.sh` writes `.env` — which the generated app service already
  reads through `env_file:` — with `VIKUNJA_SERVICE_SECRET`, and pulls
  `alpine:3` before the build rather than during it (below).
- `probe` and `ready` make `docker compose up -d` return at the right moment.

`mem_limit: 512m` on the app replaces the hardener's 384m app-role default; 32m
and 64m for the helpers. The build, not the runtime, is what wants the account's
2500m.

## The public URL, and why an init container writes a config file

`service.publicurl` has to be the account's public address, and neither of the
two ways the other recipes get one is available:

- The `http://localhost` placeholder `ComposePlaceholders` rewrites to the
  account's https URL only runs in `UserComposeStrategy`. This deploy goes
  through `DockerfileStrategy`.
- That strategy does write the real URL onto the app service, as `SITE_URL` and
  six other aliases (`ComposeHarden::urlEnvironment()`), and pretix copies it
  across in its start command — `export PRETIX_PRETIX_URL="${SITE_URL}"`. There
  is no shell in a scratch image to copy it with, and compose cannot interpolate
  one service's variable into another's.

`hooks/prepare.sh` cannot supply it either. It runs in `AppConfigBootstrap`,
**before detection**, so the generated compose file does not exist yet — and
the account's own `~/<domain>/` directory is not there yet either. That was the
first attempt, and the timestamps on the test account say exactly why it
failed: `.env` written at `07:29:24.375`, `~/vikunjatest9dbr-aa2a.panelalpha.online/`
created at `07:29:29.952`, five and a half seconds too late.

What does work is doing it at container start, in the one container in the
stack that has a shell. `init` mounts the generated `docker-compose.yml`
read-only, reads `SITE_URL` out of it, and writes

```yaml
service:
  publicurl: "https://<account>.panelalpha.online"
```

into a named volume mounted at `/etc/vikunja`, which is the second path
`viper.AddConfigPath()` looks in. Environment variables still outrank a config
file in viper, so an account that sets `VIKUNJA_SERVICE_PUBLICURL` in its env
vars keeps the last word. Verified on the test account: `Using config file:
/etc/vikunja/config.yml`, `CORS enabled with origins: …,
https://vikunjatestmdtu-36d2.panelalpha.online`, and the served index carries
`window.API_URL = 'https://vikunjatestmdtu-36d2.panelalpha.online/api/v1'`.

**When no URL can be read** the script writes `cors: {enable: false}` instead,
so the fatal above can never be what a deploy ends on. That is a truthful
description of this deployment rather than a workaround — the frontend comes
from the same binary on the same origin, and `routes/static.go` falls back to
`window.API_URL = '/api/v1'` for an empty publicurl — but it does turn CORS off
for clients on other origins, and mail links lose their host.

## Pulling `alpine:3` before the build, not during it

The recipe's first full run died in the build with
`ResourceExhausted: … cannot allocate memory`, on the xgo link step, with
`Image alpine:3 Pulling` in the same output — inside the 2500 MB account the
same commit had built in 511s without the recipe. `docker compose up -d` pulls
the images it does not have while it builds the one it does not have either,
and that link is the largest single allocation the account ever makes. So
`hooks/prepare.sh` ends with `docker pull alpine:3` (never fatal): eight
megabytes, seconds, and nothing else running yet. Two runs since, both
`deploy-ok`.

## Secrets

`VIKUNJA_SERVICE_SECRET` is the one generated value. Left unset,
`generateServiceSecretIfEmpty()` makes a random one per boot, so every restart
would invalidate every session and API token. On a checkout older than the
`service.secret` rename the key is `VIKUNJA_SERVICE_JWTSECRET`, which is still
read and still works — it logs a deprecation warning and is copied onto
`service.secret`.

## Verified

Two `deploy-ok` runs on mariusz (470s and 500s, both full no-cache builds),
HTTP 200 on the account's domain with `<title>Vikunja</title>`, and the port
probe green. Beyond the 200: `POST /api/v1/register`, `POST /api/v1/login`,
`GET /api/v1/user` with the returned bearer token and `PUT /api/v1/projects`
all succeed over the public https domain, so sign-up, sign-in and writes go
through — the `Inbox` project the registration creates is there, and so is one
created over the API.

## Readiness, in two services instead of one

Vikunja runs its migrations against the empty SQLite file before it binds 3456;
the engine runs `up -d` without `--wait` and probes immediately. The usual shape
— a healthcheck on the app, a no-op `ready` gated on `service_healthy` — needs a
program inside the app container to run the check with, and a scratch image has
exactly one executable. `vikunja healthcheck` is not a substitute: its `PreRun`
is `initialize.FullInitWithoutAsync()`, which runs the migrations and opens a
second connection to the same SQLite file, and it only pings the database — it
says nothing about whether the HTTP server is listening.

So `probe` (alpine) polls `http://app:3456/api/v1/info` from the compose network
until it answers, and `ready` waits on `probe` having exited — a
`service_completed_successfully` dependency is what compose actually blocks on.
Both exit 0 and stay exited, which `AppHealth::isCrashing()` does not count as a
crash loop. `probe` gives up after 300s and still exits 0, so a Vikunja that
never comes up is reported by the health probe rather than as a failed deploy
stage.

## Not configured

**Registration is left on**, which is upstream's default and the only way into a
fresh instance: Vikunja has no bootstrap admin and no setup flow, and the only
alternative is `vikunja user create` over `docker compose exec`. Sign up first,
then set `VIKUNJA_SERVICE_ENABLEREGISTRATION=false` in the account's env vars.

**Mail.** The app works without it, but email confirmation, password resets and
reminders need an SMTP server the engine does not provide — point
`VIKUNJA_MAILER_ENABLED`, `VIKUNJA_MAILER_HOST`, `VIKUNJA_MAILER_USERNAME`,
`VIKUNJA_MAILER_PASSWORD` and `VIKUNJA_MAILER_FROMEMAIL` at one of your own.

**No database sidecar and no Redis.** SQLite is Vikunja's own default and one
person's task list is not what a database server is for; `keyvalue.type` and the
rate limiter both default to in-memory and only need Redis across several
instances. `VIKUNJA_DATABASE_TYPE`, `VIKUNJA_DATABASE_HOST`, … switch the first
over if you add one.
