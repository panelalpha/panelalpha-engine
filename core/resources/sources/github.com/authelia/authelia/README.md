# Authelia (github.com/authelia/authelia)

An authentication and authorization server: one Go binary serving a React
login portal and a JSON API on `:9091`, with SQLite behind it and a YAML file
or LDAP as its user directory.

Detection: `dockerfile`, and that part was right. The verdict was
`serving-unknown` — `app (Restarting (1) 9 seconds ago)`, nothing ever
listening on 9091.

## Is a standalone Authelia worth deploying at all?

It has to be asked, because Authelia is normally the companion of a reverse
proxy: nginx `auth_request`, Traefik `forwardauth` or Caddy `forward_auth` ask
it about every request to *some other* application and it answers 200 or sends
the browser to its portal. On its own domain it protects nothing.

The honest answer is **yes, with a limit worth stating plainly**:

- What the account gets is a working identity provider. The portal signs users
  in, enrols TOTP and WebAuthn credentials, resets passwords, and — once
  clients are registered — is a complete OpenID Connect 1.0 provider that other
  applications can authenticate against. None of that needs a proxy in front of
  it, and all of it is reachable on the account's own domain.
- What the account does **not** get is any way to put this login in front of
  another PanelAlpha app. Each account is served by the engine's own proxy, and
  an account cannot insert an `auth_request` into the vhost of the account next
  door. Forward-auth is still available to anything *outside* the engine that
  can reach the domain and is under the same cookie domain — which, with each
  account on its own `<account>.panelalpha.online` name, is nothing else here.

So: a usable identity provider on its own domain, not a way to gate the
neighbours. Deploying it is worthwhile; expecting it to protect a second
PanelAlpha account is not.

## Why it exited

Authelia will not start without a configuration file, and the configuration has
four things in it that nothing in the checkout knows.

**Three secrets with no defaults.** `session.secret`,
`storage.encryption_key` (minimum 20 characters) and
`identity_validation.reset_password.jwt_secret` are each a fatal validation
error when missing.

**A session cookie domain.** `validateSessionCookieDomains` pushes
`session: option 'cookies' is required` when the list is empty, and each entry needs a
`domain` and an `authelia_url` that must be absolute, must be **https**
(`utils.IsURISecure`) and must be equal to or inside the cookie domain
(`utils.HasURIDomainSuffix` — an exact match counts). That is the account's own
public address, which is not knowable until the container starts.

**A user.** Authelia has no sign-up page, no first-run wizard and no bootstrap
admin. The file backend does write a database when the path does not exist —
`checkDatabase` → `userYAMLTemplate` — and what it writes is the published pair
`authelia` / `authelia`, `disabled: true`. A first boot that reaches it
produces an instance nobody can log in to.

## Which Dockerfile

The repository ships three, and the interesting part is that the engine picked
the worst of them for a reason that is correct.

- Root `Dockerfile` is the release image: `COPY authelia-${TARGETOS}-${TARGETARCH}/authelia`
  copies a binary CI built, and `FROM authelia/base:${TAG}@sha256:${SHA}` takes
  its base from two `ARG`s the file never sets. `DockerfileFinder::isUsable()`
  rejects it on `missingContextSource`, which is right — building it from a
  clone is impossible.
- Next in the root listing is `Dockerfile.coverage`, upstream's CI image:
  `pnpm coverage` (`VITE_COVERAGE=true vite build`) for the frontend and
  `go build -tags dev -cover -covermode=atomic` for the backend. It builds and
  it runs, and it serves an istanbul-instrumented portal.
- `Dockerfile.dev` is the same two builder stages without the coverage flags.

`hooks/prepare.sh` derives `Dockerfile.panelalpha` from `Dockerfile.dev` with
two substitutions — `pnpm build` for `pnpm coverage`, and the `dev` build tag
dropped. `sed` rather than a shipped `files/Dockerfile` so the pinned
base-image digests stay whatever the checkout pins today.

Both substitutions matter. `internal/utils/version_dev.go` is the only
`//go:build dev` file in the tree and all it declares is `Dev = true`, but
three places read that constant: `internal/server/template.go` sends
`tmplCSPDevelopment` instead of `tmplCSPDefault` — a deliberately looser
Content-Security-Policy on every page of a login portal — `handlers.go` builds
the Duo client with `duoapi.SetInsecure()`, and `root.go` logs "running in
development mode". A `-tags dev` build is not a release build with a different
version string on it.

**And it copies `Dockerfile.dev.dockerignore` to
`Dockerfile.panelalpha.dockerignore`.** That line is load-bearing. BuildKit
prefers `<dockerfile>.dockerignore` over `.dockerignore`, and this repository's
root `.dockerignore` is

```
# Ignore All
*

# Overrides
!authelia-linux-*
!LICENSE
!entrypoint.sh
!healthcheck.sh
!.healthcheck.env
```

— written for the release image, which needs nothing but a prebuilt binary.
Building `Dockerfile.panelalpha` against it would send a context with no `web/`
and no `internal/` in it.

