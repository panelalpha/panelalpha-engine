# SOGo — github.com/Alinto/sogo

SOGo 5.12.11 is groupware written in Objective-C on GNUstep and SOPE: calendars,
contacts, tasks and a webmail client, published over CalDAV, CardDAV, GroupDAV
and ActiveSync as well as its own AngularJS UI. Tracker row
`panelalpha/playground/supported-apps#770`, previously `Unsupported` at
`serving-missing_entry` / HTTP 403.

Two questions had to be answered before any of the rest of this was worth
writing, and both came out in SOGo's favour.

## 1. Is it in scope, given that this platform rejects mail-receiving apps?

Yes, on exactly the basis b1gMail (#1348) and tine (#1220) were kept: SOGo is a
mail **client**. It is never the destination MTA for its domain and nothing in
it wants port 25.

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

So the shape is the same as b1gMail's POP3 pull and tine's IMAP client: the
mailbox belongs to somebody else's server, and the account owner names it.
`SOGO_IMAP_SERVER` and `SOGO_SMTP_SERVER` are empty by default, and calendars
and contacts are fully usable with both unset — which is why
`SOGoLoginModule` is set to `Calendar` here rather than upstream's `Mail`.

Proven live rather than argued: a throwaway GreenMail IMAP/SMTP server was
stood up beside the account, a message was delivered into it over SMTP, and it
was opened and read in SOGo's own webmail UI over the public HTTPS domain.

## 2. Does it build here at all?

Yes, and it is cheap — which was the surprise. The engine has no Objective-C
runtime and `core/resources/platforms/` has no GNUstep platform, but
`dockerfile` lets a recipe bring any image, so the question was only whether a
buildable path fits an account's budget.

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
a different product, does. Measured on the dev host:

| | |
|---|---|
| `./configure && make -j4 && make install` | **45 s** |
| whole image, cold, no cache, inside the account | **106 s** |
| cold build under `docker build --no-cache -m 2500m` | succeeded |
| cold build under `docker build --no-cache -m 1024m` | succeeded |
| runtime image | 526 MB |

Three build-time traps, all fixed in `files/Dockerfile`:

- `/usr/share/GNUstep/Makefiles/GNUstep.sh` reads `$ZSH_VERSION` unguarded, so
  sourcing it under `set -u` aborts the build. `set -ex`, never `-eux`.
- `/bin/sh` in `debian:bookworm` is dash, which has no `pipefail`. A
  `make | tail -40` therefore reports **success for a failed compile** — the
  first attempt here "built" in 45 s and produced no `sogod` at all. Redirect
  to a file and grep it on failure; never pipe.
- `libsope-ldap4.9-dev` brings `NGLdapConnection.h`, which `#include <ldap.h>`.
  Debian's `libldap2-dev` is not pulled in by anything and `LDAPSource.m` will
  not compile without it, even for an instance that never uses LDAP.

## What the 403 was

The repository has no web root, no Dockerfile and no compose file, so detection
fell through to `php-plain` over the repository root (confirmed by
`POST /source/inspect`: candidates `php-plain` priority 200 `via: detected`).
Reproduced on a control account with the recipe moved aside: deploy succeeded in
46 s, `serving: missing_entry`, and the `php/entry-served` check reported *"The
front page is missing: / answered 403 … There is no index.php anywhere in
~/project"*. The body is a 337-byte stock `Apache/2.4.68 (Debian)` 403 — Apache
refusing to list a directory with no index.

## What this recipe supplies

Everything SOGo expects from a machine it owns, which a tenant account is not.

- **A web server, in the image.** `sogod` speaks HTTP on 20000 and expects a
  proxy in front that sets the `x-webobjects-*` headers; the engine only ever
  creates an HTTP proxy rule to one port, and there is no path to expose a raw
  TCP port. nginx therefore lives in the container, serves the 53 MB of
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
  and answering. That was the `Restarting (0)` on the first deploy here.
- **Persistence.** `~/project` is emptied on every deploy (engine#173), so the
  database password and the first login are generated once by `hooks/prepare.sh`
  into `~/.panelalpha/sogo/sogo.env` and read as a second `env_file`; the
  calendars and contacts are rows in the named volume `sogo-db`.
- **A gate.** `docker compose up -d` runs without `--wait` (engine#204), so
  `probe` polls `/SOGo/` and `ready` waits on it having exited.

## Verified

Deployed cold from nothing but the repository URL onto a second, untouched
account (`sogocold`, no cached layers): **222 s**, `deploy-ok`, `serving: ok`,
HTTP 302 on `/` (to `/SOGo`) and 200 on `/SOGo/`, every health check passing,
no hand-fixing. A real `POST /projects/<user>/rebuild` on the working account
took **34.2 s** and a control deploy of the same repository with this recipe
moved aside took 46 s to reach the 403 above.

Past the probe, in a browser on the public HTTPS domain:

- Logged in as the generated first user; SOGo loaded its Calendar module.
- Created a **private** calendar event in the UI (`saveAsAppointment` 200) and
  read it back in the events list.
- Created a contact in the UI (`saveAsContact` 200) and read it back on the
  contact card.
- Opened the webmail INBOX against the throwaway IMAP server and read the
  message body.

Isolation, by body rather than by status code. A second user `bob` was created
through `overrides/app.sh`:

| request | result |
|---|---|
| owner `GET` the private `.ics` | 200, 625 bytes, the real VEVENT with `CLASS:PRIVATE` |
| `bob` `GET` the same `.ics` | 404, 91 bytes, `object not found` |
| anonymous `GET` the same `.ics` | 401, 0 bytes |
| owner `GET` the `.vcf` | 200, 136 bytes, the real VCARD |
| `bob` `GET` the same `.vcf` | 404, 91 bytes |
| `bob` `PROPFIND` the owner's calendar | 404 |
| `bob` `PROPFIND` *his own* calendar | 207 (control: bob's account works) |

Exposure, over the public domain, comparing bodies: `/etc/sogo/sogo.conf`,
`/sogo.conf`, `/.git/config`, `/.git/HEAD`, `/Dockerfile`,
`/panelalpha/entrypoint.sh`, `/panelalpha/nginx-sogo.conf`,
`/docker-compose.yml`, `/docker-compose.override.yml`, `/.env`, `/GNUmakefile`,
`/Version`, `/packaging/debian/control`, `/server-status` and `/nginx_status`
all return the same 153-byte nginx 404; five alias-traversal attempts at
`sogo.conf` return 400. There is nothing to leak in the first place: the
checkout exists only in the build stage, so the runtime image contains no
repository at all, and `sogo.conf` — which carries the database password in six
URLs — is `0640 root:sogo` in a `0750` directory outside every served path.

Redeploy kept everything: the event and the contact came back byte-identical
(same md5), `sogo.env` unchanged, both named volumes intact, row counts equal.

Memory, measured: `project-app-1` (nginx + sogod × 3 workers) 50 MB idle and
about 260 MB with a session open, against a 512 MB limit; `project-db-1` 93 MB;
`project-memcached-1` 9 MB. The whole account sat at **300 MB**. SOGo's own
watchdog caps each worker at 384 MB of vmem and says so at startup. This is not
a heavy app to run — it is only a heavy app to *say*, and the build is 106 s.

## Left undone

- ActiveSync is not built (`--enable-activesync` needs `libwbxml2` wiring and
  nothing here tests an Outlook client).
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
