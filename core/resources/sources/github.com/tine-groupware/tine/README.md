# tine (tine-groupware/tine)

PHP groupware on MySQL: contacts, calendars, tasks, a file manager, CRM, sales,
HR and an IMAP mail client, with CalDAV, CardDAV and ActiveSync in front of it.
AGPL-3.0. https://github.com/tine-groupware/tine

`panelalpha.yaml` beside this file carries the argument for every manifest key.
This page is the detail that does not belong in a manifest: what was decided
and why, what an operator needs to know, and what this recipe deliberately does
not do.

---

## Why the repository does not deploy as it is

The repository root is documentation and release tooling — `AGENTS.md`,
`CONTRIBUTING.md`, `LICENSE.md`, `README.md`, `SECURITY.md`, `ci/`, `docs/`,
`etc/`, `scripts/`, `tests/` and `tine20/`. The application is `tine20/`.

`PhpDocroot::detect()` (app/Lib/Deploy/Platform/Runtime/Php/PhpDocroot.php:80+)
tries `public`, `web`, `public_html`, `webroot`, then the repository root, then
the late candidates. `tine20` is on none of those lists. So `detect()` returns
`''`, no `PA_DOCROOT` is emitted, and `/usr/local/bin/panelalpha-serve` falls
back to `/app` because there is no `/app/public`. `/app` is the repository root,
which has no `index.php` and no `index.html`, and `Options -Indexes` in the
generated vhost turns that into a 403.

It is a layout problem and not, for once, the engine's Composer install: `composer install` with
`--no-plugins` is fine here, because the two plugins the lock pins are harmless
(`php-http/discovery` only auto-selects an HTTP client, and tine requires
`php-http/curl-client` explicitly; `tine20/composerapploader` installs packages
of type `tine20application`, of which the lock contains none).

The 403 is only the front page, and that is the finding worth carrying beyond
this app: **when `PhpDocroot::detect()` finds nothing, the fallback is not
"serve nothing", it is "serve the whole repository"**. `/` is a 403 only
because the root happens to have no index file; every other path in the
checkout is reachable, PHP included — `/tine20/setup.php` executes, and
`/tine20/config.inc.php.dist` and `/etc/tine20/config.inc.php.dist` are served
as source. A reviewer reading `serving: missing_entry, 403` would reasonably
conclude nothing is exposed.

### Is there an open installer without this recipe?

Not reachable in practice, and by accident rather than by design.
`/tine20/setup.php` executes, but dies on
`require '/app/tine20/vendor/autoload.php'` — because the repository root has no
`composer.json`, the PHP host build has nothing to run and `vendor/` is never
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

So: deployed without the recipe, no — the autoloader fatal stops it. As a
class, yes, and it is the reason this recipe writes `config.inc.php` before
Apache binds and denies `setup.php` outright.

---

## The decisions, and why

### `app_root: tine20`, not `docroot: tine20`

Both fix the 403. Only `app_root` also puts Composer where
`tine20/composer.json` is (so `vendor/` exists at all), resolves the PHP version
from that manifest (8.2, from its `config.platform.php`, rather than the engine
default of 8.3), and — the one that matters most here —
takes `ci/`, `docs/`, `etc/`, `scripts/` and `tests/` out of the container
entirely instead of leaving them one request away.

### `database: mysql`

tine is MySQL-only: every `Setup/setup.xml` is MySQL DDL, `Setup_Backend_Factory`
has one backend, and `setup.php --migrateUtf8mb4` exists because the schema is
MySQL's. The account's own server means the database is in the panel, in
phpMyAdmin and in the account's backup, and costs the host neither a container
nor a volume. It also stops `PhpStrategy` mining the checkout for sidecars,
and there is something to mine here: `scripts/docker-compose/` ships
workstation compose files.

### The client has to be built, and upstream's own config cannot build it here

`.gitignore` excludes `tine20/Tinebase/js/build/*`,
`tine20/Tinebase/styles/build/*` and `tine20/*/*/*FAT*`, so a checkout has no
compiled client at all. `Tinebase_Core::detectBuildType()` reads
`Tinebase/js/webpack-assets-FAT.json` and answers RELEASE if it is there and
DEVELOPMENT if it is not; in DEVELOPMENT, `getAssetsMap()` fetches the asset
manifest over HTTP from a webpack-dev-server on `localhost:10443` and throws
when there is none. There is no third mode, so the build is not optional.

Two things about that build are not obvious.

