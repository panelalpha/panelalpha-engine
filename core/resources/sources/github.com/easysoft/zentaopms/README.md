# ZenTao

Project/ALM management — products, projects, stories, tasks, bugs, releases —
on ZenTao's own PHP framework, with MySQL underneath and no Composer at the
repository root.

Upstream: <https://github.com/easysoft/zentaopms>. Tracker: #829.

## What the engine could not infer

**The document root is `www/`.** `PhpDocroot::CANDIDATES` is
`['public', 'web', 'public_html', 'webroot']`
(`core/app/Lib/Deploy/Platform/Runtime/Php/PhpDocroot.php:25`), then the
repository root, then `src/` as a late candidate. ZenTao serves from `www/`,
which is on none of those lists, and has no index file at the repository root —
so `detect()` falls through everything and returns `''`, no `PA_DOCROOT` is
emitted, and `panelalpha-serve.sh` falls back to `/app` because there is no
`/app/public`. `/app` holds `README.md`, `Makefile`, `config/` and `module/`
and no index, so Apache answers 403 and the report says `serving-missing_entry`.

`docroot: www` in `panelalpha.yaml` is the fix, and it is enough on its own:
`www` is a plain relative path, so unlike `.` it survives
`PlatformManifest::readDocroot()` (`PlatformManifest.php:297-318`), which folds
both `''` and `'.'` to "undeclared". **#172 does not bite here** and no
`PA_DOCROOT` override is needed — this is the case the manifest key exists for.
Adding `www` to the probe list would be the wrong fix: `www/` is also the
conventional name for a whole *site* directory, and a project with a
`www/index.html` beside a real front controller would be mis-served.

**The database.** ZenTao is MySQL or nothing — `db/zentao.sql` is 645 KB of DDL,
the driver list offers mysql/pgsql/dm, there is no SQLite path, and the checkout
ships no `.env` and no compose file to point itself at a server. `database:
mysql` gets one on the account's own MySQL server.

**The installation.** Nothing in the checkout runs it; see below.

## Serving `www/` is what makes this safe to host

Every directory that matters is a *sibling* of the document root, not inside it:
`config/` (the database configuration), `module/`, `framework/`, `lib/`, `db/`
(the schema and every migration), `tmp/`, and the engine's own
`docker-compose.override.yml`, `.git/`, `.env.default` and `panelalpha-*` files
at the repository root. None of them is reachable over HTTP by construction
rather than by an `.htaccess` rule.

