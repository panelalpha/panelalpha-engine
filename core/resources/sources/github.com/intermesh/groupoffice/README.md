# Group Office

PHP groupware — contacts, calendars, tasks, notes, files, an IMAP mail client
and CalDAV/CardDAV/ActiveSync sync — on MySQL. Tracker issue
[#1100](https://git.modulesgarden.tech/panelalpha/playground/supported-apps/-/work_items/1100).

Verdict before this recipe: `serving-missing_entry`, detected strategy `php`,
PHP 8.3. After: `deploy-ok`, `serving: ok`, HTTP 200, all twelve health checks
passing, 105s from queue to a rendered login page on a 2-core / 3.7 GB host.

## Licensing

**AGPL-3.0, and hosting it for third parties is permitted.**

Group Office is dual-licensed: a community edition and paid Professional and
Enterprise editions. What this repository contains is the community edition,
and it says so three times over:

* `LICENSE` is the verbatim GNU Affero General Public License, version 3.
* `www/composer.json` declares `"license": "AGPL"`.
* `publiccode.yml` declares `legal.license: AGPL-3.0-or-later` — the
  machine-readable one, and the least ambiguous.

AGPL-3.0 places no limit on who may run the software or for whom. Section 13,
"Remote Network Interaction" (LICENSE:540), is its only network condition: a
user interacting with the program remotely must be offered the Corresponding
Source. For a deployment that ships the application unmodified — which this one
does; nothing in `files/` changes Group Office's behaviour except its
configuration, and the one PHP file added is an empty probe target — that is
satisfied by pointing at <https://github.com/Intermesh/groupoffice>, which is
where the exact commit deployed came from.

The paid editions are not a complication here, because they are **not in this
repository**. Intermesh ships them as ionCube-encoded modules under
`www/go/modules/business/` and `www/promodules/`, and `.gitignore` excludes
`/www/go/modules/*` except `community` and `/www/promodules` outright. A clone
of this URL has neither. The licence check in the application
(`go\modules\business\license\model\License`) is left exactly as upstream
ships it; this recipe only turns off the *update* check, which is a call home
to intermesh.nl asking whether a newer release exists.

Two honest caveats:

* GitHub's own licence detection reports AGPL-3.0 for this repository, and so
  does `publiccode.yml`, but Intermesh's website sells editions of the same
  product under commercial terms. Nothing in the repository conditions the
  AGPL grant on anything, and a dual-licensed work is offered under *each* of
  its licences at the recipient's choice, so the AGPL grant stands on its own.
  This recipe's deployments are taken to be under AGPL-3.0.
* Branding is left exactly as upstream ships it. The AGPL has no badgeware
  clause, so this is not a licence obligation, but it is the posture the
  `couchcms` and `easysoft/zentaopms` recipes took and there is no reason to
  differ.

## What the engine could not infer, and why

### The document root

`PhpDocroot::CANDIDATES` is `['public', 'web', 'public_html', 'webroot']`, then
the repository root, then a late `src/`
(`app/Lib/Deploy/Platform/Runtime/Php/PhpDocroot.php:25-47`). Group Office
serves from `www/` — upstream's own install instructions symlink `www` to
`/usr/share/groupoffice`, and the Debian package's vhost points there — and the
repository root has no index file at all. So `detect()` falls through
everything and returns `''`, no `PA_DOCROOT` is emitted, and the base image
falls back to `/app`, which holds `README.md`, `CHANGELOG.md`, `LICENSE`,
`build/`, `media/`, `scripts/`, `tests/` and `www/`. Apache answers 403.

### `app_root: www`, not `docroot: www`

Both fix the 403. Only `app_root` also fixes what is underneath it, and this is
the one decision the whole recipe turns on:

* `www/composer.json` is the application's Composer manifest and
  `www/vendor/autoload.php` is the first line of every entry point. With the
  whole checkout as the application there is no `composer.json` at the root, so
  `php-plain` matches, no `composer install` ever runs, and every request is a
  fatal on the missing autoloader.
  `PhpStrategy::build()` resolves `<app_root>/composer.json`
  (`PhpStrategy.php:163-165`) and `DindHostBuilder::phpBuildArgv()` runs the
  host build in `/app/<app_root>` (`DindHostBuilder.php:367`).
* `www/composer.json` pins `config.platform.php` to 8.2. Read at the repository
  root there is no manifest at all and the deploy takes the engine default —
  which is how this app was originally reported as PHP 8.3.
* `~/project/.git`, the generated `docker-compose.yml` and `.env.default` are
  not in the container at all, because `/app` **is** `~/project/www`.

With `www/` as the application root the probe then answers correctly by itself:
`www/index.php` exists, `PhpDocroot::detect()` returns `ROOT`, `PA_DOCROOT` is
`/app`. No `docroot:` key is needed and engine#172 never comes up.

### The frontend build

`.gitignore` excludes `/**/*.css`, so a checkout ships no compiled CSS, and
`www/views/Layout.php:16` calls `filemtime(<theme>/style.css)` on every page.
The theme, the GOUI client bundles and the per-module stylesheets are built by
upstream's `scripts/install-npm.sh`, which needs the three git submodules. The
engine fetches those after the clone already
(`app/System/Project/Git.php:117-123`) — the one piece of this that needed
nothing.

The engine's own frontend pass does not fire, for two independent reasons:

* `HostCompile::runForPhp()` reads `package.json` from `$projectDir`, the
  checkout root, with no `app_root` applied (`HostCompile.php:188-190`) — while
  the line above its call site, `runPhpBuild(..., $build->appRoot, ...)`
  (`PhpStrategy.php:114-119`), does apply it.
* `www/package.json` declares npm workspaces and has no `build` script, so even
  reached it would return `false`.

So `files/package.json` puts a `package.json` at the checkout root whose only
content is `engines.node: 22` and a `build` script, and
`files/panelalpha-build.sh` runs upstream's own build. Measured: ~30s for 204
packages, the SASS pass and the esbuild bundles. The script asserts the three
artefacts that matter and removes the `node_modules` trees afterwards — build
tooling only, and the next deploy re-installs from the account's npm cache
mount.

### `go/modules/business/license/model/License.php`

`Environment::sourceIsEncoded()` (`go/core/Environment.php:175-186`) decides
whether the source is ionCube-encoded by reading the first 200 bytes of that
exact path. It is a paid-edition file and `.gitignore` keeps it out, so in a git
checkout it does not exist: `file_get_contents` raises `E_WARNING`, Group
Office's own error handler turns that into an `ErrorException`, and
`Installer::install()` dies inside `registerCoreEntities()` — measured, with the
full stack, before anything else in this recipe worked.

`files/www/go/modules/business/license/model/License.php` is an empty commented
file. `ClassFinder::fileIsEncoded()` reads it, finds no `sg_load`, and
`hasIoncube()` correctly answers "this source is not encoded". It defines no
class, so `class_exists()` is false and `ClassFinder` skips it exactly as it
would have skipped a file that was never there. This is a bug in installing
Group Office from its own repository rather than from a release tarball, and it
is upstream's.

## Security

### The installer

`www/install/` is an unauthenticated four-step wizard, and the obvious hole is
not the worst one:

| file | what an unauthenticated request gets |
| --- | --- |
| `configfile.php` | **rewrites `www/config.php` from POST fields** — database host, name, user, password and the data paths (`configfile.php:103-112`). A stranger can point a fresh deploy at a database they control and then create the administrator on it. The GET form also discloses the current host, name and user (`configfile.php:76-80`); the password field alone is not prefilled. |
| `install.php` | creates the System Administrator from POST (`install.php:71-85`). First visitor wins. |
| `upgrade.php` | runs schema migrations. For the life of the installation, not only before it is installed: `index.php` → `test.php` → `configfile.php` → `install.php`, and `install.php` redirects straight to `upgrade.php` as soon as the database is non-empty (`install.php:52-55`). |
| `gotest.php` | a full system report, down to the loaded extensions. |
| `clearcache.php` | rebuilds the cache on request. |

The whole directory is denied — `RedirectMatch 404` in `files/www/.htaccess`
plus a real `Require all denied` in `files/www/install/.htaccess` — and
everything it would have done runs from `files/www/panelalpha-install.php` in a
CLI process on the install and upgrade stages, before Apache binds.

The administrator password is generated per account by `hooks/prepare.sh` into
`~/.panelalpha/groupoffice-admin-password` (0600 inside a 0700 directory,
because account homes are root-owned 0755) and copied into the application root
as `www/.panelalpha-admin-password`, which the generated vhost denies as a
dotfile. It is never a default: upstream's `Installer::installAdminUser()`
ships `admin`/`admin` (`go/core/Installer.php:289-300`).

If you need the web upgrader for a migration this recipe will not do, delete
`www/install/.htaccess` and the `RedirectMatch` in `www/.htaccess`, run it, and
put them back.

### Exposure, measured on the deployed site

Everything below was requested over the public HTTPS domain after the deploy.

Denied: `/install/` and every page under it, `/config.php`,
`/config.php.example`, `/cli.php`, `/cron.php`, `/groupofficecli.php`,
`/groupoffice`, `/composer.json`, `/composer.lock`, `/package.json`,
`/vendor/` (404), `/.git/config`, `/docker-compose.yml`,
`/panelalpha-install.php`, `/panelalpha-setup.sh`,
`/.panelalpha-admin-password`, `/.panelalpha/php/zz-groupoffice.ini`, `/.env`,
`/.env.default`, and Z-Push's command-line tools.

`.git/` and `docker-compose.yml` are denied by the vhost, but they are also not
in the container: `/app` is `~/project/www`, and both live a directory above it.

Serving normally: `/`, `/api/jmap.php` (401 without a token),
`/api/auth.php`, `/views/Extjs3/themes/Paper/style.css`,
`/go/core/dav/index.php` and `/go/modules/community/carddav/index.php` (401
auth challenge — these are the CalDAV and CardDAV endpoints a phone talks to,
and denying them would remove a real feature).

The Z-Push list was found by asking the tree rather than guessing: every `.php`
under the document root whose first line is a `#!` shebang. Two of them —
`/modules/z-push/z-push-admin.php` and `z-push-top.php` — answered **200** to an
unauthenticated request and wrote their shebang line into the response before
failing on a CLI-only constant.

### Residual surface, not closed

Group Office serves its whole source tree from the document root, and a direct
request to a class file executes it. Measured: `GET /go/core/App.php` answers an
empty 500. With `display_errors` off (see below) nothing is disclosed, and this
is how upstream's own supported deployment serves it — the Debian package points
the vhost at `/usr/share/groupoffice`, which is this same directory. A blanket
deny on `.php` under `go/` and `modules/` is **not** applied because several
legitimate endpoints live there: `go/core/dav/index.php`,
`go/modules/community/carddav/index.php`, `modules/dav/files.php`,
`modules/carddav/addressbook.php`, `modules/site/index.php` and Z-Push's
`src/index.php`. Denying by prefix would break CalDAV, CardDAV or ActiveSync;
denying by an allowlist of names is a list that goes stale on the next upstream
release. Left open, and said out loud here rather than quietly.

One related upstream quirk: `GET /modules/site/index.php` on an installation
where the `site` module is not enabled answers with Group Office's own
"Uncaught exception" text, which names the account's database
(`Table 'x_app.site_sites' doesn't exist`). That is Group Office's error handler
printing, not `display_errors`, so the php.ini does not suppress it.

### php.ini

The base image loads none at all — `Loaded Configuration File => (none)`,
measured (engine#185). `files/www/.panelalpha/php/zz-groupoffice.ini`, reached
through `PHP_INI_SCAN_DIR`, turns `display_errors` and `expose_php` off, sets
`memory_limit` to 256M, and raises `upload_max_filesize` to 64M and
`post_max_size` to 72M. Group Office reports both to its client as
`maxSizeUpload` and `maxSizeRequest`, so without this the browser refuses a 3 MB
attachment before it is sent. Verified after the deploy: no `X-Powered-By`
header, and the session response reports `maxSizeUpload: 67108864`.

**But see "Uploads are capped at 1 MiB on a `*.panelalpha.online` domain"
below.** That cap is not this recipe's and not PHP's.

## Where the data lives, and what a redeploy does to it

Better than most. Group Office keeps nothing user-generated inside the checkout:
every uploaded file, mail attachment, contact photo and blob goes under
`file_storage_path`, which is a plain config value —
`Blob::buildPath()` is `<file_storage_path>/data/<xx>/<yy>/<id>`
(`go/core/fs/Blob.php:380-384`). So unlike ZenTao, XBackBone or Chyrp there is
nothing to symlink and nothing to mount *over* a directory inside the document
root.

`overrides/docker-compose.override.yml` bind-mounts `~/.panelalpha/groupoffice`
at `/data`, and `www/config.php` points `file_storage_path` at `/data/files` and
`tmpdir` at `/data/tmp`. The database is the account's own MySQL from
`database: mysql`.

Measured, by reproducing a redeploy by hand — `rm -rf ~/project`, re-clone,
re-apply the recipe, rebuild, recreate the container:

* the only file the running application had written inside `~/project` was
  `www/config.php`, which the setup command rewrites on every deploy;
* a 500 KB attachment uploaded before the redeploy was still at
  `~/.panelalpha/groupoffice/files/data/d5/61/d561082e…` afterwards;
* the contact and the calendar event created before it read back unchanged
  after it;
* the setup command's upgrade path reported *"already installed; schema
  26.0.47 matches the checkout"* and left the data alone.

The one thing that has to be **dropped** on a redeploy is the compiled client
bundle. `Extjs3::loadScripts()` caches the concatenated ExtJS/GOUI JavaScript
and the theme CSS at `<file_storage_path>/cache/clientscripts/`
(`Extjs3.php:297`) — which is inside the mount, so after a version bump it would
serve the previous release's JavaScript against this release's PHP.
`panelalpha-install.php` removes it recursively on both stages. The framework's
own disk cache (`cache/disk`) is handled by upstream's `rebuildCache()`.

This was a manual reproduction because the engine has **no redeploy endpoint**:
`POST /projects/{username}/clone` duplicates a project to a new account
(`UserController.php:1495-1540`), it does not redeploy this one. That is
[engine#2344](https://git.modulesgarden.tech/panelalpha/engine/-/work_items/2344).

## Verified

Over the public HTTPS domain, with the generated administrator credential:

* `POST /api/auth.php` → 201, access token, `version: 26.0.47`.
* `Contact/set` then `Contact/get` — a contact with an e-mail address, created
  and read back.
* `CalendarEvent/set` then `CalendarEvent/get` — an event with a start, a
  duration and a timezone, created and read back.
* `POST /api/upload.php` — a blob stored under `/data/files/data/…`.
* All of it again after the redeploy, against the same account and the same
  data.

`scripts/` in the scratch directory has the verification script; it is a plain
JMAP client and needs nothing but the domain and the credential.

## Things worth knowing

### Uploads are capped at 1 MiB on a `*.panelalpha.online` domain

Not PHP's limit and not the account's. Measured on the deployed site: 1,048,576
bytes → 201, 1,100,000 bytes → **413 from openresty**, while the same 3 MB body
posted directly to the container's port 8000 inside the account answered 201 and
stored the blob. The engine's own per-account nginx sets
`client_max_body_size 0` (`templates/webserver-nginx.blade.php:43`), so the cap
is the public tunnel edge in front of `*.panelalpha.online`, outside the
account. On a customer's own domain the ceiling is PHP's, which this recipe
raises to 64M. It affects every app that takes uploads through a tunnel domain,
not just this one.

### Server-sent events are off

`config.php` sets `sseEnabled = false`. Group Office's SSE endpoint holds one
Apache worker open per signed-in browser tab, and the base image is mod_php on
prefork with a worker count sized for ordinary requests — a handful of open tabs
would take the site down. Upstream's own `config.php.example` says to turn it
off on a server that cannot spare the connections. The client falls back to
polling.

### The mail client is a client

Group Office ships an IMAP client (`go/core/imap/Connection.php`) and sends
through PHPMailer over SMTP (`go/core/mail/PHPMailerSMTP.php`). It talks to a
mail server; it is not one. Nothing here binds port 25 and the inbound-SMTP
policy does not apply.

### `config.php` holds no secret

It is a list of `getenv()` calls, so the database password is never on disk. It
is inside the document root — `App::findConfigFile()` looks for
`<install dir>/config.php` (`go/core/App.php:936`) and `/etc/groupoffice/` is
not writable from the container — and it is denied in `.htaccess` anyway.

### Upgrades

The setup command runs on `install` and `upgrade`, and the upgrade path calls
upstream's own `Installer::upgrade()` — the same call `core/System/upgrade`
makes, minus its `clearCache()`, which asks the webserver for
`/install/clearcache.php`: a URL this recipe denies, and one that is not
listening yet anyway because the stage runs before Apache binds. Group Office
refuses to upgrade from below `Installer::MIN_UPGRADABLE_VERSION` (25.0.18); the
script checks that and fails the deploy loudly rather than serving a checkout
whose code and schema disagree.

Because the repository is tracked at `master` and Group Office releases often,
an account that redeploys after a version bump gets the migration automatically.
That has not been exercised on a real version bump — only the no-op path, where
the schema already matches.

## Not done

* The upgrade across an actual version change. The no-op path is verified; a
  real migration is not, because `master` is the only thing a clone of this URL
  gets and it was 26.0.47 throughout.
* CalDAV/CardDAV/ActiveSync sync from a real client. The endpoints answer their
  auth challenge and are deliberately left reachable, but no phone was pointed
  at them.
* The IMAP mail client, which needs a mail account to test against.