**Upstream's `webpack/prod.mjs` can be OOM-killed in the engine's build
container.** The webpack process grows past 5 GB resident, and when the build
container's limit is below that the cgroup OOM killer takes it, leaving
`Killed` and nothing else in the log (`Memory cgroup out of memory: Killed
process (webpack)`, `constraint=CONSTRAINT_MEMCG`). A recipe cannot raise that
limit — it is derived from the host's RAM. So the recipe ships
`files/tine20/Tinebase/js/webpack/panelalpha.mjs`, which is upstream's
`common.mjs` unchanged plus a production setup that drops the three things a
hosting deployment pays for and never uses: source maps (`devtool: false`),
`UnminifiedWebpackPlugin` (a second unminified copy of every bundle, requested
only when `TINE20_BUILDTYPE` is DEBUG, which this recipe never sets) and
`BrotliPlugin` (`.br` files the generated Apache vhost has no `AddEncoding br`
to serve). Terser still runs, at `parallel: 2` rather than one worker per core.
That build needs about half the memory.

**The Node image needs `git`, and asking for it is not obvious.** Ten of tine's
frontend dependencies resolve to `git+ssh://git@github.com/...` in
`npm-shrinkwrap.json`, and npm fetches those by shelling out to `git`.
`NodeRuntime::imageFor()` gives a project `node:24-bookworm-slim`, which has no
git binary, unless `NodeRuntime::needsGitBinary()` finds a git subcommand in a
package.json script. Without it the build fails with
`npm error syscall spawn git` / `npm error git dep preparation failed`; see
"Engine defects" below for the trap inside the trap.

### The client is two builds, not one, and the second one is PHP

The webpack pass is only half of it. Once `webpack-assets-FAT.json` exists the
installation is RELEASE, and in RELEASE
`Tinebase_Frontend_Http::getJsTranslations()` stops generating translations per
request and serves prebuilt `<App>/js/<App>-lang-<locale>.js` files
(Tinebase/Frontend/Http.php:175-186) — files `.gitignore` excludes
(`tine20/*/js/*-lang-*`) and that upstream builds with a phing task
(tine20/build.xml:392-447) that needs `require-dev`.

What happens without them is the most instructive failure in this whole recipe,
and it shows only in a browser. The endpoint returns **HTTP 200 with a
zero-byte body**. The health probe passes. `serving` is `ok`. And the client
waits ten seconds for `Tine.__translationData.__isLoaded`, puts up *"A problem
with the translations was detected. Trying to reload the client…"*, reloads,
and waits again, for ever. Nobody can log in, and no HTTP status code says so.

`files/tine20/panelalpha-langbuild.php` is upstream's phing task in about thirty
lines, calling upstream's own `Tinebase_Translation::getJsTranslations()` — it
reimplements the loop, not the translations. One file per locale and
application (45 locales × 32 application directories), on the install and
upgrade stages, before Apache binds. It ends by asserting that
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

A deploy clears and re-clones `~/project`, so nothing that has to
survive can live there.

| what | where | why it survives |
|---|---|---|
| uploads, Filemanager contents, mail attachments | `~/.panelalpha/tine/data/files` → `/data/files` | `filesdir`, a plain config value |
| temp/upload staging | `…/data/tmp` → `/data/tmp` | `tmpdir` |
| cache | `…/data/caching` → `/data/caching` | `caching.path` |
| sessions | `…/data/sessions` → `/data/sessions` | `session.path`; also why a redeploy does not log everyone out |
| setup password, `credentialCacheSharedKey` | `~/.panelalpha/tine/app.env`, 0600 in a 0700 dir | a second `env_file:`, not a file in the checkout |
| admin login | the engine (`credentials:`), `~/.panelalpha/app-credentials.env` | a third `env_file:` |
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

### `mem_limit: 1024m`

Not left to `ServiceLimits`, which caps an app-role service it did not write at
384m: the installer peaks well above that and would be OOM-killed. 1024m leaves
room for a prefork Apache serving several concurrent PHP requests at
`memory_limit = 512M`.

---

## Operating it

**The administrator login** is generated by the engine (`credentials:` in
`panelalpha.yaml`) and returned by `GET /projects/{name}/app-credentials` (MCP
`app_credentials_get`): `admin` and its password.

It is created on the first deploy only. Changing it afterwards is an ordinary
password change inside tine (or `php setup.php --setpassword -- username=admin
password=...`); the returned value is then stale.

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
`msgfmt` binary. `Zend_Translate_Exception` is caught per file and
logged, so a non-English user gets a translated UI and English strings in the
few places the server generates text itself — notification mail subjects,
export headers. A known, accepted limitation of this recipe, not a bug in tine.

**Full-text search of attachments is off.** `fulltext.tikaJar` is not set: tine's
own image downloads Apache Tika into `/usr/local/bin/tika.jar`, and the shared
PHP base image has no JRE.

---

