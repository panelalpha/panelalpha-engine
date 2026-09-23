# Baïkal

Upstream: <https://github.com/sabre-io/Baikal> · tracker:
panelalpha/playground/supported-apps#274

A CalDAV and CardDAV server: a small admin UI written on the author's own
`Flake`/`Formal` micro-framework, and `sabre/dav` 4.7 underneath doing the
protocol. Version 0.12.1, Composer-managed, SQLite or MySQL.

Verdict before this recipe: `serving-missing_entry`, HTTP 403. Verdict with it:
`deploy-ok`, `serving: ok`, HTTP 200 — and, which is the only thing that
matters for a CalDAV server, a working protocol (see **Verification**).

## What the engine got right on its own

Detection is correct and this recipe does not change it. `composer.json` with
no `artisan` puts the checkout on the `php` platform and the `php` strategy;
`PhpRuntime` reads `"php": "^8.2"` and picks `panelalpha/php:8.2-apache-bookworm`;
`~/project` is bind-mounted at `/app`, Apache listens on 8000, and Composer
resolves the dependency tree on the host in 15 s. Every extension Baïkal's
`composer.json` requires — `ext-dom`, `ext-pdo`, `ext-zlib` — is in the stock
image, and `pdo_sqlite`, which is the one this recipe depends on, is baked into
`PhpBaseImage::EXTENSIONS`. Nothing had to be added to the image.

The two `.htaccess` mechanisms Baïkal ships also work as intended, because
`apache-vhost.stub` gives the document root `AllowOverride All`.

## What it could not infer, and why