`panelalpha.yaml` names `Dockerfile.panelalpha` in `extra:` because a source
recipe is applied without running the probes, so `dockerfile`/`port_hint` would
otherwise be `null`/`80` and the deployability check would fail with
"Dockerfile strategy selected but Dockerfile is missing." The file the hook
writes exists by then: `PrepareFromSource::prepare()` bootstraps the app config
— snippets, overrides and the after-clone script — *before* `DetectProjectStrategy::detect()`.

## What the recipe adds

- `hooks/prepare.sh` — the Dockerfile above, and `.env` with three generated
  secrets plus a generated admin password. Written once: the `/config` volume
  outlives the checkout, and rolling the storage key orphans every TOTP secret
  already encrypted with it. The password is also written to
  `~/project/.panelalpha-admin-password` (0600).
- `files/panelalpha-bootstrap.sh` — runs as the container's entrypoint in front
  of the image's own, and execs it at the end.
- `overrides/docker-compose.override.yml` — the `/config` named volume, that
  entrypoint, a healthcheck, and `ready`.

Unlike the Vikunja recipe, none of this needs a second container.
`authelia/base` is an Alpine with a shell, so the bootstrap runs in the app
container itself — and `SITE_URL` and `SERVER_NAME` are already in its
environment (`ComposeHarden::urlEnvironment()`), so nothing has to be read back
out of the generated compose file.

The script writes `/config/configuration.yml` with the account's host as the
cookie domain and `https://<host>` as `authelia_url` — https regardless of what
`SITE_URL` says, because the engine terminates TLS in front of the account and
an `http://` value is a fatal error. The first line is a marker carrying the
domain it was generated for: a later boot on a different domain rewrites the
file, and a file whose marker has been deleted is never touched again.

Then it creates the admin account, hashed by the binary that is about to verify
it — `authelia crypto hash generate argon2 --password …`, argon2id at
Authelia's own defaults (64 MiB, t=3, p=4), paid once on the first boot. That
is the only reliable way to produce a digest Authelia's decoder accepts; there
is no argon2 tool in the account shell, and the prepare hook runs before the
binary exists.

## Readiness

`ready` is an `alpine:3` that exits 0, gated on the app's healthcheck — the
usual shape. The app's healthcheck has to be written here rather than left to
the image's: `/app/healthcheck.sh` sources `/app/.healthcheck.env` and
`exit 0`s when `X_AUTHELIA_HEALTHCHECK` is empty, so a container that never got
as far as writing that file reports *healthy*. The override polls
`http://127.0.0.1:9091/api/health` instead.

## Verified

Two `deploy-ok` runs on mariusz, 165.8s and 256.1s (both full no-cache builds;
the second shared the host with three other batches). `app Up (healthy)`,
`ready Exited (0)`, health probe green on 9091, HTTP 200 on the account's
domain, every `_baseline` check passing. The container log is clean —
`Storage schema migration from 0 to 29 is complete`, `Startup complete`,
`Listening for non-TLS connections on '[::]:9091'` — with one warning, that no
access-control rules were specified, which is true and is the point.

Beyond the 200, over the public https domain on the second account:

- `GET /` serves the portal with `<base href="https://<account>.panelalpha.online/" />`,
  and the bundle carries no istanbul instrumentation (which is what the
  `pnpm build` substitution was for).
- `GET /api/health` → `{"status":"OK"}`.
- `POST /api/firstfactor` with the generated admin password → `{"status":"OK"}`
  and an `authelia_session` cookie scoped to the account's domain.
- `GET /api/state` with that cookie →
  `{"username":"admin","authentication_level":1,"factor_knowledge":true}`.
- `GET /api/user/info` → the `admin` account, `has_totp: false`, i.e. the
  enrolment path is reachable.
- A wrong password → `401`, and after three the regulator refuses the *correct*
  password too, for the configured `ban_time`.

One cosmetic thing is not fixed: the instance logs its version as
`Authelia untagged-unknown-dirty (master, unknown) is starting`. Those values
come from `-X` ldflags upstream's CI sets (`BuildTag`, `BuildCommit`,
`BuildState`), and nothing in the repository's Dockerfiles sets them — a
`Dockerfile.coverage` build reports the same, with `-dev` appended. Worth
knowing for an auth server: the deployed version is the commit the engine
cloned, which the deploy log records, not whatever the binary says.

## Not configured

**OpenID Connect.** The provider needs an HMAC secret, an issuer JWK and at
least one registered client, and a client that does not exist at deploy time
cannot be registered at deploy time. Add
`identity_providers.oidc` to `/config/configuration.yml` (deleting the marker
line first, so the recipe stops rewriting it) and restart.

**SMTP.** No mail server comes with the account, so the notifier writes to
`/config/notification.txt` instead — which is where the password-reset and
identity-verification links go. `disable_startup_check: true` stops Authelia
probing a mail server that is not there on every boot. Point
`notifier.smtp` at one of your own to change that.

**LDAP and Redis.** LDAP is a server the engine does not provide; the file
backend is the alternative and is what this uses. Redis only matters for
sessions shared across several Authelia instances, and there is one.

**`access_control.default_policy` is `two_factor`**, which is Authelia's safe
default and matters only once something forwards requests here. It does not
affect the portal itself.