## Known limits

* **The branding logo is a broken image.** The client requests
  `/logo/i/300x100/image%2Fsvg%2Bxml/dark`; Apache rejects any URL containing
  `%2F` with its own 404 before mod_rewrite or even `ErrorDocument` can act,
  because `AllowEncodedSlashes` defaults to Off. The directive is valid **only**
  in server config or `<VirtualHost>` context, and the only `<VirtualHost>` here
  is baked into the shared image from
  `resources/deploy/templates/apache-vhost.stub` — so no recipe can set it. No
  placement outside it works: `conf-enabled/`, `sites-enabled/`,
  `mods-enabled/` (all main-server context, which the vhost does not inherit),
  and a second `<VirtualHost *:8000>` block, which takes over the port and
  breaks the site. `ErrorDocument 404 /index.php` does not help either: Apache
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

## Engine defects

1. **`NodeRuntime::invokesGit()` does not recognise `git -C` or `git -c`.**
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
   / `npm error git dep preparation failed`, and the failure names npm, not
   the image. Fix: allow global options
   between the binary and the subcommand, e.g.
   `git(?:\s+-[cC]\s*\S+)*\s+(?:rev-parse|…)`.

2. **The generated Apache vhost cannot serve a URL containing an encoded
   slash, and nothing outside the engine can fix it.**
   `resources/deploy/templates/apache-vhost.stub` does not set
   `AllowEncodedSlashes`, so it is Off, and Apache answers its own 404 to any
   request whose path contains `%2F` — before mod_rewrite, and before
   `ErrorDocument`. `AllowEncodedSlashes` is only valid in server config or
   `<VirtualHost>` context, and the vhost is inside the shared image, so a
   recipe has no reach. Main-server placement in `conf-enabled/`,
   `sites-enabled/` and `mods-enabled/` is **not** inherited by the vhost, and
   a second `<VirtualHost *:8000>` hijacks the port. This is not tine-specific
   — any PHP app with a front controller that takes a URL-encoded path segment
   (a mime type, a file path, an OAuth resource) is served correctly by nginx
   and 404s here. Fix: one line, `AllowEncodedSlashes NoDecode`, inside the
   `<VirtualHost *:{{ port }}>` block of the stub. `NoDecode` rather than `On`
   so the segment stays encoded and path segmentation does not change. Cost
   here: the branding logo is a broken image on the login page and in the
   header of every tine deployment.

3. **`PhpDocroot`'s "found nothing" fallback serves the whole repository, and
   the verdict says the opposite.** When `detect()` returns `''` no
   `PA_DOCROOT` is emitted and `/usr/local/bin/panelalpha-serve` falls back to
   `/app`, which for a repository-shaped project is the entire checkout. For
   tine without this recipe that means `/tine20/setup.php` executes PHP,
   `/tine20/config.inc.php.dist` and `/etc/tine20/config.inc.php.dist`
   (annotated configuration) are served as source, and `ci/`, `docs/`,
   `scripts/` and `tests/` are all readable — while the reported verdict is
   `serving: missing_entry`, HTTP 403, which reads as "nothing is being
   served". The 403 is only `/`, and only because that one directory has no
   index file. Not obviously a bug to *fix* — an app that legitimately serves
   from its root needs this fallback — but the verdict should not describe it
   as an empty site, and `missing_entry` is worth a check that says what *is*
   reachable.

4. **No php.ini** — the base image loads no php.ini at all, so
   `memory_limit` is 128M, `post_max_size` 8M, `upload_max_filesize` 2M and
   `display_errors` On until a recipe ships `PHP_INI_SCAN_DIR`. For groupware
   the attachment ceilings are the ones that bite.

---

## Not done, on purpose

* **`overrides/app.sh`.** The engine's user-management and SSO integration is
  not wired up, so the panel cannot list, create or impersonate tine users.
  tine has the API for it (`Admin.saveUser`, `Admin.searchUsers` over JSON-RPC,
  and `tine20.php --method` on the CLI), so it is a straightforward addition;
  it was out of scope for making the app work.
* **Server-side `.mo` translations.** See above. The client's are built.
* **Tika full-text indexing.** See above.
* **The branding logo.** The encoded-slash 404 above. Not fixable from a
  recipe; filed under "Engine defects".
* **The admin password reaches `setup.php --install` as a command-line
  argument**, so it is visible in `ps` inside the account's own container for
  the duration of the first install. The alternative is
  `Setup_Frontend_Cli::_promptPassword()`, which prints the whole licence to
  stdout and blocks on stdin. It is not in the deploy log (the entrypoint runs
  `sh panelalpha-setup.sh`; the value comes from the environment) and not in
  the checkout.
