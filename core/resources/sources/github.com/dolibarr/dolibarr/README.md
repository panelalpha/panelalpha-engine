# Dolibarr

Upstream: <https://github.com/Dolibarr/dolibarr> · tracker:
panelalpha/playground/supported-apps#307

An ERP and CRM: third parties, products, stock, orders, invoices, accounting,
roughly sixty modules over one MySQL schema. Classic multi-file PHP — no
framework, no front controller, the dependencies committed into
`htdocs/includes/` and `composer.json` shipped as `composer.json.disabled`.

Verdict with this recipe: `deploy-ok`, `serving: ok`, HTTP 200 — and, which is
the only thing that matters for an ERP, an application that is *claimed*: an
administrator with a per-account password, the install wizard closed, and an
invoice that can be created, validated and read back (see **Verification**).

## What the engine got right on its own

Detection is correct and this recipe does not change it. No `composer.json` at
the root satisfies `php.yaml`'s `none: [file: composer.json]`, so `php-plain`
claims the checkout; `PhpRuntime` has no constraint to read and takes the
engine default, PHP 8.3, which is inside Dolibarr's supported range; `~/project`
is bind-mounted at `/app` and Apache listens on 8000. Nothing resolves a
dependency tree, because the dependencies are committed — a deploy is 45 s,
almost all of it `git clone` of a 400 MB repository.

Every extension this application needs is already in the shared base image:
`mysqli` (its only shipped driver here), `gd`, `intl`, `zip`, `calendar` and
`soap` are all in `PhpBaseImage::EXTENSIONS`. Nothing had to be built.

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
That is the tracker's recorded verdict exactly.

