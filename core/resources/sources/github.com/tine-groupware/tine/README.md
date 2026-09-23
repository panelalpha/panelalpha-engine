# tine (tine-groupware/tine)

PHP groupware on MySQL: contacts, calendars, tasks, a file manager, CRM, sales,
HR and an IMAP mail client, with CalDAV, CardDAV and ActiveSync in front of it.
AGPL-3.0. https://github.com/tine-groupware/tine

`panelalpha.yaml` beside this file carries the argument for every manifest key.
This page is the detail that does not belong in a manifest: what was measured,
what was decided and why, what an operator needs to know, and what this recipe
deliberately does not do.

---

## What the 403 was

The tracker row (`panelalpha/playground/supported-apps#1220`) said
`serving-missing_entry`, HTTP 403, 45.2s. A control deploy of the same
repository with **no** recipe reproduced it in 50s, and the cause is one line of
directory layout.

The repository root is documentation and release tooling — `AGENTS.md`,
`CONTRIBUTING.md`, `LICENSE.md`, `README.md`, `SECURITY.md`, `ci/`, `docs/`,
`etc/`, `scripts/`, `tests/` and `tine20/`. The application is `tine20/`.

`PhpDocroot::detect()` (app/Lib/Deploy/Platform/Runtime/Php/PhpDocroot.php:80+)
tries `public`, `web`, `public_html`, `webroot`, then the repository root, then
the late candidates. `tine20` is on none of those lists — not under stock
`development-2.0.0`, whose `LATE_CANDIDATES` is `['src']`, and not under the
unmerged patch this host runs, which widens it to
`['www','htdocs','httpdocs','source','upload','webui','src']`. **Both engines
were checked and both miss it.** So `detect()` returns `''`, no `PA_DOCROOT` is
emitted, and `/usr/local/bin/panelalpha-serve` falls back to `/app` because
there is no `/app/public`. `/app` is the repository root, which has no
`index.php` and no `index.html`, and `Options -Indexes` in the generated vhost
turns that into a 403.

It is a layout problem and not, for once, engine#199: `composer install`
resolved tine's 193 locked packages in 56s with `--no-plugins` and the two
plugins the lock pins are harmless here (`php-http/discovery` only auto-selects
an HTTP client, and tine requires `php-http/curl-client` explicitly;
`tine20/composerapploader` installs packages of type `tine20application`, of
which the lock contains none).

Measured on the control deploy, the 403 is only the front page:

```
/                                    403
/tine20/index.php                    200   Fatal error: ... '/app/tine20/vendor/autoload.php'
/tine20/setup.php                    200   the same fatal
/tine20/config.inc.php.dist          200   served as source
/etc/tine20/config.inc.php.dist      200   6600 bytes, served as source
/.git/config                         403   (the vhost's dot-path rule)
```

That is the finding worth carrying beyond this app: **when `PhpDocroot::detect()`
finds nothing, the fallback is not "serve nothing", it is "serve the whole
repository"**. `/` is a 403 only because the root happens to have no index file;
every other path in the checkout is reachable, PHP included. A reviewer reading
`serving: missing_entry, 403` would reasonably conclude nothing is exposed.

### Is there an open installer on the control deploy?

Not reachable in practice on *this* control, and by accident rather than by
design. `/tine20/setup.php` returns 200 and executes, but dies on
`require '/app/tine20/vendor/autoload.php'` — because the repository root has no
`composer.json`, the PHP host build had nothing to run and `vendor/` was never
created. Give that checkout a `vendor/` by any route and the setup UI comes up,
and then:

`Setup_Server_Json.php:62-76` checks the json key and the session user only
`&& Setup_Core::configFileExists()`. A checkout has no `config.inc.php` —
`.gitignore` excludes `tine20/config.inc.php` — so in that state **every**
method on `Setup_Frontend_Json` is anonymous. That includes
`saveConfig()` (writes `config.inc.php` from POST data, so a stranger can point
the installation at a database they control) and `installApplications()`
(creates the first administrator on it). `Setup_Auth`
(tine20/Setup/Auth.php:31-46) only starts refusing logins once a `setupuser`
key exists, which is to say the lock appears only after someone has already
walked through the doorway.

