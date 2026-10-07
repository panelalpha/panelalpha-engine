# Drupal

Upstream: <https://github.com/drupal/recommended-project>

The Composer project template for Drupal: a relocated document root at `web/`,
`drupal/core-recommended` pinned in `composer.lock`, and nothing else. The
checkout is four files — `LICENSE.txt`, `composer.json`, `composer.lock`, `.git`
— and every byte of the application, the front controller included, is produced
by `composer install`.

Without this recipe the deploy succeeds and every request answers HTTP 403
(`serving: missing_entry`). With it the account gets an installed CMS with the
install wizard closed before the first request.

## What the engine got right on its own

Detection is correct and this recipe does not change it. A `composer.json` with
no `artisan` puts the checkout on the `php` platform and the `php` strategy;
`PhpRuntime` reads `">=8.3.0"` out of `drupal/core`'s lock entry and picks
`panelalpha/php:8.3-apache-bookworm`. Every extension Drupal requires — `gd`,
`pdo_mysql`, `dom`, `SimpleXML`, `mbstring`, `intl`, `zip` — is in the stock
image. `~/project` is bind-mounted at
`/app`, Apache listens on 8000, and `AllowOverride All` on the document root
means Drupal's own scaffolded `.htaccess` — clean URLs, the `FilesMatch` deny
rules, the `sites/default/files` hardening — all work.

## Why the generic platform fails

**Not the document root.** `web` has been in `PhpDocroot::CANDIDATES` all along
(`app/Lib/Deploy/Platform/Runtime/Php/PhpDocroot.php:25`), and
`PhpStrategy::documentRoot()` runs *after* the host Composer build
(`PhpStrategy.php:113` is `runPhpBuild`, `:118` is `writeCompose`), so a
`web/index.php` would be found.

There is none. The host build installs the packages into `vendor/` and creates
no `web/` at all, and the deploy log says why:

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
directory with no `DirectoryIndex` match.

The engine knows all of this already. `PhpHostBuild::INSTALLER_PLUGINS`
(`PhpHostBuild.php:63`) lists exactly the five plugins this lock pins, and its
docblock is written about Drupal by name. It does not fire — see
**Engine defects** below.

## What this recipe does

| | |
|---|---|
| `docroot: web` | States the document root instead of leaving it to be detected, so the answer no longer depends on which Composer flags won. A plain relative path, so `readDocroot()` folding `''` and `'.'` together does not apply. |
| `database: mysql` | `<prefix>_app` on the account's own MySQL server via `AppDatabase::provision()`: panel-visible, phpMyAdmin-openable, in the account backup, password generated once and stable across redeploys. No sidecar, no volume the panel cannot see. |
| install/upgrade command 1 | `composer install` **with plugins**, in the application container. This is the fix for the 403. |
| install/upgrade command 2 | `composer drupal:scaffold`, by name, so the document root is repaired even on a boot where Composer had nothing to install. |
| install/upgrade command 3 | Removes `vendor/drupal/core`, which the host build downloaded into the wrong place. |
| install/upgrade command 4 | `panelalpha-drupal.php`: writes `settings.php`, and on an empty database installs Drupal — before Apache binds. |
| `hooks/prepare.sh` | The persistent store, the hash salt, the bind-mount sources and the mount point. |
| `overrides/docker-compose.override.yml` | The second `env_file:`, the two bind mounts, `PHP_INI_SCAN_DIR`, and a healthcheck that asks two questions. |
| `files/panelalpha/php/zz-drupal.ini` | The container has no php.ini at all. |
| `files/panelalpha-dr.php` | A working entry point for Drupal's own CLI; `vendor/bin/dr` is broken here. |

### The build runs in the container, and that is not a preference

A source recipe cannot change the host build. `AppConfig::readManifest()`
strips `commands` out of the manifest a source recipe produces
(`AppConfig.php:459-463`, *"`commands` and `env` this class applies itself"*),
and the commands it keeps for itself are only ever consumed for the host stages
and for the entrypoint — nothing anywhere reads `$appConfig->commands('build')`.
So `install_command` stays php.yaml's, `--no-plugins` and all.

The install and upgrade stages are where a recipe *can* speak, and the
application container has the same Composer and the same PHP. Running
`composer install` there is not a repeat of the host's work: Composer's
`LibraryInstaller::isInstalled()` tests the path the installer plugin computes,
finds `web/core` missing, reinstalls `drupal/core` into it and regenerates the
autoloader against the new location — `vendor/composer/installed.json` then
reads `"install-path": "../../web/core"`. `COMPOSER_CACHE_DIR` is already baked into the base image pointing at the
account's own `~/.cache/composer`, so a redeploy does not download again.

