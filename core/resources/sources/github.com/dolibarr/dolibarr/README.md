# Dolibarr

Upstream: <https://github.com/Dolibarr/dolibarr>

An ERP and CRM: third parties, products, stock, orders, invoices, accounting,
roughly sixty modules over one MySQL schema. Classic multi-file PHP — no
framework, no front controller, the dependencies committed into
`htdocs/includes/` and `composer.json` shipped as `composer.json.disabled`.

What the recipe delivers is, above all, an application that is *claimed*: an
administrator with the login the engine generates (`credentials:` in
`panelalpha.yaml`, returned by `GET /projects/{name}/app-credentials`, MCP
`app_credentials_get`) and the install wizard closed.

## What the engine got right on its own

Detection is correct and this recipe does not change it. No `composer.json` at
the root satisfies `php.yaml`'s `none: [file: composer.json]`, so `php-plain`
claims the checkout; `PhpRuntime` has no constraint to read and takes the
engine default, PHP 8.3, which is inside Dolibarr's supported range; `~/project`
is bind-mounted at `/app` and Apache listens on 8000. Nothing resolves a
dependency tree, because the dependencies are committed.

Every extension this application needs is already in the shared base image:
`mysqli` (its only shipped driver here), `gd`, `intl`, `zip`, `calendar` and
`soap` are all in `PhpBaseImage::EXTENSIONS`. Nothing has to be built.

Upstream's two `.htaccess` files work as intended, because `apache-vhost.stub`
gives the document root `AllowOverride All`.

## What it could not infer, and why

### 1. The document root is `htdocs/`

`PhpDocroot::CANDIDATES` is `['public', 'web', 'public_html', 'webroot']` and
`LATE_CANDIDATES` is `['src']`
(`app/Lib/Deploy/Platform/Runtime/Php/PhpDocroot.php:25,45`), then the
repository root for an `index.php` or `index.html`. Dolibarr serves from
`htdocs/`, which is on none of those lists, and the repository root holds
`COPYING`, `COPYRIGHT`, `ChangeLog`, `README.md`, `composer.json.disabled`,
`phpstan.neon.dist`, `pyproject.toml`, `robots.txt`, `dev/`, `doc/`,
`htdocs/`, `scripts/` and `test/` — and no index file. So `detect()` falls
through everything, `environment()` returns `[]`, no `PA_DOCROOT` reaches the
container, `panelalpha-serve.sh` finds no `/app/public` and serves `/app`,
Apache has no `DirectoryIndex` candidate there and answers **403** — which
`resources/checks/php/entry-served.yaml` reports as `serving: missing_entry`.

`docroot: htdocs` states it. Being a plain relative path it survives
`PlatformManifest::readDocroot()`, where `.` would not.

Serving `htdocs/` is also most of what makes the application safe to host.
`dev/` (which ships a development compose file and a Dockerfile), `doc/`,
`scripts/` (three dozen CLI jobs meant for cron), `test/`,
`composer.json.disabled`, `.git/` and the engine's own generated
`docker-compose.yml` and `.env` are all *siblings* of the document root and
unreachable over HTTP by construction rather than by a rule.

### 2. The install wizard is first-visitor-wins

This is the main reason this recipe exists.

`htdocs/filefunc.inc.php:125` sets `$conffile = "conf/conf.php"` and includes
it; when that include fails and the request is a web request, line 222 sends
the visitor to `install/index.php`. The wizard there takes the database
credentials, the data directory, the URL root and the administrator login and
password from whoever asks first, with no authentication of any kind.

Without this recipe, `/` answers 302 to `/install/index.php`, which renders the
wizard, and the deploy reports success with `serving: ok` — because a 302 to a
200 is indistinguishable, from the outside, from a working site. **A 200 on `/` is not evidence that an ERP is yours.**

`files/panelalpha-dolibarr-setup.php` closes the window on the install stage,
before Apache binds. It drives Dolibarr's own wizard rather than
reimplementing it, because upstream supports exactly that: every install page
reads `$argv` when `GETPOST` comes back empty (`step1.php:60-84`,
`step2.php:62`, `step5.php:84-153`, `upgrade.php:88-91`,
`upgrade2.php:104-106`), and `install/inc.php:140` carries a `getopt()` block
and an `install_usage()` describing the command-line form. So:

| phase | what runs | guard |
|---|---|---|
| configuration | `step1.php set en_US /app/htdocs /data/documents <url> "" "" mysqli <db…> llx_ "" ""` | only when the stored `conf.php` is smaller than 9 bytes — Dolibarr's own "not configured yet" test (`install/inc.php:207`) |
| schema | `step2.php set en_US` | only when `llx_const` does not exist |
| administrator + lock | `step5.php 0.0.0 <version> en_US set <login> <pass> <pass> 1` | only when no `MAIN_VERSION_LAST_INSTALL`/`_UPGRADE` row exists |
| migration | `upgrade.php`, `upgrade2.php`, `step5.php … upgrade` | only when the stored version differs from `DOL_VERSION` on disk |

