# Kanidm (github.com/kanidm/kanidm)

Identity management server (Rust): OAuth2/OIDC, LDAPS, RADIUS, passkeys, with
a web UI where users manage their own credentials.

## Deploying

Deploy as is. The site answers with kanidm's login page (`/` redirects to
`/ui/login`).

The first deploy gives kanidm's two built-in admins a password and writes both
to `~/.panelalpha/kanidm/admins.env` in the account (0600):

- `idm_admin` (`IDM_ADMIN_PASSWORD`): people, groups, credential resets.
- `admin` (`ADMIN_PASSWORD`): the server's own settings, OAuth2 clients.

idm_admin's login is also the project's app credentials (`GET
/projects/{name}/app-credentials`, MCP `app_credentials_get`): the engine reads
it from `admins.env` once the app has started (`credentials.written_by_app`).

Both sign in at `/ui/login` and with the `kanidm` CLI
(`kanidm login -H <site URL> -D idm_admin`). People you create then set their
own credentials (a password plus TOTP, or a passkey) through a reset link:
`kanidm person credential create-reset-token <name>`.

To recover a password by hand, as upstream documents it, run in the project:
`docker compose exec app kanidmd recover-account idm_admin` (or `admin`). That
invalidates the password in `admins.env`; delete the file to have the next
deploy recover both admins again and write a new one.

## What the recipe does

- The repository is kanidm's Rust workspace; the plain deploy (rust strategy)
  built all of it and failed in `libudev-sys v0.1.4` (its build script needs
  libudev, which the build image does not have).
- `overrides/docker-compose.yml` runs `docker.io/kanidm/server:1.12.1` (the
  current release) with `/data` (database, TLS files, admin socket) on the
  named volume `data`. `files/panelalpha/kanidm/server.toml` is its
  `/data/server.toml`; `KANIDM_DOMAIN` / `KANIDM_ORIGIN` are `${PA_PUBLIC_HOST}`
  / `${PA_PUBLIC_URL}`, which WebAuthn, OAuth2 and the cookies are bound to.
- kanidmd has no plain HTTP listener and refuses to start without
  `tls_chain` / `tls_key`. The one-shot `certs` service runs
  `kanidmd cert-generate` (a self-signed CA and certificate on the volume; it
  leaves existing files alone), and `port_scheme: https` makes the engine's
  proxy reach port 8443 over https without checking that certificate.
  `X-Forwarded-For` is trusted from private ranges, so kanidm logs the
  visitor's address.
- The login: kanidm offers no way to give the built-in admins a chosen
  password. They are protected service accounts (only `recover-account` sets
  one, at random), a password set on any service account fails on 1.12.1
  (`pwd_changed_time` schema violation), and a person needs MFA to sign in. So
  a password the engine generated would never work.
  Instead `recover` (`files/panelalpha/kanidm/kanidm-recover.sh`) runs once
  kanidmd is healthy: on a new database it runs
  `kanidmd scripting recover-account` for `idm_admin` and `admin` over the
  admin socket and writes `admins.env`. While the file's `idm_admin` password
  signs in, or the volume's marker shows the file was written for this
  database, it changes nothing. Its image is upstream's `kanidm/tools` (bash,
  curl, the CLI) plus `kanidmd` copied from the server image
  (`files/panelalpha/kanidm/Dockerfile`), as the server image has no shell.
- `credentials:` declares idm_admin's login with `written_by_app:
  .panelalpha/kanidm/admins.env`: after each start the engine takes
  `IDM_ADMIN_PASSWORD` from that file, so the app credentials name the
  password that works (until the first start they hold a generated one).
- `hooks/prepare.sh` creates `~/.panelalpha/kanidm` (0700).
- `ready` makes `compose up -d` wait until kanidm is healthy and `recover` has
  finished.

## Memory

`app` 256 MB limit (about 35 MB used idle on Engine #4); `certs` and `recover` are one-shot.