The cost is one wasted download of Drupal core on the first deploy. Fix
`HostCompile.php:407` and the first two install-stage commands become a no-op
that can be deleted.

`--no-scripts` is kept throughout. The repository defines no `scripts` block,
and Composer 2 still runs plugin *event subscribers* under `--no-scripts`, so
the scaffold runs as part of `composer install`. The explicit
`drupal:scaffold` call is there for the boot where Composer has nothing to
install.

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

Afterwards `/core/install.php` answers **"Drupal already installed"**,
with or without `?profile=` and `?langcode=` parameters.

### On Drupal 11 the `standard` profile is not a standard site

From the outside it looks identical to the missing front controller.

`core/profiles/standard/standard.info.yml` at 11.4-dev installs a module list
and `themes: [default_admin]` and nothing else. Installed from it, the site
bootstraps, serves `/user/login` correctly — and answers **403 Access denied**
to every anonymous visitor on `/`. A Drupal-rendered 403, not Apache's, because
nothing grants the anonymous role `access content`.

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
form in the admin theme.

This recipe does not paper over the difference. Forcing `page.front` would be
wrong in both directions: `/node` 404s on `11.x` because `views.view.frontpage`
was never installed there, and `/admin/welcome` — what
`core/profiles/standard/config/install/system.site.yml` sets — is a 403 for an
anonymous visitor, which would fail the health probe. The owner picks a front
page at `/admin/config/system/site-information`.

### What survives a redeploy

`ProjectTree::clearContents()` empties `~/project` before every clone, which takes `web/sites/default/settings.php` and
`web/sites/default/files` with it. Three things live in `~/.panelalpha/drupal/`
instead — 0600 files in a 0700 directory, created by the hook because the
account home is root-owned 755:

```
~/.panelalpha/drupal/app.env       DRUPAL_HASH_SALT
~/.panelalpha/drupal/files/        the public file system  -> /app/web/sites/default/files
~/.panelalpha/drupal/private/      private files + config sync -> /app/private
```

The administrator login (`DRUPAL_ADMIN_USER`, `DRUPAL_ADMIN_PASS`) is the
engine's: `credentials:` in `panelalpha.yaml`, delivered in
`~/.panelalpha/app-credentials.env` as a third `env_file:` entry and returned by
`GET /projects/{name}/app-credentials` (MCP `app_credentials_get`). An account
deployed before this keeps the password from `~/.panelalpha/drupal/app.env`
(`adopt_from`).

`settings.php` is **not** persisted. It is regenerated from the container
environment on both the install and the upgrade stage, so it cannot drift from
the credentials the engine actually provisioned — and it reads the database
password with `getenv()` rather than carrying a literal, so the account's
database password is never written into a file inside the document root.
`getenv()` reaches mod_php in this image, so the compose `environment:` block is
visible to the web request.

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

The recipe is identical either way. What differs is the Drupal:

| | `11.x` (default branch) | `11.4.7` (tag) |
|---|---|---|
| core | 11.4-dev | 11.4.7 |
| status report | an unsupported-release error | no unsupported-release error |
| `/` for an anonymous visitor | the login form, admin theme | a front page, Olivero |
| front-end theme | none installed | Olivero |

## Exposure

`vendor/` and the Composer files are *outside* the document root (`/app/web`),
so the front controller sees them as ordinary missing routes and there is
nothing to leak. Without the recipe they are served — `installed.json` in
particular is a complete versioned dependency inventory, and
`vendor/autoload.php` executes on request. Drupal's scaffolded `.htaccess`
`FilesMatch` plus the base image's dot-path and `panelalpha[-.]` rules deny
`settings.php`, `services.yml`, `.git/`, `.env`, the compose files and the
engine's own files.

## Memory

One container, no sidecar. No `mem_limit` is set: the generated compose for a
`framework-recipe` PHP project sets none, `ServiceLimits`' 384 MB cap for a
service called `app` does not apply to it, and imposing one here would only
create an OOM risk that does not exist today.

## Engine defects this recipe works around

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
`installCommand()` path never run. `HostCompile::targetPhpMinor()` exists in the
same class and is used at `:480` for the Composer-image pass, so
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
error, no log line and no command. That is the defect, with the file and line.
It should either apply them (merge into `install_command`/`build_command`
alongside the manifest's) or refuse them at load time.

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