`docroot: htdocs` states it. Being a plain relative path it survives
`PlatformManifest::readDocroot()`, where `.` would not (engine #172).

> Measured caveat, and it is worth knowing before reading the control numbers
> below: the engine deployed on the test host at the time of writing carries an
> **uncommitted local change** to `PhpDocroot::LATE_CANDIDATES`, widening it to
> `['www', 'htdocs', 'httpdocs', 'source', 'upload', 'webui', 'src']`. On that
> host `htdocs` is therefore found without any recipe. The `docroot:` key is
> kept regardless: it states the fact rather than depending on a heuristic
> landing on it, and the recipe has to work against `development-2.0.0` as
> committed.

Serving `htdocs/` is also most of what makes the application safe to host.
`dev/` (which ships a development compose file and a Dockerfile), `doc/`,
`scripts/` (three dozen CLI jobs meant for cron), `test/`,
`composer.json.disabled`, `.git/` and the engine's own generated
`docker-compose.yml` and `.env` are all *siblings* of the document root and
unreachable over HTTP by construction rather than by a rule. Measured below.

### 2. The install wizard is first-visitor-wins

This is the real finding, and on its own it is the whole reason this recipe
exists.

`htdocs/filefunc.inc.php:125` sets `$conffile = "conf/conf.php"` and includes
it; when that include fails and the request is a web request, line 222 sends
the visitor to `install/index.php`. The wizard there takes the database
credentials, the data directory, the URL root and the administrator login and
password from whoever asks first, with no authentication of any kind.

Measured, without this recipe, on a public HTTPS domain:

```
GET /                    302  ->  https://<domain>/install/index.php
GET /install/index.php   200      <title>Dolibarr install or upgrade</title>
                                  "The configuration file conf/conf.php does not
                                   exist … We will run the installation process"
```

and the engine scored that account `deploy-ok`, `health_healthy: true`,
`serving: ok` — because a 302 to a 200 is indistinguishable, from the outside,
from a working site. **A 200 on `/` is not evidence that an ERP is yours.**

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
`ProjectTree::clearContents()` empties `~/project` before every clone
(engine #173), so left alone, **every attachment, every generated invoice PDF
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
session — measured below.

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

### 5. No php.ini reaches this image (engine #185)

Measured on the deployed account: `php --ini` answers `Loaded Configuration
File: (none)`. So the compiled-in defaults are in force — `memory_limit=128M`,
`upload_max_filesize=2M`, `display_errors=On`, `expose_php=On`. Dolibarr turns
`display_errors` off itself, but only after `conf.php` has been read
(`filefunc.inc.php:245`), which is not the requests whose output would name the
account's paths. `files/panelalpha/php/zz-dolibarr.ini` fixes that and raises
the limits an ERP needs; the compose override names the directory with
`PHP_INI_SCAN_DIR`, listing the image's own `conf.d` first — the variable
*replaces* the compiled-in path, and dropping it would unload every
`docker-php-ext-*.ini`, `mysqli` included.

Confirmed in the running container: `memory_limit => 256M`, `post_max_size =>
32M`, `date.timezone => UTC`, `display_errors => Off`, `expose_php => Off`.

## Timings

Same host, same repository, same branch (`develop`), back to back.

| | verdict | deploy | HTTP `/` | what is actually being served |
|---|---|---|---|---|
| control, no recipe | `deploy-ok` | 45.1 s | 200 (after a 302) | the install wizard, open to anyone |
| with this recipe | `deploy-ok` | 60.2 s | 200 | the login form of a claimed instance |
| redeploy (`POST /projects/<user>/rebuild`) | success | 24 s / 25 s | 200 | unchanged, with the data |

The 15 s the recipe costs on a first deploy is Dolibarr's own installer:
`step1` + `step2` (roughly 600 `CREATE TABLE` plus the reference data set) +
`step5`. The redeploy is 24 s because the install stage does not run again —
`PA_DEPLOY_PHASE` is `upgrade`, and every phase's guard answers "already done",
which costs three `SELECT`s. Confirmed in the container log: the only line the
setup script printed on a redeploy was its final `ready:`.

## Verification, past the health probe

Everything below is over the account's real public HTTPS domain, as the
administrator the recipe generated, with a cookie jar — not `curl` inside the
container.

1. **Log in.** `POST /index.php` with `actionlogin=login`, the page's CSRF
   token and the generated password → 302, then `<title>Home - Dolibarr
   25.0.0-alpha</title>`, with a `Logout` link. Dolibarr's own redirects came
   back as `https://<domain>/…`, not `http://<domain>:8000/…` — so
   `dolibarr_main_url_root` is right and engine #177 does not bite here.
2. **Complete the company setup** (`POST /admin/company.php action=update`) and
   **enable four modules** (`/admin/modules.php?action=set&value=modSociete`,
   `modProduct`, `modCommande`, `modFacture`). The top menu went from
   `agenda home tools` to `agenda billing commercial companies home products
   tools`, and `/index.php` stopped redirecting to
   `redirect_if_setup_not_complete.inc.php`'s setup page.
3. **Create a third party.** `POST /societe/card.php action=add name=Acme
   Widgets SARL client=1` → `<title>Acme Widgets SARL - Card</title>`,
   `socid=1`.
4. **Create a product.** `POST /product/card.php action=add ref=WIDGET-001
   label="Blue Widget, 10mm" price=12.50` → `<title>Product Blue Widget, 10mm -
   Card</title>`, `id=1`.
5. **Create an invoice, add a line, validate it.** `action=add socid=1 type=0`
   → `<title>(PROV1) - Card</title>`; `action=addline idprod=1 qty=3` → the
   card shows `WIDGET-001` and `37.50`; `action=confirm_valid confirm=yes` →
   `<title>FA2609-0001 - Card</title>`. The draft reference became a real one,
   which is the whole point of validation.
6. **Read it back** in a fresh request: `/compta/facture/card.php?facid=1`
   renders `FA2609-0001`, `Acme Widgets SARL`, `WIDGET-001`, `37.50 €` and
   "Invoice FA2609-0001 validated"; `/compta/facture/list.php` lists it.
7. **The PDF TCPDF generated on validation** is at
   `~/.panelalpha/dolibarr/documents/facture/FA2609-0001/FA2609-0001.pdf` —
   outside `~/project`, which is the point — and downloads through Dolibarr's
   own `document.php` as `application/pdf`, 8523 bytes, starting `%PDF-1.7`.

## Exposure

Anonymous, over the public domain, after the recipe's install. Bodies compared,
not codes: every `403` here is Apache's own 342-byte page (one sha256 prefix,
`4f2515bdde5f`) and every `404` its 339-byte one (`cbe24c8ed442`), so a body
that is neither would stand out.

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
| `/documents/`, `/documents/install.lock`, `/documents/facture/FA2609-0001/FA2609-0001.pdf` | 404 — not in the document root at all |
| `/composer.json`, `/composer.json.disabled`, `/dev/`, `/doc/`, `/scripts/`, `/test/` | 404 — siblings of the document root |
| `/panelalpha/php/zz-dolibarr.ini` | 404 |
| `/custom/` | 403 (`Options -Indexes` over an empty directory) |
| `document.php?modulepart=facture&file=FA2609-0001/FA2609-0001.pdf` | 200 **with the login page**, 7974 bytes — not the PDF |
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
  pages and must keep working. Checked: every stylesheet and script the login
  page references still answers 200.
- **`/public/test/`.** Four developer pages upstream ships with
  `define("NOLOGIN", '1')`. `test_exec.php` and `test_sessionlock.php` guard
  themselves on `$dolibarr_main_test` — and answer "Access forbidden …" with
  **HTTP 200**, which is the sharpest example on this page of why bodies are
  compared and not codes. `test_csrf.php` and `test_arrays.php` do not guard
  themselves at all: each rendered a full 22 KB Dolibarr page to an anonymous
  visitor before this rule.

Everything else under `public/` — the online payment pages, the ticket form,
the survey module — is public by design and left alone; `/public/demo/` refuses
by itself because `$dolibarr_main_demo` is not set.

## Redeploy

`POST /projects/<user>/rebuild`, twice, 24 s and 25 s, both `success`.
`PA_DEPLOY_PHASE: upgrade` in the regenerated compose file. Afterwards:

- the administrator password is unchanged and logs in;
- `FA2609-0001` renders with its third party, its product line and its total;
- `societe/card.php?socid=1` is `Acme Widgets SARL`;
- the invoice PDF still downloads, byte-identical (8523);
- `conf.php` is byte-identical — same `dolibarr_main_instance_unique_id`, so
  nothing encrypted was orphaned;
- `install.lock` is still in place and `/install/` still answers 403;
- the four enabled modules are still enabled.

One thing this found, and it is the reason `panelalpha-dolibarr-setup.php`
chmods before it rewrites: **`htdocs/index.php:176-183` strips the write bits
from `conf.php` on every load of the home page** (`$newPerm = $currentPerm &
~0222`, then `dolChmod`). A deployed account's `conf.php` is `0400` within one
request of coming up. That is upstream hardening and welcome — but the one
deploy that genuinely has to rewrite `conf.php`, the one after a project's
domain changed, would have failed on a file the process owns. Measured after
the fix: seeded `dolibarr_main_url_root='https://wrong.example'` at mode 0400,
re-ran the setup script, and it restored the real domain and left the file at
0600.

## Memory and disk

Measured on the deployed account (`--memory-limit=2000`).

| | |
|---|---|
| `project-app-1` at rest | 67.8 MiB of a 768 MiB limit |
| `project-app-1` after the UI session above | 84.6 MiB |
| account container total (nested dockerd + the app) | 181.4 MiB |
| the same account without the recipe, idle | 146.3 MiB |
| `~/project` | 405 MB (the checkout; `git clone` is most of the deploy time) |
| `~/.panelalpha/dolibarr` | 1.7 MB after one invoice and its PDF |

One container, because `database: mysql` means the database is the account's
own. `mem_limit: 768m` rather than the 384m `ServiceLimits` would cap an
unclaimed app-role service at: the php.ini here allows a single request 256M,
which TCPDF and PhpSpreadsheet can each use.

## Known limits

- **The default branch is `develop`, and `htdocs/version.inc.php` reads
  `25.0.0-alpha`.** Everything above was measured against a pre-release. An
  account meant to hold real books should be deployed from a release branch;
  the recipe does not and cannot pin one, because the branch is a property of
  the project, not of the recipe.
- **The migration path is implemented but was not exercised.** Both deploys ran
  the same commit, so `upgrade.php`/`upgrade2.php` never had a version gap to
  cross. What was verified is that the guard correctly decides to skip them.
  A multi-version jump — what happens when an account has not been redeployed
  for a year — is what upstream's wizard walks one version pair at a time and
  this runs as a single pair; that is the path most likely to need work.
- **No `overrides/app.sh`.** The panel cannot list, add or SSO Dolibarr users
  through this recipe. Its own user management is a full-featured part of the
  application and the administrator reaches it at `Home > Users & Groups`.
- **Modules are not pre-enabled.** A fresh install has the business modules
  off, and which ones an account wants is a decision about their business, not
  about hosting. The four enabled during verification were enabled through the
  UI and are not part of the recipe.