That is a materially better position than CouchCMS or OpenEMR, which serve from
the repository root and need a `files/.htaccess` to claw it back (#181). It also
means `panelalpha-setup.sh` and `panelalpha-install.php` can sit at the
repository root without relying on the vhost's `panelalpha-*` deny rule.

Verified on a live deploy over the public HTTPS domain: `/config/my.php`,
`/db/zentao.sql`, `/docker-compose.override.yml`, `/Makefile`,
`/framework/router.class.php` and `/module/user/model.php` return no content,
because none of them is under the document root; `/.git/config`,
`/.env.default`, `/docker-compose.yml`, `/panelalpha-install.php`,
`/panelalpha-setup.sh` and `/.panelalpha-admin-password` are 403 from the
generated vhost's own rules.

**Read those results carefully if you audit this yourself.** Upstream's
`www/.htaccess` rewrites *every* path that is not a real file back to
`index.php` (`RewriteRule (.*)$ index.php/$1 [L]`), so a missing file answers
**200**, not 404. A status-code-only probe reports two dozen "leaks" that are
all the same empty page. Compare bodies, not codes.

### What upstream does put in the document root

`www/` holds more than the front controller, and on a first deploy every one of
these answered 200 to an unauthenticated request:

| File | What it is |
| --- | --- |
| `cache.php` | APC/OPcache/Redis/Memcached console — and `?action=redis_clear` calls `flushDb()`, `?action=memcache_clear` calls `flush()`, straight from `$_GET` |
| `checktable.php` | includes `../config/config.php`, so it runs with the account's database credentials, and offers table check/repair |
| `dev.php` | "Zentao Dev Tools", a SQL profile browser |
| `coverage.php`, `webcoverage.php` | test-coverage reports |
| `init.php` | bare framework bootstrap that builds a `commonModel` named "tester" |
| `worker.php`, `cron.php` | RoadRunner/FrankenPHP and cron entries, neither meant for HTTP |
| `install.php.tmp`, `upgrade.php.tmp` | Apache has no `.tmp` handler, so it served the installer's **source as plain text** — and these are the files `Makefile:85-86` renames into a live installer |

`hooks/prepare.sh` appends a `FilesMatch` deny for all of them to
`www/.htaccess`. Denied rather than deleted, so the checkout stays exactly as
upstream ships it and an account that wants them back has one line to remove.
`index.php`, `api.php`, `imgproxy.php` (a three-line stub that only prints a
discouraging message) and the asset directories are untouched — re-verified
after the change: the whole table above is 403 and the application and its REST
API still work.

## There is no web installer to race

`.gitignore` lists `www/install.php` and `www/upgrade.php`. The repository ships
`www/install.php.tmp` and `www/upgrade.php.tmp`, and the **Makefile renames them
only when it builds a release tarball** (`Makefile:85-86`). A deploy that clones
the repository therefore has no installer to expose at all — the
first-visitor-wins hole found in CouchCMS, Koillection, Outline, NocoDB and
Mattermost is simply not reachable. This recipe never creates `install.php`, so
the window stays shut for the same structural reason upstream's own release
build closes it.

**And the install controller refuses to run even if one appeared.**
`install/control.php:25` calls `helper::end()` unless `$this->app->installing`,
and that flag is only set when the app is created in installing mode — which
only `www/install.php` does. Measured: `GET /install.php` on a live deploy is
rewritten to `index.php`, reaches the install controller and returns an **empty
200**. No wizard, no step 1, no way to claim the admin account. So the answer to
"can an unauthenticated visitor claim the first admin?" is **no**, for two
independent reasons.

A *failed* install would therefore serve an empty page rather than an open
wizard — and the compose healthcheck fails the deploy before even that can be
served, because it insists on the rendered login form rather than on a status
code (see the override file for why `curl -f`, and even `curl -fL`, are not
enough here).

The installation itself runs from the install stage in a CLI process, before
Apache binds, via `files/panelalpha-install.php`. It bootstraps ZenTao's own
framework exactly as `www/install.php.tmp` does
(`router::createApp('pms', '/app', 'router', 'installing')`), imports
`db/zentao.sql` and `db/dbviews.sql` through upstream's own
`replaceContantsInSQL()` and `appendMySQLTableOptions()`, and then calls
upstream's own `installModel::grantPriv()`, `updateLang()`,
`execPostInstallSQL()`, `enableCache()` and `updateDbSeq()` plus the settings
writes `install/control.php::step5()` makes. Nothing here reimplements the
schema, the privilege seed or the password hashing.

The super-admin password is generated per account by `hooks/prepare.sh` into
`~/.panelalpha/zentao-admin-password` — mode 0600 inside a 0700 directory,
because account homes are root-owned 0755 and an account cannot create a file
directly in its own home — and is never a default. #173 writes `.env.default`
into the checkout 0644 and readable by every other tenant, which is the other
reason nothing secret belongs in `~/project`.

## `config/my.php` holds no secret

ZenTao has a first-class container mode: `config/config.php:191` reads
`IS_CONTAINER`/`IN_CONTAINER`, and `config/config.php:225-245` then takes the
whole database configuration from `ZT_*` environment variables. This recipe
writes a `config/my.php` that does the same thing directly from the engine's own
`DB_*` variables — **the file is a list of `getenv()` calls, so the database
password is never written to disk.** `my.php` is included last
(`config.php:253`), after the env block and after `config/db.php`, so it wins.

`$config->inContainer` is set *in that file* rather than passed as an
environment variable, and that is deliberate: setting `IN_CONTAINER` in the
environment would make `config.php:225-245` take the database configuration from
`ZT_*` variables that do not exist, clobbering it with `false` before `my.php`
is reached. Setting it afterwards buys the two behaviours that matter:

- `router::checkInstalled()` (`framework/base/router.class.php:3681`) stops
  trusting the `installed` flag on its own and also requires the version row in
  the database (`getInstalledVersion()`, `router.class.php:1038`). "Installed"
  becomes a property of the thing that survives a redeploy rather than of a file
  in the checkout, which does not.
- `commonModel::checkSafeFile()` (`module/common/model.php:1168`) returns early,
  disabling upstream's "safe mode" — which otherwise nags on every page until
  somebody creates `www/data/ok.txt` on the filesystem by hand. Reasonable for a
  self-hosted install, unworkable for a hosted one.

## Where the data lives, and what a redeploy does to it

**The database survives.** It is the account's own MySQL, provisioned by
`database: mysql` — visible in the panel, openable in phpMyAdmin, inside the
account's backup. Every product, project, story, task and bug is in there and a
redeploy does not touch it.

**Attachments do not.** `fileModel::setSavePath()`
(`module/file/model.php:533`) puts uploads in
`www/data/upload/<companyID>/<YYYYMM>/`, which is inside the checkout — and
every clone wipes `~/project` (#173). So **a redeploy destroys every uploaded
file while the `zt_file` rows that point at them survive**, leaving an
application that lists attachments it can no longer serve. This is upstream's
layout, not the engine's fault, but #173 is what turns "files in the checkout"
into "files that disappear". Say so to anyone who hosts this.

`www/data/` is also inside the document root. Upstream protects it with nothing;
`files/www/data/.htaccess` adds `Options -Indexes` and denies script execution
there. ZenTao does append `.notAllowed` to extensions outside
`$config->file->allowed` (`module/file/model.php:348`), which is a real defence,
but it is one check in one code path.

## Licensing

**ZenTao is dual-licensed and hosting for third parties is permitted. This is
not a licence rejection.**

`COPYING` states the choice plainly: the source is covered by ZPL 1.2 and
AGPL-3.0, and "You can choose ZPL or AGPL to use zentao" (ZenTao, `COPYING`).

**This recipe's deployments are taken to be under AGPL-3.0.** That is the
election `COPYING` offers, and AGPL-3.0 is OSI-approved, FSF-free and
SPDX-listed, where ZenTao's Z Public License is none of those. Under AGPL the
badgeware condition the ZPL imposes — keeping ZenTao's logo and links in the
running interface — does not apply: AGPL §7 permits requiring preservation of
reasonable legal notices and author attributions, not interface branding. AGPL
§13 is satisfied for an unmodified deployment by pointing at the upstream
repository, which is where this checkout comes from.

**Honest caveats, because a reader who skims will get this wrong.**

- The election is genuinely available but its use here is **unconfirmed**: no
  instance of anyone explicitly exercising ZenTao's AGPL option was found.
- `LICENSE.EN` still calls the ZPL "the default license agreement", while
  `COPYING` — amended 2022-06-08 to drop that wording — presents a plain
  either/or. The two files disagree in emphasis; both permit the choice.
- GitHub's licence detector reports `NOASSERTION` for the repository, and SPDX
  has no `ZPL-1.2` identifier at all (the SPDX `ZPL-*` identifiers are the
  unrelated **Zope** Public License). Automated tooling will not see the AGPL
  half.

**Branding is left exactly as upstream ships it, under either licence.** Under
ZPL §4.5/§4.6 that is mandatory and §4.7 forbids even offering users a tool to
strip it; under AGPL it is merely unnecessary to remove. This follows the
posture the `couchcms/couchcms` recipe took with CPAL: attribution untouched,
and nothing presenting the application as PanelAlpha's own. Were the ZPL relied
on instead, §4.4 would additionally require telling users the service is based
on ZenTao — satisfied by listing it as "ZenTao" in the catalogue. Either way the
recipe ships the application unmodified, so it stays inside ZPL §5 rather than
becoming a derived work under §6.

## Upstream weaknesses, not this recipe's to fix

- **Passwords are bare `md5($password)`** (`module/install/model.php:294`, and
  the user model does the same). Changing it would mean modifying the source and
  becoming a derived work under ZPL §6.
- **Upstream turns `display_errors` back on.** `www/.htaccess` carries
  `php_value display_errors 1` under `<IfModule php_module>` — which is the
  mod_php 8 module name, and the shared base image *is* mod_php
  (`panelalpha-serve.sh` execs `apache2-foreground`). Combined with #185, which
  leaves the platform with `display_errors=1` and no `php.ini` to fix it
  centrally, a notice would be rendered into every visitor's page.
  `hooks/prepare.sh` appends an override that turns it off, guarded so a
  redeploy does not stack copies. Measured after the change:
  `display_errors=0` under Apache. `expose_php` is still `1` — it is
  `PHP_INI_SYSTEM`, so no `.htaccess` can touch it, and `X-Powered-By:
  PHP/8.3.33` is on every response. That half of #185 needs a php.ini in the
  base image and cannot be fixed from a recipe.
- **`POST /api.php/v1/stories` without a `reviewer` array is a fatal.**
  `array_filter($_POST['reviewer'])` in `module/story/zen.php:1252` gets `''`
  from the API's own `batchSetPost()` and throws a `TypeError`, so the request
  is a 500 with an empty body. Clients must send `reviewer` as an array, and
  since force-review is on by default it must be non-empty. Upstream's bug, and
  worth knowing before anyone reports the API as broken.

## Known limitation: version upgrades are not automated

ZenTao migrates through `module/upgrade`, an interactive multi-step wizard keyed
on the version being upgraded *from*, driven by `www/upgrade.php` — which
`.gitignore` also keeps out of the repository. So a clone has no way to run it,
and `www/index.php` would redirect every visitor to a 404 if the checkout were
ever newer than the schema.

`panelalpha-setup.sh` therefore **compares the schema version against the
checkout version on every redeploy and fails the deploy when they disagree**,
rather than serving an application whose code and schema do not match. The
previous container keeps running and the account's data is untouched. Driving
ZenTao's upgrade wizard from the CLI is the obvious next piece of work.

## What was verified

On `mariusz.panelalpha.tools`, 2026-09-20, ZenTao 22.6 (`d5bfff8c`), PHP 8.3,
`--memory-limit=2000`:

- `deploy-ok`, `serving: ok`, HTTP 200, **60.2 s**, every health check passing.
  Install log: `schema: 804 statements executed`, company and super-admin
  created, `seed finished`.
- **Logged in with the generated credential over the public HTTPS domain**, two
  ways: `POST /api.php/v1/tokens` (201, real session token) and the human web
  form at `/user-login.html`, after which `/my-index.html` renders
  `Dashboard - ZenTao` rather than the login page.
- **Created a product, a project and a story through the REST API and read all
  three back** in fresh authenticated requests — `GET /products/1`,
  `GET /projects/1`, `GET /stories/1` — plus `GET /products` and
  `GET /products/1/stories` listing them. The product is also visible in the
  real web UI at `/product-browse.html`.
- The exposure table above, before and after the `www/.htaccess` hardening.
- The upgrade stage re-run in place: `already installed; leaving the database
  alone`, `schema 22.6 matches the checkout`, exit 0, and the product and story
  still readable afterwards.

Not verified: a full engine-driven redeploy. The `#173` re-clone is documented
engine behaviour and the attachment path was established by reading
`fileModel::setSavePath()` and confirming `~/project/www/data/upload/` on the
live account, but no redeploy was exercised end to end here.

## Files

| Path | Why |
| --- | --- |
| `panelalpha.yaml` | `docroot: www`, `database: mysql`, the setup command |
| `hooks/prepare.sh` | admin password into `~/.panelalpha/`, runtime dirs, `display_errors` off |
| `files/panelalpha-setup.sh` | install/upgrade stage driver (repository root, outside the docroot) |
| `files/panelalpha-install.php` | CLI install through upstream's own install model |
| `files/www/data/.htaccess` | no listing, no script execution in the attachment directory |
| `overrides/docker-compose.override.yml` | healthcheck + `ready` service (#90) |