**The document root.** `PhpDocroot::CANDIDATES` is `['public', 'web',
'public_html', 'webroot']`, then the application root, then `src/` for an
`index.php` only. Baïkal serves from `html/`, which is on none of those lists,
and the repository root holds `Makefile`, `README.md`, `composer.json`,
`phpstan.neon`, `run_tests.py` and no index at all — so `detect()` falls through
everything, emits no `PA_DOCROOT`, the base image serves `/app`, and Apache
answers 403. `docroot: html` is the fix and the only thing that can say it;
being a plain relative path it survives `PlatformManifest::readDocroot()`,
where `.` would not (engine #172).

Serving `html/` is also what makes the application safe to host here. `Core/`,
`config/`, `Specific/`, `vendor/`, `tests/`, `composer.json` and the engine's
own `docker-compose.yml` and `.env` are all *siblings* of the document root and
unreachable over HTTP by construction rather than by a rule. Measured below.

**That the document root is full of symlinks.** `html/admin/index.php` and
`html/admin/install/index.php` point into `Core/Frameworks/BaikalAdmin/WWWRoot/`,
and `html/res/core` points at `Core/Resources/Web/`, whose own entries point on
into the framework. All of it resolves outside `${PA_DOCROOT}`, under a
`<Directory />` that the stub sets to `Require all denied`. It works because
the stub also gives the document root `Options -Indexes +FollowSymLinks` and
Apache matches `<Directory>` blocks on the request path rather than on the
symlink target. Worth stating because it is the kind of thing that looks like
it should fail: measured, `/admin/` renders and `/res/core/.../style.css`
answers 200.

**That the install wizard is first-visitor-wins.** This is the part a document
root does not cover and on its own it is the whole security story. A deployed
Baïkal with no `config/baikal.yaml` sends *every* entry point — `index.php`,
`dav.php`, `admin/index.php` — to `/admin/install/`
(`Baikal\Framework::installTool()`, `Core/Frameworks/Baikal/Framework.php:51`),
and that page takes an administrator password from whoever asks first, with no
authentication of any kind. On an account with a public HTTPS domain the window
is however long it takes the owner to open a browser.

`files/panelalpha-baikal-setup.php` closes it on the install stage, before
Apache binds. It runs upstream's code rather than reimplementing it: the two
install controllers are `\Formal\Form` machinery wrapped around a POST, so what
is reused is the models underneath — `Baikal\Model\Config\Standard`, whose
`set()` is what hashes the password with
`BaikalAdmin\Core\Auth::hashAdminPassword()`, and
`Baikal\Model\Config\Database` — plus the schema loop, which reads
`Core/Resources/Db/SQLite/db.sql` out of the repository and splits it on `;`
exactly as `Install\Database::validateSQLiteConnection()` does. Nothing here
knows what a Baïkal table looks like. The version upgrade, which is the part
with real migration logic in it, is not copied at all:
`BaikalAdmin\Controller\Install\VersionUpgrade` is instantiated and rendered
unchanged.

Flake has no CLI entry point — `Framework::bootstrap()` reads
`SCRIPT_FILENAME`, `DOCUMENT_ROOT`, `REQUEST_URI` and `HTTP_HOST` — so the
script synthesizes the `$_SERVER` the real front controller would have had, and
defines `BAIKAL_CONTEXT_INSTALL`, which is upstream's own flag for "do not
demand a database yet" and, when the versions differ, for "open it, there is an
upgrade to run" (`Flake/Framework.php:271`).

**That there is no configuration file in a checkout, and where it should go.**
`.gitignore` lists `config/baikal.yaml`; a clone ships `config/baikal.yaml.dist`
and nothing else. The engine empties `~/project` before every clone (engine
#173), so the obvious place — the repository's own `config/` and `Specific/` —
would destroy the configuration *and every calendar in the account* on each
redeploy.

Upstream already has the answer: `Flake\Framework::bootstrap()` reads
`BAIKAL_PATH_CONFIG` and `BAIKAL_PATH_SPECIFIC` from the environment and only
falls back to the in-repository paths when they are unset
(`Core/Frameworks/Flake/Framework.php:180-195`). The compose override points
both at `/data`, bind-mounted from `~/.panelalpha/baikal`. So persistence here
is the application's own mechanism rather than a symlink or a copy-back, and
the account's whole state is two files it can see and copy:
`config/baikal.yaml` and `Specific/db/db.sqlite`.

`hooks/prepare.sh` creates that directory before compose runs, and that
ordering is load-bearing: compose creates a missing bind-mount source itself,
owned by root and mode 755, and this container runs as the account uid — which
could then neither write the SQLite file nor create its `-journal` beside it.

**That upstream's `.well-known` redirect does not survive this proxy.** See
**A defect this recipe fixes** below.

**That no `php.ini` reaches this image** (engine #185). Measured: `php --ini`
answers *Loaded Configuration File: (none)*, so `display_errors` is On,
`expose_php` is On and `post_max_size` is 8M.
`files/panelalpha/php/zz-baikal.ini` fixes that and the override names it with
`PHP_INI_SCAN_DIR`, listing the image's own `conf.d` first and explicitly —
`PHP_INI_SCAN_DIR` *replaces* the compiled-in path rather than adding to it,
and dropping it would unload every `docker-php-ext-*.ini`, `pdo_sqlite` among
them.

## The database, and why SQLite

SQLite, with no sidecar and no `database:` key.

Baïkal's own installer picks SQLite whenever `pdo_sqlite` is available
(`Install/Initialize.php:85`) — it is the default, not the fallback — the
schema ships as `Core/Resources/Db/SQLite/db.sql`, and `pdo_sqlite` is in
`PhpBaseImage::EXTENSIONS`. The result is 14 tables in a 110 KB file inside the
account's own home, which the owner can copy with sftp and restore by putting
it back.

The alternative was `database: mysql`, which would have given the account a
panel-visible database in phpMyAdmin and inside the panel backup. It was not
taken because the price is worse than the gain here: a MySQL wait on every
deploy and a second service, for an application whose upstream treats MySQL as
the option and SQLite as the default, and whose entire dataset for a
small-team calendar server is a few hundred kilobytes. A `postgres:` or
`mysql:` sidecar — what the DAViCal recipe is forced into, because DAViCal is
PostgreSQL to its stored procedures — would additionally have cost the account
its panel view, phpMyAdmin and the panel backup. None of that is necessary
here, and this is the cheapest recipe in the catalogue for it: one container,
29.6 MiB at rest.

The one thing to know: SQLite means one writer at a time. For a household or a
small team — which is what Baïkal is for; upstream's own front page says
"lightweight" — that is not a constraint anyone will meet. An account
syncing dozens of busy clients should switch `backend` to `mysql` in
**Settings → Database**, which the admin UI supports and this recipe does not
prevent.

## A defect this recipe fixes

`/.well-known/caldav` and `/.well-known/carddav` are how RFC 6764 clients find
a server when they are given only a domain — which is how every calendar client
is set up. Baïkal ships the rules for them in `html/.htaccess`:

    Redirect 308 /.well-known/caldav /dav.php

`Redirect` is mod_alias, and mod_alias builds an **absolute** URL out of what
the server believes it is. Behind the engine's proxy that is `http` and port
8000, so the account answers

    Location: http://<domain>:8000/dav.php

which is the container's own port on the public hostname: not published, not
TLS. Measured from outside: *Failed to connect to … port 8000*. Every client
that starts from `/.well-known` fails there, on an otherwise perfectly healthy
account.

The fix is three lines and no patch to upstream. A relative `Location` is legal
(RFC 7231 §7.1.2) and is resolved by the client against the scheme and host it
actually used, so it cannot be wrong about either:

    SetEnvIf Request_URI "^/\.well-known/(caldav|carddav)$" PA_WELLKNOWN_DAV=1
    Header always set Location "/dav.php" env=PA_WELLKNOWN_DAV

`always` is required: a 308 is generated by Apache and its headers live in
`err_headers_out`, not `headers_out`. Measured after: `308` with `Location:
/dav.php`, following it lands on `https://<domain>/dav.php` and answers 401.

**Every rule this recipe adds is mod_alias, mod_setenvif or mod_headers, and
not one is mod_rewrite.** That is measured, not stylistic. Upstream's own
block ends with

    RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization},L]

which matches every request and carries `[L]`, so a mod_rewrite rule appended
after it never runs: `RewriteRule ^res/.*\.html$ - [F]` written there returned
200, and the same path as a `RedirectMatch` returns 403.

## Security

**The admin password is generated per account and the wizard is closed before
the site is reachable.** `hooks/prepare.sh` mints 20 characters of
`[A-Za-z0-9]` into `~/.panelalpha/baikal-app.env` (0600 in a 0700 directory)
and writes a readable copy to `~/.panelalpha/baikal-admin-credentials.txt`; the
install-stage script stores it as `sha256('admin:' + auth_realm + ':' +
password)`, which is upstream's own `hashAdminPassword()`, and creates
`Specific/INSTALL_DISABLED`. It only does so while no password is set, so a
redeploy leaves a password the owner has changed alone — verified on a real
rebuild, which logged *"config already carries an admin password; left
untouched"* and left the hash byte-identical.

The password never goes through `.env`. `ProjectEnvironment::apply()` copies
`.env` to `.env.default` at mode 644 (engine #173), and this is a login
credential for a public endpoint; `env_file: ../.panelalpha/baikal-app.env`
keeps it out. Measured on the deployed account: `.env` is 0 bytes and
`.env.default` does not exist. There is no second secret — SQLite means no
database password, and the `encryption_key` field is generated into
`baikal.yaml` by the setup script (upstream seeds it with
`md5(microtime() . rand())`; this uses `random_bytes`).

**`/admin/install/` is denied outright**, and that is not belt-and-braces over
`INSTALL_DISABLED`. The branch *above* the one that checks that file does not
check it at all: when `configured_version` differs from the code's
`BAIKAL_VERSION`, `install/index.php` renders `UpgradeConfirmation`, and
`?upgradeConfirmed` then runs `VersionUpgrade` — a schema migration, on an
unauthenticated request, by upstream's deliberate choice (*"No auth check: …
upgrading is safe"*, `install/index.php:97`). Every deploy re-clones `master`,
so that window opens by itself the first time upstream bumps the version.
Denying the URL is only safe because the install/upgrade stage command runs the
same upgrade before Apache binds; without that, an account mid-version-bump
would be a redirect loop into a 403.

**Exposure, measured against a baseline 404 body rather than by status code.**
Everything outside `html/` — `config/baikal.yaml`, `Specific/db/db.sqlite`,
`vendor/autoload.php`, `Core/Frameworks/Baikal/Core/Tools.php`, `composer.json`,
`composer.lock`, `tests/`, `Makefile`, `run_tests.py` — returns a 404 whose body
is byte-identical to the 404 for a path that never existed, so nothing is being
rewritten to a front controller and nothing is leaking. `.git/`, `.env`,
`.env.default`, `docker-compose.yml` and `panelalpha-*` are 403 from the
generated vhost. `/admin/install/` and `res/**/*.html` are 403 from this
recipe's rules, while `res/**/*.css` still answers 200.

**Engine #181 does not apply here, and that was checked rather than assumed.**
The vhost's `FilesMatch` covers `docker-compose.yml` and `.yaml` but not
`docker-compose.override.yml` — which is the defect. It does not matter on this
application because the document root is `html/` and the override is a sibling
of it: measured, `/docker-compose.override.yml` returns the baseline 404. No
deny rule was added for it.

**The session cookie.** Baïkal's three entry points each set
`session.cookie_httponly` themselves; `samesite` is set nowhere in the
application, so the ini sets it. Measured on the deployed account:
`Set-Cookie: PHPSESSID=…; path=/; HttpOnly; SameSite=Lax`.
`session.cookie_secure` is deliberately not set — TLS terminates at the proxy
and the container only ever sees plain HTTP, so an account also reached over
`http://` would get a login form that never logs anyone in. The proxy's 301 to
https is what keeps the cookie off the wire (measured: port 80 answers 301).

**`X-Powered-By` is gone** (`expose_php = Off`); `X-Sabre-Version` stays,
because it is the application's own and DAV clients read it.

### Two things left as upstream has them

**Any authenticated calendar user can enumerate the other users.** A `PROPFIND`
`Depth: 1` on `/dav.php/principals/` as `bob` returns `bob`'s own displayname
with `200` and `alice`'s with `403` — but `alice`'s href is in the multistatus,
so the username is disclosed. The same is true one level down: `bob` can list
the *resource URIs* of `alice`'s calendar objects, which are client-chosen UIDs.
No property and no calendar data is readable: every propstat is
`403 Forbidden`, and a `GET` of `alice`'s event as `bob` is a flat 403. This is
`Sabre\DAVACL\Plugin` with its default `$hideNodesFromListings = false`, which
Baïkal does not change and which this recipe would have to patch
`Baikal/Core/Server.php` to change. It is the same class of default as
DAViCal's `$c->list_everyone`, and it is recorded rather than fixed because the
application is shipped unmodified.

**`dav_auth_type` is left at upstream's `Digest`.** Changing an application's
authentication default is a decision an operator should make, and it is one
dropdown in **Settings → Baïkal**: `users.digesta1` is
`md5(user:realm:password)` either way (`Baikal\Core\PDOBasicAuth` computes the
same value), so switching costs no re-hash and invalidates nothing.

One consequence is worth knowing. sabre/dav parses a `REPORT` body *before* it
authenticates, and `curl --digest` probes with `Content-Length: 0` — so a cold
`REPORT` as the very first request gets `500 Sabre\Xml\ParseException` ("The
input element to parse is empty") instead of the `401` the client is waiting
for, and the retry that would carry the body never happens. Real CalDAV clients
`PROPFIND` before they `REPORT` and so already hold a nonce; the verification
below reproduces that state by computing the Digest header itself. The same
request under `Basic` is one round trip.

## Verification

Deployed on `mariusz.panelalpha.tools` at host load 0.94–2.39, from scratch:
`deploy-ok` in **45.2 s**, `serving: ok`, HTTP 200, every `_baseline` and `php`
health check passing. The control deploy without the recipe took **45.2 s**
too and ended `serving-missing_entry` / HTTP 403 — the recipe adds no
measurable time, because the install stage is 20 `CREATE TABLE` and
`CREATE INDEX` statements into a local file. Runtime footprint: 29.6 MiB of a 512 MiB cap for the one container,
147 MiB for the whole account, 168 MB on disk (mostly `vendor/` and `.git/`).

No engine #90 gate service. The workaround is for a deploy whose install stage
is still loading a schema into a *starting datastore* when the probe fires;
measured here the upgrade stage runs in 45 ms end to end
(`[panelalpha] upgrade: php` at `18:20:49.182`, `[panelalpha] start: serve` at
`18:20:49.226`) and the probe found the site already answering. A gate would
cost an image pull and a container for a race that does not exist.

A rendered admin page proves nothing about CalDAV, so the protocol was
exercised end to end over the public HTTPS domain:

* logged into `/admin/` as `admin` with the generated password and created two
  users, `alice` and `bob`, through the web form — each of which Baïkal gives a
  default calendar and a default address book — and then a second, named
  calendar `work` through **Users → calendars → add calendar**;
* `PROPFIND` `Depth: 0` on `/dav.php/calendars/alice/default/` with `alice`'s
  credentials → **207 Multi-Status** with
  `<d:resourcetype><d:collection/><cal:calendar/><cs:shared-owner/></d:resourcetype>`,
  `<d:displayname>Default calendar</d:displayname>` and
  `<cal:supported-calendar-component-set>` listing VEVENT and VTODO; the same
  on `/dav.php/calendars/alice/work/` returns `Work calendar`;
* `OPTIONS` advertises `DAV: 1, 3, extended-mkcol, access-control,
  calendarserver-principal-property-search, calendar-access, calendar-proxy,
  calendar-auto-schedule, calendar-availability, resource-sharing,
  calendarserver-sharing, addressbook`;
* the same `PROPFIND` with a wrong password → 401; with no credentials → 401;
  `OPTIONS /dav.php/` with no credentials → 401;
* `PUT` of a VEVENT carrying `RRULE:FREQ=WEEKLY;COUNT=3` → **201** with an
  ETag; `GET` returns it with the RRULE intact;
* `REPORT` `calendar-query` with a `<C:time-range>` of
  `20260112T000000Z`–`20260120T000000Z`, against an event whose `DTSTART` is
  `20260105T090000Z` — a window containing no DTSTART, so it matches only if
  the server really expanded the recurrence — returned the event's href;
* `bob` `PROPFIND`ing `alice`'s calendar → 207 in which **every propstat is
  403** and no property comes back; `bob` `GET`ting `alice`'s event → 403. See
  the note above on what is still enumerable;
* `/.well-known/caldav` and `/.well-known/carddav` → 308 `Location: /dav.php`,
  which follows to `https://<domain>/dav.php` and answers 401.

The admin forms are `enctype="multipart/form-data"`, and a genuine multipart
POST through a `*.panelalpha.online` name is refused by the forwarding service
(engine #170). It is avoidable rather than fatal here: `\Formal\Form` reads
`$_POST`, so the same fields sent urlencoded work through the public domain,
which is how both users and the `work` calendar above were created. The one
form detail that is not obvious is that each field needs a `witness[<prop>]=1`
companion — `Formal\Element::posted()` ignores any `data[<prop>]` without one,
and a POST missing them comes back as *"Username is required"* with the value
right there in the body.

## What a redeploy does to the data

Nothing. Verified with a real `POST /projects/<user>/rebuild`, not a
simulation:

* `db.sqlite` md5 identical before and after;
* `admin_passwordhash` in `baikal.yaml` unchanged, and the install stage logged
  *"config already carries an admin password; left untouched"* and *"database
  already holds every required table"*;
* `alice`, `bob`, both of `alice`'s calendars and the test VEVENT all still
  there over CalDAV afterwards;
* the `.htaccess` block re-applied exactly once (the marker makes the append
  idempotent);
* `~/project/config/` came back holding only `baikal.yaml.dist` and
  `~/project/Specific/db/` came back empty — which is the point: the checkout
  holds nothing worth keeping, so engine #173 wiping it costs the account
  nothing.

## Licence

GPL-3.0-only (`LICENSE`, and `composer.json` says so too). No additional
condition on running it as a network service, no badgeware clause, no ambiguity
about the identifier. Hosting for third parties is unambiguously permitted and
the application is shipped unmodified.
