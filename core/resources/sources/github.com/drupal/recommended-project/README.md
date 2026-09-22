# Drupal

Upstream: <https://github.com/drupal/recommended-project> · tracker:
panelalpha/playground/supported-apps#349

The Composer project template for Drupal: a relocated document root at `web/`,
`drupal/core-recommended` pinned in `composer.lock`, and nothing else. The
checkout is four files — `LICENSE.txt`, `composer.json`, `composer.lock`, `.git`
— and every byte of the application, the front controller included, is produced
by `composer install`.

Verdict before this recipe: `deploy-ok`, `serving: missing_entry`, HTTP 403,
44 s. Verdict with it: `deploy-ok`, `serving: ok`, HTTP 200, 58 s, 12 of 12
health checks passing — and an installed, signed-in-able CMS with the install
wizard closed before the first request (see **Verification**).

## What the engine got right on its own

Detection is correct and this recipe does not change it. A `composer.json` with
no `artisan` puts the checkout on the `php` platform and the `php` strategy;
`PhpRuntime` reads `">=8.3.0"` out of `drupal/core`'s lock entry and picks
`panelalpha/php:8.3-apache-bookworm`. Every extension Drupal requires — `gd`,
`pdo_mysql`, `dom`, `SimpleXML`, `mbstring`, `intl`, `zip` — is in the stock
image, and the lock verified against it on the first try ("Verifying lock file
contents can be installed on current platform"). `~/project` is bind-mounted at
`/app`, Apache listens on 8000, and `AllowOverride All` on the document root
means Drupal's own scaffolded `.htaccess` — clean URLs, the `FilesMatch` deny
rules, the `sites/default/files` hardening — all work.

## What went wrong, exactly

**Not the document root.** `web` has been in `PhpDocroot::CANDIDATES` all along
(`app/Lib/Deploy/Platform/Runtime/Php/PhpDocroot.php:25`), and
`PhpStrategy::documentRoot()` runs *after* the host Composer build
(`PhpStrategy.php:113` is `runPhpBuild`, `:118` is `writeCompose`), so a
`web/index.php` would have been found.

There was none. The control deploy resolved 69 packages into `vendor/` and
created no `web/` at all, and said why five times:

```
The "composer/installers" plugin was not loaded as plugins are disabled.
The "drupal/core-composer-scaffold" plugin was not loaded as plugins are disabled.
The "drupal/core-project-message" plugin was not loaded as plugins are disabled.
The "drupal/core-recipe-unpack" plugin was not loaded as plugins are disabled.
The "symfony/runtime" plugin was not loaded as plugins are disabled.
```

For Drupal the plugin *is* the installer. `composer/installers` reads
`extra.installer-paths` and is the only thing that puts `drupal/core` in
`web/core` rather than `vendor/drupal/core`; `drupal/core-composer-scaffold` is
the only thing that writes `web/index.php`, `web/.htaccess`, `web/update.php`
and `web/sites/default/default.settings.php`, none of which exist inside any
package. With both disabled there is no document root, no front controller and
no index file anywhere, so `PhpDocroot::detect()` returns `''`, no `PA_DOCROOT`
is emitted, `panelalpha-serve` serves `/app`, and Apache answers 403 on a
directory with no `DirectoryIndex` match. Measured on the control: `/` → 403,
`entry-served` failing with *"There is no index.php anywhere in ~/project"*.

The engine knows all of this already. `PhpHostBuild::INSTALLER_PLUGINS`
(`PhpHostBuild.php:63`) lists exactly the five plugins this lock pins, and its
docblock is written about Drupal by name. It has never once fired — see
**Engine defects** below.

## What this recipe does

| | |
|---|---|
| `docroot: web` | States the document root instead of leaving it to be detected, so the answer no longer depends on which Composer flags won. A plain relative path, so engine #172 does not apply. |
| `database: mysql` | `<prefix>_app` on the account's own MySQL server via `AppDatabase::provision()`: panel-visible, phpMyAdmin-openable, in the account backup, password generated once and stable across redeploys. No sidecar, no volume the panel cannot see. |
| install/upgrade command 1 | `composer install` **with plugins**, in the application container. This is the fix for the 403. |
| install/upgrade command 2 | `composer drupal:scaffold`, by name, so the document root is repaired even on a boot where Composer had nothing to install. |
| install/upgrade command 3 | Removes `vendor/drupal/core`, 164 MB the host build downloaded into the wrong place. |
| install/upgrade command 4 | `panelalpha-drupal.php`: writes `settings.php`, and on an empty database installs Drupal — before Apache binds. |
| `hooks/prepare.sh` | The persistent store, the hash salt and the administrator password, the bind-mount sources and the mount point. |
| `overrides/docker-compose.override.yml` | The second `env_file:`, the two bind mounts, `PHP_INI_SCAN_DIR`, and a healthcheck that asks two questions. |
| `files/panelalpha/php/zz-drupal.ini` | The container has no php.ini at all (engine #185). |
| `files/panelalpha-dr.php` | A working entry point for Drupal's own CLI; `vendor/bin/dr` is broken here. |

### The build runs in the container, and that is not a preference

A source recipe cannot change the host build. `AppConfig::readManifest()`
strips `commands` out of the manifest a source recipe produces
(`AppConfig.php:459-463`, *"`commands` and `env` this class applies itself"*),
and the commands it keeps for itself are only ever consumed for the host stages
and for the entrypoint — nothing anywhere reads `$appConfig->commands('build')`.
So `install_command` stays php.yaml's, `--no-plugins` and all. Confirmed on the
host: with the recipe declaring a build-stage `role: dependencies` command, the
resolved manifest still reported

```
role='dependencies' run=composer install --no-dev --no-interaction --no-scripts --no-plugins --optimize-autoloader
```

The install and upgrade stages are where a recipe *can* speak, and the
application container has the same Composer and the same PHP. Running
`composer install` there is not a repeat of the host's work: Composer's
`LibraryInstaller::isInstalled()` tests the path the installer plugin computes,
finds `web/core` missing, reinstalls `drupal/core` into it and regenerates the
autoloader against the new location — `vendor/composer/installed.json` then
reads `"install-path": "../../web/core"`. Measured: **8 s**, and
`COMPOSER_CACHE_DIR` is already baked into the base image pointing at the
account's own `~/.cache/composer`, so a redeploy does not download again.

The cost is one wasted download of Drupal core on the first deploy. Fix
`HostCompile.php:407` and the first two install-stage commands become a no-op
that can be deleted.

`--no-scripts` is kept throughout. The repository defines no `scripts` block,
and Composer 2 still runs plugin *event subscribers* under `--no-scripts` —
measured, the scaffold ran as part of `composer install` and did not need the
explicit `drupal:scaffold` to produce a working tree. The explicit call is there
for the boot where Composer has nothing to install.

### The installer is first-visitor-wins

An installed-code, empty-database Drupal sends every request to
`core/install.php`, which takes a site name, a database and an administrator
password from whoever asks first, with no authentication of any kind. On an
account with a public HTTPS domain that window opens the moment the container
binds.

`files/panelalpha-drupal.php` closes it on the install stage, before
`exec panelalpha-serve`. It calls Drupal's own installer rather than
reimplementing it — `install_drupal()` with `'interactive' => FALSE`, the same
call `Drupal\Core\Command\InstallCommand` makes for `dr install`, with the MySQL
driver namespace in place of its hard-coded SQLite one. `settings.php` is
written first, so `install_begin_request()` finds `settings_verified` true
(`install.core.inc:397-400`) and skips the database form entirely.

No drush: it is not in `composer.lock`, so adding it would mean a
`composer require` resolving against Packagist and packages.drupal.org on every
deploy, for one command. Drupal 11 ships its own CLI (`dr`), which covers
`cache:rebuild`, `system:status`, `recipe:apply` and `user:login`.

Measured afterwards: `/core/install.php` answers **"Drupal already installed"**,
with or without `?profile=` and `?langcode=` parameters.

### On Drupal 11 the `standard` profile is not a standard site

This cost a deploy, and from the outside it looks identical to the original bug.

`core/profiles/standard/standard.info.yml` at 11.4-dev installs a module list
and `themes: [default_admin]` and nothing else. Installed from it, the site
bootstrapped, served `/user/login` correctly — and answered **403 Access
denied** to every anonymous visitor on `/`. A Drupal-rendered 403, not Apache's,
because nothing had granted the anonymous role `access content`.

What used to be in the profile is now in `core/recipes/standard/recipe.yml`:
`access content` for anonymous and authenticated, the default theme, the
administrator and content_editor roles, the CKEditor text formats, and ten
further recipes. `install_drupal()` takes it as `parameters.recipe` with an
empty `parameters.profile` (`install.core.inc:321` turns `''` into `FALSE`,
`:823` swaps the profile tasks for the recipe tasks) — which is exactly what
`dr install core/recipes/standard` does. The profile is kept as a fallback for
a Drupal that predates the split.

The node types went the same way and are not in the site recipe either, so
`core/recipes/page_content_type` and `core/recipes/article_content_type` are
applied afterwards through `dr recipe:apply`. Without them Drupal comes up with
the node module installed and no bundles: a CMS with nowhere to put content
until its owner builds a content type by hand. Article and Basic page are what
every Drupal before 11.2 shipped, so this restores what the account owner
expects rather than inventing something. Override with `DRUPAL_RECIPES_EXTRA`
in the project's `env_vars` (empty string to skip).

**Which branch you deploy decides what the front page is, and this is the
clearest reason to name a tag.** On the `11.4.7` tag, `core/recipes/standard`
pulls in `core_recommended_front_end_theme`, imports `views.view.frontpage` and
sets `page.front: /node`: `/` renders a real front page ("Welcome!") in Olivero.
On the `11.x` branch those three lines are gone from the recipe, core's
`system.site` default of `page.front: /user/login` stands, and `/` is the login
form in the admin theme. Both were deployed and measured.

This recipe does not paper over the difference. Forcing `page.front` would be
wrong in both directions: `/node` 404s on `11.x` because `views.view.frontpage`
was never installed there, and `/admin/welcome` — what
`core/profiles/standard/config/install/system.site.yml` sets — is a 403 for an
anonymous visitor, which would fail the health probe. The owner picks a front
page at `/admin/config/system/site-information`.

### What survives a redeploy

`ProjectTree::clearContents()` empties `~/project` before every clone (engine
#173), which takes `web/sites/default/settings.php` and
`web/sites/default/files` with it. Three things live in `~/.panelalpha/drupal/`
instead — 0600 files in a 0700 directory, created by the hook because the
account home is root-owned 755:

```
~/.panelalpha/drupal/app.env       DRUPAL_HASH_SALT, DRUPAL_ADMIN_USER, DRUPAL_ADMIN_PASS
~/.panelalpha/drupal/files/        the public file system  -> /app/web/sites/default/files
~/.panelalpha/drupal/private/      private files + config sync -> /app/private
~/.panelalpha/drupal-admin-credentials.txt
```

`settings.php` is **not** persisted. It is regenerated from the container
environment on both the install and the upgrade stage, so it cannot drift from
the credentials the engine actually provisioned — and it reads the database
password with `getenv()` rather than carrying a literal, so the account's
database password is never written into a file inside the document root.
(Checked that `getenv()` reaches mod_php in this image before relying on it: a
probe page returned `APP_URL`, `SERVERNAME` and `HTTPS` from the compose
`environment:` block.)

The hash salt has to persist because it keys every session cookie, every
one-time login link and every form token. The database password does not:
`AppDatabase::password()` stores it in the account's encrypted details and hands
back the same one on every deploy.

Schema updates are deliberately **not** run on a redeploy. The clone takes a
fresh branch tip and Composer re-resolves the lock that came with it, so two
deploys weeks apart can move Drupal core — and running schema updates unattended
over a customer's data is a decision the operator owns, the same reason the
engine never seeds a database. The upgrade stage prints the code's version and
says to sign in and run `/update.php` if it has moved.

## Deploy it

```json
{"name": "drupal", "git_repo": "https://github.com/drupal/recommended-project"}
```

That clones the `11.x` branch, whose committed `composer.lock` pins
`drupal/core` at **`11.x-dev`** — a development snapshot. Drupal's own status
report says so: *"Unsupported release (version 11.4.7 available). Your version
of Drupal is no longer supported."* That is a property of the repository, not of
the recipe: `composer create-project` resolves to a stable release, a `git clone`
of the default branch does not.

**Name a tag instead.** This is the recommended form:

```json
{"name": "drupal", "git_repo": "https://github.com/drupal/recommended-project", "git_branch": "11.4.7"}
```

The recipe is identical either way and both were deployed and verified. What
differs is the Drupal:

| | `11.x` (default branch) | `11.4.7` (tag) |
|---|---|---|
| core | 11.4-dev | 11.4.7 |
| deploy to `deploy-ok` | 58 s | 59 s |
| `serving` / health | `ok`, 0 of 12 failing | `ok`, 0 of 12 failing |
| status report errors | 1 — unsupported release | **0** |
| status report warnings | 3 | 1 (a Drupal 12 forward-compat notice) |
| `/` for an anonymous visitor | the login form, admin theme | a front page, Olivero |
| front-end theme | none installed | Olivero |

## Verification

Everything below was measured on `mariusz.panelalpha.tools`, over the account's
real public HTTPS domain, on 2026-09-20.

### Timings

| | control (no recipe) | recipe | recipe, redeploy |
|---|---|---|---|
| deploy to `deploy-ok` | 44 s | 58 s | 21 s |
| `serving` | `missing_entry` | `ok` | `ok` |
| HTTP on `/` | 403 | 200 | 200 |
| health checks failing | 1 of 12 (`entry-served`) | 0 of 12 | 0 of 12 |
| Apache bound | immediately (nothing to install) | 14 s after the container started — 2 s *before* `deploy-ok` | 1 s before `deploy-ok` |

`install_drupal()` itself: **6.2 s**, peak 91 MB. The two extra recipes: under a
second each. The same recipe on the `11.4.7` tag: 59 s to `deploy-ok`, 6.0 s to
install, `serving: ok`, 0 of 12 checks failing.

Two accounts were left on the host for inspection: `drupal349`
(`11.x`, deployed then rebuilt) and `drupal349s` (`11.4.7`). The control,
`drupalctl`, is the same repository with the recipe directory absent.

### Past the probe

Driven with a cookie jar over `https://drupal349-e512.panelalpha.online`:

```
GET  /                    200  Drupal 11, not the installer
GET  /user/login          200  login form present
POST /user/login          200  -> /user/1?check_logged_in=1, logged in
GET  /admin               200  85 KB, admin toolbar, not access-denied
GET  /admin/reports/status 200 no errors attributable to the recipe
GET  /node/add/article    200  title field present
POST /node/add/article    200  -> /node/1, "has been created"
GET  /node/1              200  title and body present
GET  /node/1  (anonymous) 200  title present
```

`/admin/reports/status` reports `Memory limit 256M`, `Database: MariaDB
12.2.2`, and — after the `output_buffering` line was added to
`zz-drupal.ini` — no output-buffering warning. What remains is one warning for
a deprecated core module (`Text With Summary Field`, upstream's), one
forward-compatibility notice about `enable_html5_validation` in Drupal 12, and
the unsupported-release error discussed above.

### Exposure

Bodies compared, not status codes. `sha1` of the response body, so an identical
hash means an identical page.

```
                                          recipe            control (no recipe)
/composer.json                            403  339 e1cb9280   200   3448  LEAKED
/composer.lock                            403  339 e1cb9280   200 410375  LEAKED
/vendor/composer/installed.json           404 6779 e97e2c6f   200 207408  LEAKED
/vendor/autoload.php                      404 6779 e97e2c6f   200      0  EXECUTED
/sites/default/settings.php               403  339 e1cb9280
/sites/default/services.yml               403  339 e1cb9280
/sites/default/default.settings.php       403  339 e1cb9280
/sites/default/files/.htaccess            403  339 e1cb9280
/.git/config, /.git/HEAD                  403  339 e1cb9280
/.env                                     403  339 e1cb9280
/docker-compose.yml, .override.yml        403  339 e1cb9280
/panelalpha-entrypoint.sh                 403  339 e1cb9280
/panelalpha-drupal.php                    403  339 e1cb9280
/panelalpha/php/zz-drupal.ini             404 6779 e97e2c6f
/core/lib/Drupal.php                      403  339 e1cb9280
/core/core.services.yml                   403  339 e1cb9280
/core/authorize.php                       403 10303            Drupal's own access-denied
/update.php                               403  157            "you need … Administer software updates"
/core/install.php                         200 10738           "Drupal already installed"
/nonexistent-control-path-xyz             404 6779 e97e2c6f   (the control body)
```

The `11.4.7` deployment was checked the same way and gave the same answer: every
row above 403 except the four that are outside the document root, which come
back as Drupal's 404 with a body byte-identical (`ad448aff`) to a path that was
never there, and `/core/install.php`, which says "Drupal already installed".

`e1cb9280` is Apache's own 403 — Drupal's scaffolded `.htaccess` `FilesMatch`
plus the base image's dot-path and `panelalpha[-.]` rules. `e97e2c6f` is
Drupal's 404, byte-identical to a path that was never there: those files are
*outside* the document root (`/app/web`), so the front controller sees them as
ordinary missing routes and there is nothing to leak. The control's four 200s
are what a Drupal deployed without this recipe hands to anybody who asks —
`installed.json` in particular is a complete versioned dependency inventory.

### Redeploy

`POST /projects/drupal349/rebuild`, 21 s, which re-cloned `~/project` and ran
the upgrade stage:

```
[panelalpha] drupal: wrote /app/web/sites/default/settings.php
[panelalpha] drupal: site already installed; rewrote settings.php and cleared 13 cache tables
[panelalpha] drupal: code is 11.4-dev; if that has moved since the last deploy, sign in and run /update.php
```

After it: `serving: ok`, 0 of 12 checks failing; `~/.panelalpha/drupal/app.env`
byte-identical (same md5); the same administrator password still signs in;
`/node/1` still served to anonymous visitors with its title intact;
`~/.panelalpha/drupal/files` still holding its 484 KB of derived images and
compiled templates; `settings.php` back at mode `0444` with
`$settings['hash_salt'] = getenv('DRUPAL_HASH_SALT')`. A second article was
created and viewed after the rebuild to prove the site was still writable.

### Memory and disk

At rest, a few minutes after the deploy, one visitor:

```
account container (host daemon)   146 MiB   59 PIDs      (11.x, after a rebuild)
  project-app-1                   145 MiB    7 PIDs
  seven Apache prefork workers     45-88 MB RSS each (shared, mostly OPcache)

account container (host daemon)   148 MiB   61 PIDs      (11.4.7)
  project-app-1                   151 MiB    7 PIDs
```

One container, no sidecar. No `mem_limit` is set: the generated compose for a
`framework-recipe` PHP project sets none, `ServiceLimits`' 384 MB cap for a
service called `app` does not apply to it, and imposing one here would only
create an OOM risk that does not exist today.

Disk, per account:

```
~/project           192 MB   (web/ 164 MB is drupal/core, vendor/ the rest)
~/.cache/composer    32 MB   (makes the second deploy's composer install free)
~/.panelalpha       1.3 MB   (grows with the account's uploads)
```

## Engine defects found

**1. The host PHP build never learns what the lock pins.**
`HostCompile::runPhpBuild()` calls `PhpHostBuild::script()` with three of its
six arguments (`core/app/System/Project/Dind/HostCompile.php:407`):

```php
$script = PhpHostBuild::script(
    is_string($decision['install_command'] ?? null) ? $decision['install_command'] : '',
    is_string($decision['build_command'] ?? null) ? $decision['build_command'] : '',
    $hasComposer
);
```

`$phpVersion`, `$lockPhpContradicted` and `$composerLock` all take their
defaults. `$composerLock = null` makes `mayRunPlugins(null)` false for every
project on the engine, so `PhpHostBuild::INSTALLER_PLUGINS` and the whole
`installCommand()` path — two commits, a unit test and a docblock written about
Drupal by name — have never run in production. `HostCompile::targetPhpMinor()`
exists in the same class and is used at `:480` for the Composer-image pass, so
the platform pin is missing from this call too. Cost: every Composer project
whose installer plugins place its files deploys with no application at all.

**2. A source recipe's build-stage commands are inert.**
`AppConfig::readManifest()` (`core/app/Lib/Deploy/Platform/AppConfig/AppConfig.php:459-463`)
excludes `commands` from the manifest array on the grounds that the app config
applies them itself — but it only applies them for the host stages
(`AppConfigBootstrap::runScripts`, `PrepareFromSource::preCheck`) and for the
entrypoint (`StageResolver::commandsFor` via `StageScript`). Nothing reads
`$appConfig->commands(PlatformStage::BUILD)`: the only consumer of build-stage
commands is `$manifest->stage(PlatformStage::BUILD)`
(`PlatformValues.php:162`, `PlatformManifest.php:553`, `StageScript.php:90`),
and the manifest no longer has them. A recipe that writes `stage: build` gets no
error, no log line and no command. This is engine #171 with the file and line.
It should either apply them (merge into `install_command`/`build_command`
alongside the manifest's) or refuse them at load time.

**3. `php artisan api:call` is broken on this build.**
`Api\Call` authenticates an anonymous `Illuminate\Foundation\Auth\User`
(`core/app/Console/Commands/Api/Call.php:40`) and dispatches through the route,
which runs the `api` middleware group. `EnsureTokenMayUseApi::handle()` does
`$request->user()?->currentAccessToken()`
(`core/app/Http/Middleware/EnsureTokenMayUseApi.php:31`) — the null-safe
operator guards a missing *user*, not a user that does not use `HasApiTokens` —
so every call returns 500 `Server Error` with
`BadMethodCallException: Call to undefined method …@anonymous::currentAccessToken()`.
The command's own description says it bypasses middleware; it does not. Either
the anonymous user should be an `App\Models\Admin` (or use `HasApiTokens`), or
the middleware should check `method_exists`/`instanceof HasApiTokens`.

Not an engine defect, but worth knowing: **`vendor/bin/dr` cannot work on any
project whose packages move**. Composer's `BinaryInstaller` skips a bin link
that already exists, so the proxy the host build wrote for
`vendor/drupal/core/scripts/dr` survives the container build's reinstall into
`web/core` and then points at a directory this recipe deletes.
`files/panelalpha-dr.php` is the workaround.

## Licence

GPL-2.0-or-later, for the template and for `drupal/core`. No network-service
clause, no badgeware. Hosting for third parties is permitted, and the
application is shipped unmodified — this recipe adds files beside it and never
patches it.