`cwd` is `htdocs/install/` and that is not cosmetic: `inc.php:36` requires
`'../filefunc.inc.php'`, `inc.php:40` defines `DOL_DOCUMENT_ROOT` as the
literal `'..'`, and `inc.php:73` sets `$conffile` to `'../conf/conf.php'`. All
three are relative. Each page exits non-zero on failure when it was started
from a CLI (`step1.php:830`, `step2.php:605`, `step5.php:633`), so the deploy
fails loudly rather than serving a half-installed ERP.

`step5.php:531-546` writes `DOL_DATA_ROOT/install.lock`, and
`install/inc.php:319-335` refuses every page under `install/` while it exists.
`hooks/prepare.sh` denies `/install/` in the document root's `.htaccess` as
well, because the lock alone is not enough:

- `install/upgrade.php` and `install/upgrade2.php` run schema migrations and
  have no authentication of their own — upstream gates them on an
  `upgrade.unlock` file (`inc.php:333`) rather than on a login. Every deploy
  re-clones the branch, so a version bump upstream opens that path by itself.
  The recipe runs the migration on the upgrade stage instead, creating and
  removing `upgrade.unlock` inside one run, which is what makes denying the
  URL safe rather than a lock-out.
- `install/phpinfo.php` is `phpinfo()`.
- The lock lives on a bind mount, and a rule in the document root does not.

### 3. `documents/` is Dolibarr's data directory and its default is inside the checkout

`install/inc.php:224` defaults `$dolibarr_main_data_root` to
`DOL_DOCUMENT_ROOT.'/../documents'`, which here would be `/app/documents`.
`ProjectTree::clearContents()` empties `~/project` before every clone,
so left alone, **every attachment, every generated invoice PDF
and `install.lock` itself would be deleted on each redeploy** — the ERP would
come back up with its books in the database, its documents gone, and its
installer open.

So the account's state lives in `~/.panelalpha/dolibarr`, bind-mounted at
`/data`:

| path | what |
|---|---|
| `/data/conf.php` | database password, `dolibarr_main_instance_unique_id` (the dolcrypt seed), URL root |
| `/data/documents` | `DOL_DATA_ROOT`: attachments, generated PDFs, the ECM tree, `install.lock` |
| `/data/custom` | `$dolibarr_main_document_root_alt`, where the module manager installs external modules |

`documents/` and `custom/` are named in `conf.php`, so those need no link.
`conf.php` does: `htdocs/filefunc.inc.php:125` and `install/inc.php:73` name it
as a literal relative path and read no environment variable, and the only other
supported location is the packagers' `/etc/dolibarr/conf.php`, which is a path
inside the shared image. `hooks/prepare.sh` therefore links
`htdocs/conf/conf.php` at `/data/conf.php`; `fopen($conffile, "w")` in
`step1.php:912` follows it, so the wizard writes straight through to the store.

The cost of the link, stated plainly because an account owner will see it: its
target is a path that only exists inside the application container, so over
SFTP the link reads as broken. `~/.panelalpha/dolibarr/README.txt` says where
the real file is. The alternative was two copies of the database password that
can drift; one file that is occasionally confusing beats that.

Putting the data root outside the document root is also what makes the
documents safe. Dolibarr serves them through `document.php`, which checks the
session.

### 4. `database: mysql`, not a sidecar

Dolibarr speaks `mysqli`; `mysqli` is in `PhpBaseImage::EXTENSIONS`. The key
gets a database on the account's own MySQL server (`AppDatabase::provision`),
which the panel lists, phpMyAdmin opens and the account's backup includes.

The password matters more than usual here: Dolibarr writes it into `conf.php`,
so a rotated one would bring the site back up unable to reach a database that
was working a minute earlier. `AppDatabase::password()` generates it once and
keeps it in the account's details, which is exactly the property needed.

The key has a second effect worth naming: `PhpStrategy.php:64-67` skips sidecar
mining entirely when a manifest declares `database:`. Dolibarr ships a compose
file at `dev/build/docker/docker-compose.yml` — a development stack with a
MariaDB whose root password comes from the developer's shell. Nothing reads it
today, because `RuntimeSidecars` only looks at the project root
(`ComposeFileInspector::COMPOSE_FILE_CANDIDATES`), but the `database:` key
means it would still be skipped if it ever moved.

### 5. No php.ini reaches this image

The base image loads no php.ini (`php --ini` answers `Loaded Configuration
File: (none)`), so the compiled-in defaults are in force — `memory_limit=128M`,
`upload_max_filesize=2M`, `display_errors=On`, `expose_php=On`. Dolibarr turns
`display_errors` off itself, but only after `conf.php` has been read
(`filefunc.inc.php:245`), which is not the requests whose output would name the
account's paths. `files/panelalpha/php/zz-dolibarr.ini` fixes that and raises
the limits an ERP needs; the compose override names the directory with
`PHP_INI_SCAN_DIR`, listing the image's own `conf.d` first — the variable
*replaces* the compiled-in path, and dropping it would unload every
`docker-php-ext-*.ini`, `mysqli` included.

