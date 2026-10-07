# SOGo — github.com/Alinto/sogo

SOGo 5.12.11 is groupware written in Objective-C on GNUstep and SOPE: calendars,
contacts, tasks and a webmail client, published over CalDAV, CardDAV, GroupDAV
and ActiveSync as well as its own AngularJS UI.

## A mail client, not a mail server

SOGo is a mail **client**. It is never the destination MTA for its domain and
nothing in it wants port 25.

- **Reading** is IMAP, as a client. `SoObjects/Mailer/SOGoMailBaseObject.m:30`
  imports `<NGImap4/NGImap4Client.h>`; the server it talks to is
  `SOGoIMAPServer`, read at `SoObjects/SOGo/SOGoDomainDefaults.m:121`.
- **Sending** is SMTP submission, as a client.
  `SOGoDomainDefaults.m:302-316` accepts exactly one mailing mechanism —
  `smtp` — and logs and discards anything else; `SOGoDomainDefaults.m:322-334`
  reads `SOGoSMTPServer` and normalises it to a `smtp://` or `smtps://` URL.
- **Nothing listens for mail.** `sogod` is a SOPE `WOWatchDogApplication` and
  binds one HTTP port; upstream's own reverse-proxy samples
  (`Apache/SOGo.conf:83`) proxy to `127.0.0.1:20000` and that is the only
  socket. `packaging/debian/control` has no MTA in `Depends` and only
  *recommends* `memcached` and a web server.

The mailbox belongs to somebody else's server, and the account owner names it.
`SOGO_IMAP_SERVER` and `SOGO_SMTP_SERVER` are empty by default, and calendars
and contacts are fully usable with both unset — which is why
`SOGoLoginModule` is set to `Calendar` here rather than upstream's `Mail`.

## Building

The engine has no Objective-C runtime and `core/resources/platforms/` has no
GNUstep platform, but `dockerfile` lets a recipe bring any image.

The repository is **not** self-contained. `SOPE/` in the checkout holds only
`NGCards` and `GDLContentStore` — the two subprojects SOGo owns — and the rest
of SOPE (core, xml, mime, appserver, gdl1, ldap) plus `libsbjson` are separate
products. `packaging/debian/control` says so itself: `Build-Depends` names
`libsope-appserver4.9-dev`, `libsope-core4.9-dev`, `libsope-gdl1-4.9-dev`,
`libsope-ldap4.9-dev`, `libsope-mime4.9-dev`, `libsope-xml4.9-dev` and
`libsbjson-dev`. Upstream publishes all of them as Debian bookworm packages at
`packages.sogo.nu`, signed by the key `74FF C6D7 2B92 5A34 B5D3 56BD F8A2 7B36
A6E2 EAE9` (present on keys.openpgp.org; not on keyserver.ubuntu.com, and
packages.sogo.nu serves no key file of its own).

With those installed, the checkout compiles itself. **None of SOGo comes
prebuilt** — the `sogo` binary package is never installed; only SOPE, which is
a different product, does.

Three build-time traps, all fixed in `files/Dockerfile`:

- `/usr/share/GNUstep/Makefiles/GNUstep.sh` reads `$ZSH_VERSION` unguarded, so
  sourcing it under `set -u` aborts the build. `set -ex`, never `-eux`.
- `/bin/sh` in `debian:bookworm` is dash, which has no `pipefail`. A
  `make | tail -40` therefore reports **success for a failed compile** and
  produces no `sogod` at all. Redirect to a file and grep it on failure; never
  pipe.
- `libsope-ldap4.9-dev` brings `NGLdapConnection.h`, which `#include <ldap.h>`.
  Debian's `libldap2-dev` is not pulled in by anything and `LDAPSource.m` will
  not compile without it, even for an instance that never uses LDAP.

## Why the generic platform fails

The repository has no web root, no Dockerfile and no compose file, so detection
falls through to `php-plain` over the repository root and every request answers
403 (`serving: missing_entry`) — Apache refusing to list a directory with no
index.

## What this recipe supplies

Everything SOGo expects from a machine it owns, which a tenant account is not.

- **A web server, in the image.** `sogod` speaks HTTP on 20000 and expects a
  proxy in front that sets the `x-webobjects-*` headers; the engine only ever
  creates an HTTP proxy rule to one port, and there is no path to expose a raw
  TCP port. nginx therefore lives in the container, serves
  `WebServerResources` off disk, proxies `/SOGo`, redirects `/` and the
  `.well-known` DAV paths, and 404s everything else.
  - The one change from upstream's sample: `x-webobjects-server-url` is built
    from a `map` over `$http_x_forwarded_proto`, not from `$scheme`. `$scheme`
    inside the container is always `http` because the engine's proxy terminates
    TLS, and sogod puts that value into every absolute URL it emits — a browser
    on the https:// domain would be redirected to http:// on every hop.
- **A database.** `database: mysql` is read only under `runtime: php`
  (`Php/PhpEnvironment.php:90`) and says nothing to a Dockerfile project, so
  the override runs a `mariadb:11` sidecar on a named volume. SOGo creates its
  own folder/profile/session tables; it never creates the user directory.
- **A user directory.** `SOGoUserSources` of `type = sql`
  (`SoObjects/SOGo/SQLSource.m:59-77`) over a `sogo_users` table the entrypoint
  creates, with the five mandatory columns. No LDAP server is needed or wanted.
  Passwords are `sha512-crypt` (`SoObjects/SOGo/NSData+Crypto.m:262`), which
  `openssl passwd -6` produces directly.
- **memcached.** Not optional: `SoObjects/SOGo/SOGoCache.m` is where sessions
  live and `SOGoMemcachedHost` (`SOGoSystemDefaults.m:637-640`) must point at a
  server or nobody stays logged in.
- **`-WONoDetach YES`.** `sogod` daemonizes by default — upstream's unit is
  `Type=forking`. Without the flag the entrypoint's foreground parent exits 0 as
  soon as the real daemon is forked away, the entrypoint reads that as "sogod
  died" and takes the container down, once a minute, forever, while sogod is up
  and answering.
- **Persistence.** `~/project` is emptied on every deploy, so the
  database password is generated once by `hooks/prepare.sh` into
  `~/.panelalpha/sogo/sogo.env` and read as a second `env_file`; the first
  login (`sogoadmin`) is the engine's (`credentials:` in `panelalpha.yaml`),
  returned by `GET /projects/{name}/app-credentials` (MCP
  `app_credentials_get`); the
  calendars and contacts are rows in the named volume `sogo-db`.
- **A gate.** `docker compose up -d` runs without `--wait`, so
  `probe` polls `/SOGo/` and `ready` waits on it having exited.
- **Nothing to leak.** The checkout exists only in the build stage, so the
  runtime image contains no repository at all, and `sogo.conf` — which carries
  the database password — is `0640 root:sogo` in a `0750` directory outside
  every served path. nginx answers 404 for everything it does not serve.

## Known limits

- ActiveSync is not built (`--enable-activesync` needs `libwbxml2` wiring).
- SAML2, MFA and OpenID are off; `./configure` defaults them off and none of
  them can be exercised from a tenant account.
- No `users:sso`. SOGo authenticates every request with HTTP Basic or its own
  form and has no token login to mint, so `overrides/app.sh` does not advertise
  one.
- `SOGoIMAPServer` unset falls back to SOGo's own `localhost:143` default, so
  an account that never names a mail server logs `Could not connect IMAP4`
  whenever the Mail module is opened. Harmless — Calendar and Contacts are
  unaffected, and `SOGoLoginModule = Calendar` keeps a first visitor away from
  it — but it is noise in the log.