So: on the control as deployed, no — the autoloader fatal stops it. As a class,
yes, and it is the reason this recipe writes `config.inc.php` before Apache
binds and denies `setup.php` outright.

---

## The decisions, and why

### `app_root: tine20`, not `docroot: tine20`

Both fix the 403. Only `app_root` also puts Composer where
`tine20/composer.json` is (so `vendor/` exists at all), resolves the PHP version
from that manifest (8.2, from its `config.platform.php`, rather than the engine
default of 8.3 the control deploy took), and — the one that matters most here —
takes `ci/`, `docs/`, `etc/`, `scripts/` and `tests/` out of the container
entirely instead of leaving them one request away.

### `database: mysql`

tine is MySQL-only: every `Setup/setup.xml` is MySQL DDL, `Setup_Backend_Factory`
has one backend, and `setup.php --migrateUtf8mb4` exists because the schema is
MySQL's. The account's own server means the database is in the panel, in
phpMyAdmin and in the account's backup, and costs the host neither a container
nor a volume. It also stops `PhpStrategy` mining the checkout for sidecars
(#166), and there is something to mine here: `scripts/docker-compose/` ships
workstation compose files.

### The client has to be built, and upstream's own config cannot build it here

`.gitignore` excludes `tine20/Tinebase/js/build/*`,
`tine20/Tinebase/styles/build/*` and `tine20/*/*/*FAT*`, so a checkout has no
compiled client at all. `Tinebase_Core::detectBuildType()` reads
`Tinebase/js/webpack-assets-FAT.json` and answers RELEASE if it is there and
DEVELOPMENT if it is not; in DEVELOPMENT, `getAssetsMap()` fetches the asset
manifest over HTTP from a webpack-dev-server on `localhost:10443` and throws
when there is none. There is no third mode, so the build is not optional.

Two things about that build were learned the expensive way.

**Upstream's `webpack/prod.mjs` is OOM-killed in the engine's build container.**
Measured on this 15.6 GB host, where `DindEngine::resolveBuildMemory()` gives a
build container MemTotal/3 = 5202 MB: the webpack process reached 5.0 GB
resident and the cgroup OOM killer took it after 2m38s, leaving `Killed` and
nothing else in the log
(`Memory cgroup out of memory: Killed process (webpack) total-vm:35615720kB,
anon-rss:5130116kB`, `constraint=CONSTRAINT_MEMCG`). A recipe cannot raise that
limit — it is derived from the host's RAM. So the recipe ships
`files/tine20/Tinebase/js/webpack/panelalpha.mjs`, which is upstream's
`common.mjs` unchanged plus a production setup that drops the three things a
hosting deployment pays for and never uses: source maps (`devtool: false`),
`UnminifiedWebpackPlugin` (a second unminified copy of every bundle, requested
only when `TINE20_BUILDTYPE` is DEBUG, which this recipe never sets) and
`BrotliPlugin` (`.br` files the generated Apache vhost has no `AddEncoding br`
to serve). Terser still runs, at `parallel: 2` rather than one worker per core.
That build peaks around 2.5 GB and takes 1m45s.

**The Node image needs `git`, and asking for it is not obvious.** Ten of tine's
frontend dependencies resolve to `git+ssh://git@github.com/...` in
`npm-shrinkwrap.json`, and npm fetches those by shelling out to `git`.
`NodeRuntime::imageFor()` gives a project `node:24-bookworm-slim`, which has no
git binary, unless `NodeRuntime::needsGitBinary()` finds a git subcommand in a
package.json script. The first deploy of this recipe failed on exactly that —
`npm error syscall spawn git`, `npm error git dep preparation failed`, 149s —
and see "Engine defects" below for the trap inside the trap.

### The client is two builds, not one, and the second one is PHP

The webpack pass is only half of it. Once `webpack-assets-FAT.json` exists the
installation is RELEASE, and in RELEASE
`Tinebase_Frontend_Http::getJsTranslations()` stops generating translations per
request and serves prebuilt `<App>/js/<App>-lang-<locale>.js` files
(Tinebase/Frontend/Http.php:175-186) — files `.gitignore` excludes
(`tine20/*/js/*-lang-*`) and that upstream builds with a phing task
(tine20/build.xml:392-447) that needs `require-dev`.

What happens without them is the most instructive failure in this whole recipe,
and it was found in a browser and nowhere else. The endpoint returns **HTTP 200
with a zero-byte body**. The health probe passes. `serving` is `ok`. Every check
in this README's exposure table passes. And the client waits ten seconds for
`Tine.__translationData.__isLoaded`, puts up *"A problem with the translations
was detected. Trying to reload the client…"*, reloads, and waits again, for
ever. Nobody can log in. A deploy verified by HTTP status codes would have
shipped it.

`files/tine20/panelalpha-langbuild.php` is upstream's phing task in about thirty
lines, calling upstream's own `Tinebase_Translation::getJsTranslations()` — it
reimplements the loop, not the translations. 1440 files (45 locales × 32
application directories, 14.7 MiB) in **1.4-2.0s**, on the install and upgrade
stages, before Apache binds. It ends by asserting that
`Tinebase/js/Tinebase-lang-en.js` exists and contains `__isLoaded`, because a
generator that silently wrote nothing is exactly the failure it exists to
prevent.

### The installer runs from the deploy, and `setup.php` is denied

`files/tine20/panelalpha-setup.sh` writes `config.inc.php` and runs
`php setup.php --install` in a CLI process on the install stage, which the
generated entrypoint runs *before* `exec panelalpha-serve`. Nothing is listening
on 8000 until it has finished, so the anonymous-setup-API window described above
never opens on a deployed account.

`files/tine20/.htaccess` then denies `setup.php` for the rest of the
installation's life. Upstream agrees about the risk and answers it differently:
its packaged nginx puts HTTP basic auth in front of the same file
(`etc/nginx/snippets/tine20-locations.conf`). This recipe denies it because the
account does not need it — the install and every schema update run from the
deploy — and because what is behind it is schema migration and *uninstall*
behind one shared password.

**What that costs the account.** The Setup UI is not reachable over HTTP. Its
functions are still available over SSH from `~/project/tine20`:

```
php setup.php --update                    # schema update (the deploy does this too)
php setup.php --list                      # installed applications and versions
php setup.php --setconfig -- configkey=... configvalue=...
php setup.php --backup -- config=1 db=1 files=1 backupDir=...
php setup.php --create_admin
```

An operator who wants the UI back can delete the `RewriteRule ^setup\.php$`
line; the `setupuser` password is in `~/.panelalpha/tine/app.env`.

### `acceptedTermsVersion=1` — who is accepting what

`Setup_Frontend_Cli::_promptRemainingOptions()` will not install without it: it
prints `tine20/LICENSE` and the GDPR module's privacy notice and blocks on
`fgets(STDIN)`. Passing it is what makes a non-interactive install possible.
`tine20/LICENSE` is the AGPL-3.0 text, which is the licence the software is
already under and which nobody has to "accept" to *run* it; the privacy notice
is tine's own template describing what the installation stores about its users.
An operator deploying tine for their own account is the person that notice
addresses. It is recorded in the database as accepted terms version 1 and can be
re-presented by the admin module.

### Mail: in scope, no inbound SMTP

The platform rejects mail-receiving apps as a class, and tine is not one.
Felamimail, tine's mail application, is an IMAP/SMTP **client**: it lists folders
and fetches messages over IMAP and submits outgoing mail to a configured SMTP
server. Credentials are per user, entered in the UI, and encrypted with
`credentialCacheSharedKey`. Nothing in it listens on port 25 or wants to be the
destination MTA for the account's domain.

tine *does* ship `etc/sql/postfix_tables.sql`, `etc/sql/dovecot_tables.sql` and
an "email user backend" that writes a Postfix/Dovecot installation's own tables
so that a tine user can be a mailbox on a mail server tine administers. That is
the part this platform cannot host. It is optional, off by default
(`Tinebase_EmailUser` is only consulted when `Tinebase_Config::IMAP`'s
`useSystemAccount` is set), and this recipe configures none of it. The two SQL
files are not even in the container — they are under `etc/`, which `app_root`
leaves outside it.

So: IMAP client, in scope. No SMTP listener, no MX, nothing to work around.

### Data, secrets and a redeploy

A deploy clears and re-clones `~/project` (engine#173), so nothing that has to
survive can live there.

| what | where | why it survives |
|---|---|---|
| uploads, Filemanager contents, mail attachments | `~/.panelalpha/tine/data/files` → `/data/files` | `filesdir`, a plain config value |
| temp/upload staging | `…/data/tmp` → `/data/tmp` | `tmpdir` |
| cache | `…/data/caching` → `/data/caching` | `caching.path` |
| sessions | `…/data/sessions` → `/data/sessions` | `session.path`; also why a redeploy does not log everyone out |
| admin password, setup password, `credentialCacheSharedKey` | `~/.panelalpha/tine/app.env`, 0600 in a 0700 dir | a second `env_file:`, not a file in the checkout |
| everything else | the account's own MySQL | `database: mysql` |

tine is unusually well-behaved about this: all four paths are configuration, not
hard-coded locations inside the tree, so nothing has to be symlinked and nothing
is mounted over a served directory.

`config.inc.php` holds `getenv()` calls, not values — so the one file that has
to live in the document root is not a secret even if the `.htaccess` rules were
ever bypassed.

`credentialCacheSharedKey` is the one that must never change: every stored
credential (an IMAP account's password above all) is encrypted against it, and a
new value on a redeploy would leave them all undecryptable. Generated once.

---

## Operating it

**The administrator login** is `admin`, and the password is in
`~/.panelalpha/tine/app.env` as `TINE_ADMIN_PASSWORD`:

```
grep TINE_ADMIN_PASSWORD ~/.panelalpha/tine/app.env
```

It is created on the first deploy only. Changing it afterwards is an ordinary
password change inside tine (or `php setup.php --setpassword -- username=admin
password=...`); the value in `app.env` is then stale and only the setup password
and the cache key still matter there.

**Adding users** is the Admin application inside tine. This recipe ships no
`overrides/app.sh`, so the panel's own user management does not drive tine — see
"Not done" below.

**Logs** go to the container's stderr (`docker logs`, and the panel's log
viewer): `config.inc.php` sets `logger.filename` to `php://stderr` at priority 5
(NOTICE). tine's own default is 7 (DEBUG), which writes several megabytes per
page load.

**The action queue is off.** tine's is Redis-only
(`Tinebase_ActionQueue`), and a PHP project here gets one container and no
Redis. With it off the same work runs synchronously inside the request. The
visible effect is that long operations (a large import, a mass mailing) block
their request rather than returning immediately.

**Translations: the client is fully translated, the server side is English.**
The client's 45 locales are built by `files/tine20/panelalpha-langbuild.php` on
every deploy — see that file for why they are not optional; without them tine is
a 200 OK that nobody can log in to. The *server* side is a separate mechanism
and is not built: with `buildtype` resolving to RELEASE,
`Tinebase_Translation::getTranslation()` uses Zend's `gettext` adapter and looks
for compiled `.mo` files, the checkout has `.po` only (`.gitignore` excludes
`tine20/*/translations/*.mo`), upstream compiles them with `msgfmt` inside
`vendor/bin/phing build`, phing is in `require-dev` and the base image has no
`msgfmt` binary (checked). `Zend_Translate_Exception` is caught per file and
logged, so a non-English user gets a translated UI and English strings in the
few places the server generates text itself — notification mail subjects,
export headers. A known, accepted limitation of this recipe, not a bug in tine.

**Full-text search of attachments is off.** `fulltext.tikaJar` is not set: tine's
own image downloads Apache Tika into `/usr/local/bin/tika.jar`, and the shared
PHP base image has no JRE.

---

## Measurements

All on `mariusz.panelalpha.tools` (8 cores, 15.6 GB, other tenants deploying
concurrently), against the engine at `/opt/panelalpha/shared-hosting/core` —
which carries the unmerged patch widening `PhpDocroot::LATE_CANDIDATES`. The
docroot conclusion was checked against both that and stock `development-2.0.0`
and is the same either way, because `tine20` is on neither list.

### Timings

| | recipe | control (no recipe) |
|---|---|---|
| first deploy, end to end | **318s** (312s on a second clean run of the final recipe) | **50s** |
| verdict | `deploy-ok`, `serving: ok`, **HTTP 200** | `deploy-ok`, `serving: missing_entry`, **HTTP 403** |
| health checks | 12/12 pass | — |

Where the 318s goes (from the deploy log, the successful first deploy of
`tinerec`):

| step | seconds |
|---|---|
| clone + submodule (224 MB, depth 1) | 16 |
| `hooks/prepare.sh` | 1 |
| `composer install --no-dev` (193 packages, 22 of them from git) | 76 |
| `npm install` in Tinebase/js (1371 packages, cold cache) | 60 |
| webpack (29 entry points, 585 bundles, 13 MB) | 106 |
| `docker compose up -d` to container healthy (the whole tine install) | 46 |
| the rest (detect, compose write, image pulls) | ~13 |

**Redeploy** (`POST /projects/tinerec/rebuild`): **209s**. It re-clones
`~/project` (engine#173 confirmed), so every step above runs again except the
install, which takes the `--update_needed` path and reports "already installed
and the schema matches the checkout". Composer resolves from the host cache in
16s instead of 76; npm is 52s and webpack 101s. Nothing survived because it was
in the checkout; everything survived because it was not:

| after the rebuild | result |
|---|---|
| `~/.panelalpha/tine/app.env` | byte-identical (same sha256), "keeping the tine secrets already in …" |
| admin login with the same password | succeeds |
| MySQL | 220 tables, 6 accounts, 7 contacts, 1 event — identical before and after |
| the users created through the API | `aliceba4430`, `bobba4430` both still there |
| a file PUT into Filemanager over WebDAV | downloads with the same content |
| the logged-in browser session | still logged in, straight back into the Addressbook (sessions are on the mount) |
| `config.inc.php` | rewritten from the environment, as designed |

### Memory

Measured on the idle installation after the first deploy, with the account's
own cgroups:

| | |
|---|---|
| app container, steady state | **354 MiB** (`memory.current`); 301 MiB on a freshly installed account |
| app container, peak | **474 MiB** (`memory.peak`, i.e. the installer); 479 MiB on the second account |
| `mem_limit` this recipe sets | 1024 MiB |
| the whole account container, host view | **527 MiB** |
| top process | `apache2`, ~200 MB RSS |

The 474 MiB peak is why `mem_limit` is not left to `ServiceLimits`, which caps
an app-role service it did not write at 384m: the install would have been
OOM-killed. 1024m leaves room for a prefork Apache serving several concurrent
PHP requests at `memory_limit = 512M`.

### Disk

| | |
|---|---|
| `~/project` after a deploy | **887 MB** (the checkout, `vendor/`, 585 built bundles, 1440 translation files) |
| `~/.panelalpha` | **66 MB** (63 MB of it the file cache, which is regenerated) |
| `tine20/Tinebase/js/node_modules` if it were kept | +567 MB — which is why panelalpha-build.sh deletes it |

### The build container

| | |
|---|---|
| host | 15.6 GB MemTotal, 8 cores |
| build container limit | 5202 MB (`MemTotal/3`, `ServiceLimits::hostBuildMemoryMb`) |
| `NODE_OPTIONS` heap the engine sets | `--max-old-space-size=3641` |
| upstream `webpack/prod.mjs` | **OOM-killed** at 5.0 GB anon-rss after 2m38s |
| this recipe's `webpack/panelalpha.mjs` | peaks ~2.5 GB, 1m45s |

### Exposure

`tine-expose.sh` compares **bodies**, not codes: the reference body is what the
front controller returns for a path it does not know (a zero-byte 401), so
anything matching it was rewritten into `index.php` rather than served.

| path | result |
|---|---|
| `/setup.php`, `/setup.php?method=Setup.getAllRegistryData` | 403 |
| `/config.inc.php`, `/config.inc.php.dist` | 403 |
| `/.git/config`, `/.htaccess`, `/.env`, `/.panelalpha/php/zz-tine.ini` | 403 |
| `/panelalpha-setup.sh`, `/docker-compose.yml` | 403 |
| `/composer.json`, `/composer.lock` | 403 |
| `/vendor/`, `/vendor/autoload.php`, `/vendor/composer/installed.json` | 403 |
| `/library/qCal/lib/qCal.php` | 403 |
| `/library/ExtJS/resources/images/default/s.gif`, `/library/ExtJS/ext-all.js` | 200 — deliberately; the compiled client links to them |
| `/tine20.php`, `/worker.php`, `/langHelper.php`, `/bootstrap.php`, `/Tinebase/Config.php` | rewritten to `index.php` (0-byte 401) |
| `/Tinebase/js/webpack/panelalpha.mjs`, `/install.properties.dist`, `/Tinebase/translations/de.po` | 403 |
| `/status.php` | 200 — **not** the file: it is tine's own public ownCloud-discovery route (Tinebase/Controller.php:1309), which reports a hard-coded `10.0.10.4` |
| `/Tinebase/js/webpack-assets-FAT.json` | 200, 1332 bytes — the client asset manifest, whose contents are already in every page's `<script src>` |

Three of these were **served with a 200 on the first green deploy** and are
fixed by the current `.htaccess`: `/composer.json` (9.6 KB), `/composer.lock`
(572 KB naming the exact commit of 193 dependencies) and
`/vendor/composer/installed.json` (508 KB). They are recorded here because
"compare bodies, not codes" is what found them — every one returned 200 with
real content while the front controller was answering everything else.

### What was verified past the probe

Over the real public HTTPS domain (`https://tinerec-9c9c.panelalpha.online`),
13/13 checks, plus a browser session:

1. `Tinebase.login` as `admin` over JSON-RPC — succeeds, returns an account id
   and a json key.
2. `Tinebase.getAllRegistryData` — 25 user applications (29 installed; the
   difference is the four the licence gates, below).
3. `Admin.saveUser` twice — two ordinary users created in the `Users` group.
4. Alice logs in, creates a contact and a `class: PRIVATE` calendar event.
5. Alice reads both back by search (`totalcount=1` each).
6. Bob logs in and: cannot see Alice's contact by search (`totalcount=0`); is
   refused it by id (`You do not have permission to get record of type
   Addressbook_Model_Contact`); is refused Alice's private event by id
   (`No Permission.`, code 403); and cannot reach the Admin API at all.
7. Anonymous `Addressbook.searchContacts` → `Not Authorised`, 401.
8. In a real browser: the login page renders, `admin` logs in, the Addressbook
   opens with the three contacts, the application menu shows 22 applications
   with their icons (the `icon-set` submodule), and the Calendar opens on a
   week view.
9. WebDAV and CalDAV answer on the paths the front-controller rule makes
   reachable: `PROPFIND /webdav/`, `/remote.php/webdav/`, `/remote.php/dav/`
   and `/calendars/` all return `207 Multi-Status`, and a `PUT` into
   `/webdav/Filemanager/.../personal files/` returns 204 and reads back.

### Known defects in this deployment

* **The branding logo is a broken image.** The client requests
  `/logo/i/300x100/image%2Fsvg%2Bxml/dark`; Apache rejects any URL containing
  `%2F` with its own 404 before mod_rewrite or even `ErrorDocument` can act,
  because `AllowEncodedSlashes` defaults to Off. The directive is valid **only**
  in server config or `<VirtualHost>` context, and the only `<VirtualHost>` here
  is baked into the shared image from
  `resources/deploy/templates/apache-vhost.stub` — so no recipe can set it. Four
  placements were measured and none works: `conf-enabled/`, `sites-enabled/`,
  `mods-enabled/` (all main-server context, which the vhost does not inherit),
  and a second `<VirtualHost *:8000>` block, which takes over the port and
  breaks the site. `ErrorDocument 404 /index.php` was measured too: Apache
  serves its canned 404 for an unescapable URL rather than the document. The
  un-encoded form `/logo/i/300x100/image/svg+xml/dark` reaches PHP (401), so
  this is purely the encoded slash. Everything else works; `/logo` and
  `/favicon/32` both return 200 PNGs. See "Engine defects" below.
* **Four applications are licence-gated and will not appear.** With no licence
  file `Tinebase_License_BusinessEdition::getStatus()` is
  `status_no_license_available`, and `Tinebase_License_Abstract::
  $_featureNeedsPermission` gates `CashBook`, `ContractManager`, `DFCom`,
  `EFile`, `GDPR`, `HumanResources.workingTimeAccounting`, `KeyManager`,
  `MeetingManager`, `OnlyOfficeIntegrator`, `Tinebase.featureCreatePreviews`
  and `UserManual`. `Tinebase_Acl_Roles` filters them out of the registry, which
  is why 29 installed applications show as 25. Document previews are in that
  list. This is upstream's business model, not something a recipe can or should
  route around; the login page's "tine ® trial — Please contact Metaways
  Infosystems GmbH to buy a valid license" is upstream's own banner.
* **`getMaxUsers()` defaults to 500** with no licence
  (`BusinessEdition::POLICY_DEFAULT_MAX_USERS`). Not a limit anyone on this
  platform will reach, but it is a limit.


---

## Engine defects found

1. **`api:call` is broken on this engine, and it is the documented no-token
   escape hatch.** `app/Http/Middleware/EnsureTokenMayUseApi.php:31` does
   `$request->user()?->currentAccessToken()`.
   `app/Console/Commands/Api/Call.php:40-44` authenticates an anonymous
   `Illuminate\Foundation\Auth\User` subclass, which does not use
   `HasApiTokens`, so that call is `BadMethodCallException: Call to undefined
   method …::currentAccessToken()` and **every** `php artisan api:call` returns
   HTTP 500 `{"message":"Server Error"}` — including `GET /test-connection`.
   The command's own description is "Call an internal API route and bypass
   middleware"; it now bypasses everything except the middleware that breaks
   it. Fix: guard the call (`instanceof HasApiTokens`, or `method_exists`), or
   have `Call.php` set `ApiTool::VIA_ATTRIBUTE` on the request the way
   `ApiTool` does. Cost: an operator or script on the host has to mint a real
   token instead, which is what this work did.

2. **`NodeRuntime::invokesGit()` does not recognise `git -C` or `git -c`.**
   `app/Lib/Deploy/Platform/Runtime/NodeRuntime.php:334-341` requires the
   subcommand to follow `git` immediately:

   ```php
   '/(?:^|[\s\'"&|;(=\[`])git\s+(?:rev-parse|log|…|submodule)\b/'
   ```

   `git -C /app submodule update` and `git -c safe.directory=* submodule update`
   — the two spellings you use when the checkout is a bind mount, and `git -C`
   is the form the engine's own `app/System/Project/Git.php` uses — do not
   match. `needsGitBinary()` then answers false, `withToolchain()` keeps
   `node:24-bookworm-slim`, and the build dies at `npm error syscall spawn git`
   / `npm error git dep preparation failed`. It cost one failed deploy here
   (149s) and the failure names npm, not the image. Fix: allow global options
   between the binary and the subcommand, e.g.
   `git(?:\s+-[cC]\s*\S+)*\s+(?:rev-parse|…)`.

3. **The generated Apache vhost cannot serve a URL containing an encoded
   slash, and nothing outside the engine can fix it.**
   `resources/deploy/templates/apache-vhost.stub` does not set
   `AllowEncodedSlashes`, so it is Off, and Apache answers its own 404 to any
   request whose path contains `%2F` — before mod_rewrite, and before
   `ErrorDocument`. `AllowEncodedSlashes` is only valid in server config or
   `<VirtualHost>` context, and the vhost is inside the shared image, so a
   recipe has no reach. Measured: main-server placement in `conf-enabled/`,
   `sites-enabled/` and `mods-enabled/` is **not** inherited by the vhost, and
   a second `<VirtualHost *:8000>` hijacks the port. This is not tine-specific
   — any PHP app with a front controller that takes a URL-encoded path segment
   (a mime type, a file path, an OAuth resource) is served correctly by nginx
   and 404s here. Fix: one line, `AllowEncodedSlashes NoDecode`, inside the
   `<VirtualHost *:{{ port }}>` block of the stub. `NoDecode` rather than `On`
   so the segment stays encoded and path segmentation does not change. Cost
   here: the branding logo is a broken image on the login page and in the
   header of every tine deployment.

4. **`PhpDocroot`'s "found nothing" fallback serves the whole repository, and
   the verdict says the opposite.** When `detect()` returns `''` no
   `PA_DOCROOT` is emitted and `/usr/local/bin/panelalpha-serve` falls back to
   `/app`, which for a repository-shaped project is the entire checkout. On the
   tine control deploy that meant `/tine20/setup.php` executed PHP,
   `/tine20/config.inc.php.dist` and `/etc/tine20/config.inc.php.dist` (6600
   bytes of annotated configuration) were served as source, and `ci/`, `docs/`,
   `scripts/` and `tests/` were all readable — while the reported verdict was
   `serving: missing_entry`, HTTP 403, which reads as "nothing is being
   served". The 403 is only `/`, and only because that one directory has no
   index file. Not obviously a bug to *fix* — an app that legitimately serves
   from its root needs this fallback — but the verdict should not describe it
   as an empty site, and `missing_entry` is worth a check that says what *is*
   reachable.

5. **engine#199 confirmed, and harmless here.**
   `app/System/Project/Dind/HostCompile.php:401-412` calls
   `PhpHostBuild::script($install, $build, $hasComposer)` — three of its six
   parameters — so `$composerLock` is null, `mayRunPlugins(null)` is false and
   `--no-plugins` is never dropped, and `$phpVersion` is null so `platformPin()`
   emits nothing. tine survives both: the two plugins its lock pins are
   `php-http/discovery` (which only auto-selects an HTTP client, and tine
   requires `php-http/curl-client` explicitly) and `tine20/composerapploader`
   (which installs packages of type `tine20application`, of which the lock
   contains none), and the resolve is from a lock so the missing platform pin
   changes nothing. The deploy log says so out loud: `The "php-http/discovery"
   plugin was not loaded as plugins are disabled.`

6. **engine#185 confirmed** — the base image loads no php.ini at all, so
   `memory_limit` is 128M, `post_max_size` 8M, `upload_max_filesize` 2M and
   `display_errors` On until a recipe ships `PHP_INI_SCAN_DIR`. For groupware
   the attachment ceilings are the ones that bite.

7. **engine#173 confirmed** — `POST /projects/{u}/rebuild` re-clones
   `~/project`, visible in the rebuild log as `Cloning into
   '/home/tinerec/project'...`. Everything this recipe keeps outside the
   checkout survived; anything left in it would not have.

---

## Not done, on purpose

* **`overrides/app.sh`.** The engine's user-management and SSO integration is
  not wired up, so the panel cannot list, create or impersonate tine users.
  tine has the API for it (`Admin.saveUser`, `Admin.searchUsers` over JSON-RPC,
  and `tine20.php --method` on the CLI), so it is a straightforward addition;
  it was out of scope for making the app work.
* **Server-side `.mo` translations.** See above. The client's are built.
* **Tika full-text indexing.** See above.
* **CalDAV, CardDAV and WebDAV were *routed*, not *used*.** `PROPFIND` on
  `/webdav/`, `/remote.php/webdav/`, `/remote.php/dav/` and `/calendars/` all
  answer `207 Multi-Status` and a `PUT` into the Filemanager tree round-trips,
  which proves the front-controller rule reaches them; no real calendar client
  was synchronised, and ActiveSync was only shown to answer `401` rather than
  `404` (i.e. it is routed).
* **The branding logo.** The encoded-slash 404 above. Not fixable from a
  recipe; filed under "Engine defects".
* **The admin password reaches `setup.php --install` as a command-line
  argument**, so it is visible in `ps` inside the account's own container for
  the duration of the first install. The alternative is
  `Setup_Frontend_Cli::_promptPassword()`, which prints the whole licence to
  stdout and blocks on stdin. It is not in the deploy log (the entrypoint runs
  `sh panelalpha-setup.sh`; the value comes from the environment) and not in
  the checkout.