## Exposure

What an anonymous visitor gets, after the recipe's install:

| request | result |
|---|---|
| `/conf/conf.php` — **the database password** | 403 |
| `/conf/conf.php.example`, `/conf/.htaccess`, `/conf/` | 403 |
| `/install/`, `/install/index.php`, `/install/phpinfo.php`, `/install/fileconf.php`, `/install/mysql/tables/llx_user.sql` | 403 |
| `/includes/tecnickcom/tcpdf/tcpdf.php`, `/includes/.htaccess` | 403 |
| `/public/test/test_csrf.php`, `/public/test/test_exec.php` and the other two | 403 |
| `/.git/config`, `/.git/HEAD` | 403 |
| `/.env`, `/docker-compose.yml` | 403 |
| `/panelalpha-dolibarr-setup.php`, `/panelalpha-entrypoint.sh` | 403 |
| `/documents/`, `/documents/install.lock`, any generated PDF | 404 — not in the document root at all |
| `/composer.json`, `/composer.json.disabled`, `/dev/`, `/doc/`, `/scripts/`, `/test/` | 404 — siblings of the document root |
| `/panelalpha/php/zz-dolibarr.ini` | 404 |
| `/custom/` | 403 (`Options -Indexes` over an empty directory) |
| `document.php?modulepart=facture&file=…` | 200 **with the login page** — not the document |
| `document.php?…file=../../conf.php` | 200 with the login page; authenticated, `File does not exist : conf.php` |

Three of those needed a rule written here rather than upstream's:

- **`/conf/`.** Upstream ships `htdocs/conf/.htaccess` containing `Require host
  localhost`, which is a reverse lookup of the client address. It does work
  behind the proxy, but "the database password is safe as long as a PTR record
  stays wrong" is not a sentence worth writing down. The recipe denies the path
  outright as well.
- **`/includes/*.php`.** Upstream's `includes/.htaccess` sets `SetHandler !`,
  which stops the vendored PHP being *executed* and leaves it being *served, as
  source*. Only `.php` is denied, because the JavaScript and CSS under
  `htdocs/public/includes/` — a different directory — are reached by rendered
  pages and must keep working.
- **`/public/test/`.** Four developer pages upstream ships with
  `define("NOLOGIN", '1')`. `test_exec.php` and `test_sessionlock.php` guard
  themselves on `$dolibarr_main_test` — and answer "Access forbidden …" with
  **HTTP 200**, so a status code alone does not show the refusal.
  `test_csrf.php` and `test_arrays.php` do not guard themselves at all: without
  this rule each renders a full Dolibarr page to an anonymous visitor.

Everything else under `public/` — the online payment pages, the ticket form,
the survey module — is public by design and left alone; `/public/demo/` refuses
by itself because `$dolibarr_main_demo` is not set.

## Redeploy

A redeploy runs the upgrade phase (`PA_DEPLOY_PHASE: upgrade`). `conf.php` —
with its `dolibarr_main_instance_unique_id`, so nothing encrypted is orphaned —
the documents and `install.lock` live on the bind mount and survive it, and
`/install/` stays denied.

`panelalpha-dolibarr-setup.php` chmods `conf.php` before it rewrites it:
**`htdocs/index.php:176-183` strips the write bits from `conf.php` on every load
of the home page** (`$newPerm = $currentPerm & ~0222`, then `dolChmod`), so a
deployed account's `conf.php` is `0400` within one request of coming up. That is
upstream hardening and welcome — but the one deploy that genuinely has to
rewrite `conf.php`, the one after a project's domain changed, would otherwise
fail on a file the process owns.

## Memory

One container, because `database: mysql` means the database is the account's
own. `mem_limit: 768m` rather than the 384m `ServiceLimits` would cap an
unclaimed app-role service at: the php.ini here allows a single request 256M,
which TCPDF and PhpSpreadsheet can each use.

## Known limits

- **The default branch is `develop`, and `htdocs/version.inc.php` reads
  `25.0.0-alpha`.** An account meant to hold real books should be deployed from a release branch;
  the recipe does not and cannot pin one, because the branch is a property of
  the project, not of the recipe.
- **A multi-version jump runs as a single pair.** Upstream's wizard walks a
  version jump one version pair at a time; the upgrade stage runs
  `upgrade.php`/`upgrade2.php` once, from the stored version to the one on disk.
  An account that has not been redeployed for a long time is the path most
  likely to need work.
- **No `overrides/app.sh`.** The panel cannot list, add or SSO Dolibarr users
  through this recipe. Its own user management is a full-featured part of the
  application and the administrator reaches it at `Home > Users & Groups`.
- **Modules are not pre-enabled.** A fresh install has the business modules
  off, and which ones an account wants is a decision about their business, not
  about hosting.
