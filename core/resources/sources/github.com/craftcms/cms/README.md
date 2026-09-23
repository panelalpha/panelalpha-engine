# Craft CMS (github.com/craftcms/cms)

Craft is a Yii 2 application: Twig templates on the front, a Vue control panel
at `/admin`, MySQL or PostgreSQL underneath. This repository is Craft's **source
package**, not a site.

```
$ cat composer.json | head
{
  "name": "craftcms/cms",
  ...
  "autoload": { "psr-4": { "craft\\": "src/" } }
$ ls
bootstrap/ src/ lib/ packages/ tests/ composer.json package.json webpack.config.js
```

No `web/`, no `index.php`, no `craft` console script, no `.env` worth the name.
Detection read that correctly — composer.json and no artisan, so the `php`
strategy, PHP 8.2 against `"php": "^8.2"` — and then reported
`serving-missing_entry`, which was the truth about the tree: there is no entry
point in it.

The deployable Craft is **craftcms/craft**, the starter project
`composer create-project craftcms/craft` makes a site from. Opened up, it is
almost nothing: `bootstrap.php`, `web/index.php`, `craft`, a `config/`
directory, a `templates/` directory, and a composer.json whose only real line is
`"craftcms/cms": "^5.9.0"`.

## Why this recipe is not the Seafile recipe

`core/resources/sources/github.com/haiwen/seafile/` covers the same shape of
problem — the repository in the URL is not the product — and answers it by
running the vendor's published image, because that checkout is a *different
program* (the desktop sync client) and contributes nothing.

Here the checkout is Craft. All of it: `src/` is the whole application, and
`src/web/assets/*/dist` is 28 MB of **committed, already-built** control panel
in git. What is missing is the seven files that wrap it, and those are public,
0BSD-licensed and tiny. So the recipe writes them (`files/`) and lets the clone
be the Craft they load, rather than deleting the clone and pulling craftcms/cms
back from Packagist. The code that runs is the code that was cloned, which is
what pasting a repository URL is supposed to mean.

The difference from craftcms/craft is one line. There, Craft is a dependency and
the bootstrap is reached at `vendor/craftcms/cms/bootstrap/web.php`; here the
checkout *is* craftcms/cms, so `bootstrap/web.php` is reached at its own path
and `CRAFT_VENDOR_PATH` has to be stated, because `bootstrap/bootstrap.php`
otherwise derives the vendor directory from sitting four levels down inside
`vendor/` (`dirname(__DIR__, 3)`).

Everything else is the starter project's own file, comments and all:

| From `files/` | What craftcms/craft calls it |
|---|---|
| `web/index.php` | `web/index.php` + `bootstrap.php`, merged (no Dotenv — see below) |
| `web/.htaccess` | `web/.htaccess`, verbatim |
| `craft` | `craft`, minus the shared bootstrap include |
| `config/general.php` | `config/general.php`, plus `@web` and `@webroot` |
| `config/app.php` | `config/app.php`, verbatim |
| `config/db.php` | **not** in craftcms/craft — see *The database* |
| `templates/index.twig` | `templates/index.twig`, rewritten |

No `vlucas/phpdotenv`, and that is deliberate. craftcms/craft loads it in its
bootstrap; craftcms/cms has it in **require-dev** and the engine installs
`--no-dev`, so the class is not there to call. It is also not needed: the
generated compose file passes everything as real container environment
variables and `craft\helpers\App::env()` reads `getenv()` directly.

## What the engine could not infer

### The npm build is Craft's, not the site's

`package.json` declares a `build` script, so `HostCompile::runForPhp()` runs the
project's own package manager on the host — correct for a Laravel or Symfony app
whose `public/build` is made that way. Here it is `webpack --node-env=production`
across 66 control-panel asset bundles, behind a `prebuild` that runs
`prettier --write .` over the whole repository.

And it produces files that are already in git. `src/web/assets/cp/dist/cp.js`,
the fonts, the CSS, all 28 MB of it, is committed — it is what a published
craftcms/cms tarball serves, and the webpack config is how Pixel & Tonic
regenerate it, not something a site runs. `hooks/prepare.sh` renames
`package.json` and `package-lock.json` to `*.upstream-dev`.

### Craft has no SQLite, and does not spell its database variables the way the engine does

`craft\db\Connection` supports `mysql` and `pgsql`; there is no SQLite driver,
so `database: mysql` in the manifest is not a preference — without it Craft
cannot install at all. (This is also why engine#167, `database_path()` doubling,
cannot bite here.)

The engine then hands the container `DB_HOST`, `DB_PORT`, `DB_DATABASE`,
`DB_USERNAME` and `DB_PASSWORD` as compose `environment:` entries
(`PhpEnvironment::mysql()`), and Craft reads `CRAFT_DB_SERVER`,
`CRAFT_DB_DATABASE`, `CRAFT_DB_USER` and so on (`Config::_createConfigObj()`,
env prefix `CRAFT_DB_`). `files/config/db.php` is the translation and the whole
of it. Nothing writes the password to a file.

### The install is a land grab if nobody runs it

An uninstalled Craft answers `/index.php?p=admin/install` with its own
installer, and whoever reaches it first becomes the administrator of the site.
So `php craft install` runs from the **install stage**, before Apache binds,
non-interactively, with a password generated per account. See *Security* below.

### The security key has to outlive the checkout

`CRAFT_SECURITY_KEY` is what Craft hashes and encrypts with: session identities,
the remember-me cookie, verification and password-reset codes, and anything a
field or plugin stores encrypted. Three engine facts collide over it:

- `GitRepository::cloneConfiguredRepository()` empties `~/project` before every
  deploy (engine#173), and the account's MySQL database survives that. A key
  stored beside the code is a new key on every deploy, against data the old one
  wrote.
- `setup/security-key` — which `install` runs through `setup/keys` — writes the
  key it generates into `.env`.
- `ProjectEnvironment::apply()` copies `.env` to `.env.default` at mode **644**.

So `hooks/prepare.sh` generates it once into `~/.panelalpha/craft.env` at 0600,
and `overrides/docker-compose.override.yml` names that file as a **second
`env_file`** — `../.panelalpha/craft.env`, relative to the project directory.
Because the key is already set when `install` runs, `setup/keys` finds
`$generalConfig->securityKey` non-empty and never takes its own branch. The same
file carries `CRAFT_APP_ID` (same story, `setup/app-id`) and the generated
administrator credentials.

`~/project/.env` is left holding a comment and nothing else.

### …and so does everything else Craft keeps

The same argument applies to three directories, which are bind-mounted out of
`~/.panelalpha/craft`:

| Mount | Why it is not in the checkout |
|---|---|
| `/pa` | `CRAFT_LICENSE_KEY_PATH=/pa/license.key` instead of the default `config/license.key`, so the key `api.craftcms.com` issues on first run is not thrown away and re-requested on every redeploy |
| `/app/storage` | `@storage`. Mostly rebuildable caches, but also `storage/rebrand` — the CP logo and site icon an operator uploads — and `storage/backups` |
| `/app/web/uploads` | Craft installs with no asset volume. The first one a customer adds is a *Local* filesystem pointed at a path they type, and the obvious path, `@webroot/uploads`, is inside the checkout. This is that path, made durable, so there is one to recommend |

**Point your first asset volume at `@webroot/uploads`** (base URL `@web/uploads`).
Anywhere else under `~/project` loses its files on the next deploy.

The licence key needed one more thing, and it is a Craft inconsistency rather
than an engine one. `bootstrap/bootstrap.php` reads
`App::env('CRAFT_LICENSE_KEY_PATH')` for its writability probe, but
`Path::getLicenseKeyPath()` reads a **PHP constant** of the same name:

```php
return defined('CRAFT_LICENSE_KEY_PATH') ? CRAFT_LICENSE_KEY_PATH
    : $this->getConfigPath() . DIRECTORY_SEPARATOR . 'license.key';
```

Setting only the environment variable moves the probe and leaves the real key in
the checkout. So `files/web/index.php` and `files/craft` promote the variable to
the constant before the bootstrap runs; `App::env()` falls back to constants, so
the probe still agrees. Verified on the deployed account:
`Craft::$app->getPath()->getLicenseKeyPath()` returns `/pa/license.key`.

All of this rests on one engine fact: the account's home is bind-mounted into
its own DinD container at the same path (`/home/<user> -> /home/<user>`), which
is what makes both the `..` in the `env_file` and the relative bind-mount
sources resolve on both sides.

### Composer's plugins never run — and nothing here can fix that afterwards

`craftcms/cms`'s lock pins two Composer plugins. Neither is on
`PhpHostBuild::INSTALLER_PLUGINS`, so `--no-plugins` stays and neither runs
(engine#168, in its lock-pins-the-wrong-plugins form):

- `yiisoft/yii2-composer` registers `yii2-extension` packages — five of Craft's
  dependencies, including `yiisoft/yii2-queue` — in
  `vendor/yiisoft/extensions.php`, with their `@yii/*` aliases.
- `craftcms/plugin-installer` registers `craft-plugin` packages in
  `vendor/craftcms/plugins.php`. `craftcms/cms` depends on none, so on this
  repository it has nothing to do at deploy time.

The deploy log says so in as many words:

```
The "craftcms/plugin-installer" plugin was not loaded as plugins are disabled.
The "yiisoft/yii2-composer" plugin was not loaded as plugins are disabled.
```

**This recipe deliberately does not try to paper over it, and an earlier draft
that did was wrong.** `composer dump-autoload` looks like the fix — it is what
the bolt/core recipe next door uses, for `symfony/runtime` — and it is not the
fix for either of these. Both write their file from Composer's **Installer**
interface, during the `install`/`update`/`uninstall` of a package of the
matching type; neither subscribes to `POST_AUTOLOAD_DUMP`. Measured on a fully
installed tree: a `dump-autoload` with plugins enabled produced
`vendor/yiisoft/extensions.php` containing `return [];`, and no `plugins.php` at
all. The only thing that would register those five extensions is a `composer
install` with plugins enabled, which is exactly what `--no-plugins` exists to
prevent. So the step was removed rather than kept as a placebo.

(The empty `extensions.php` shows up on a deployed account anyway, and that is
not this recipe: the php manifest's optional `composer run-script
post-autoload-dump` runs without `--no-plugins`, which is enough for
yii2-composer's `activate()` to create the file before the missing script kills
the command.)

It does not matter here. Craft configures every one of those packages
explicitly in `src/config/app.php` by FQCN, so PSR-4 autoloading is enough;
`Application::bootstrap()` guards the extensions file with `is_file()` and
`Plugins::_loadPluginInfo()` guards plugins.php with `file_exists()`. A full
install, migration, control-panel session, schema change and content write were
verified against a tree with an empty `extensions.php` and no `plugins.php`.

What it does mean: **a Craft plugin has to be installed from the control
panel**, not by committing it to `composer.json`. Craft's own
`craft\services\Composer` runs in the container with plugins enabled, so
`plugin-installer` does its job there. A `craft-plugin` added to composer.json
and deployed would land in `vendor/` unregistered and invisible to Craft.

## Security

`php craft install` is Craft's first-run setup and it has a web face. Left to a
visitor it is an open administrator account on a public URL. The install stage
runs it instead, before anything is listening:

- `hooks/prepare.sh` generates the password with `openssl rand -base64 18` under
  `umask 077` in a subshell — the umask has to cover the redirection that
  creates the file, and must not leak into the rest of the script, where a
  stray `077` leaves directories the engine (www-data) cannot scan while it
  walks the tree for the document root.
- It is stored in `~/.panelalpha/craft.env` (0600), outside the checkout, so a
  redeploy reuses the password the database actually holds instead of writing a
  new one the database never learns.
- A copy goes to `~/project/.panelalpha-admin-password` (0600), which is where a
  human is pointed. That file is outside the document root (`web/`) twice over,
  and the engine's Apache vhost denies both dotfiles and `panelalpha[-.]` names
  regardless.
- `files/panelalpha/craft-setup.sh` passes it on the command line to
  `craft install --interactive=0`, and `install/check` makes the whole step a
  no-op on every deploy after the first.

Measured on the deployed account: `/index.php?p=admin/install` answers 302 to
the login form, `/admin` 302 to `/admin/login`, and `.env`, `.env.default`,
`.git/config`, `docker-compose.yml`, `.panelalpha-admin-password` and
`cpresources/` are 403 while `config/db.php`, `config/general.php`,
`config/license.key`, `composer.json`, `composer.lock`, `src/Craft.php`,
`vendor/autoload.php`, `storage/`, `bootstrap/bootstrap.php`,
`templates/index.twig` and `package.json.upstream-dev` are 404 — `docroot: web`
means none of them is under the document root at all.

## Readiness

`AppLauncher` runs `docker compose up -d` without `--wait`, so the deploy is
finished when the containers have been *started* (engine#90). `ready` — alpine,
`exit 0`, `restart: "no"` — waits on the app's healthcheck, which makes `up -d`
return only once Craft answers. A clean exit 0 is explicitly not a crash loop to
`AppHealth::isCrashing()`.

The healthcheck makes two requests, and the second one is the point:

1. `GET /` — `templates/index.twig`, rendered. A 200 means the schema exists,
   Craft booted against it and Twig compiled.
2. `GET /admin/login` — the control panel's own login form. It proves the CP
   routes loaded and the published resources are there, and it is the request
   that would be answering the *installer* if the install stage had not run.

## Editions and licence — read this before selling it

Craft is **commercial software**. `LICENSE.md` is a proprietary licence, not an
OSS one. It grants permission to use, copy, modify, merge, publish and
distribute, subject to five conditions — of which the two that matter to a host
are *"Each licensed copy of the Software shall be actively installed in no more
than one production environment at a time"* and a prohibition on altering or
circumventing the licensing features.

A fresh install is **Solo**, and Solo is free:
`src/migrations/Install.php` writes `'edition' => CmsEdition::Solo->handle()`,
and the system report on the deployed account reads `Craft Solo 5.11.3`.

What Solo actually is, from the source rather than the marketing page:

- **One user account.** `Users::getMaxUsers()` returns 1 for Solo, 5 for Team,
  null (unlimited) for Pro and Enterprise, and `canCreateUsers()` refuses past
  it. The *Users* section disappears from the CP nav
  (`Cp::nav()`), there are no user groups or permissions, 2FA cannot be
  required (`Auth::is2faRequired()` returns false), and the Author and Last
  Edited By columns are hidden from entry indexes (`Entry::defineTableAttributes()`).
- **Everything else is there.** Unlimited sections, entry types, fields,
  categories, assets, globals; multi-site; the GraphQL API; the Plugin Store;
  Twig templating; drafts and revisions. It is a complete CMS for one editor.

Team, Pro and Enterprise are per-project purchases made from the control panel
(craftcms.com/pricing, at the time of writing $279 / $399 one-off with a year of
updates, then $99/year; Enterprise on request). The engine does nothing about
them either way: a customer who buys one enters the key in the CP and it is
written to `/pa/license.key`, which is why that path is on a volume that
survives a redeploy.

Practically: a host can offer Craft, and every account gets a working free Solo
site. A multi-editor Craft is a paid upgrade the customer buys themselves.

## What was actually verified

On `mariusz.panelalpha.tools`, `--memory-limit=2000`, three fresh accounts and
three redeploys. The last fresh account and the last redeploy were on exactly
the configuration in this directory.

- **Deploy.** `deploy-ok`, `serving: ok`, HTTP 200 on the account's own domain,
  all twelve health checks pass, three runs out of three. 136–166 s end to end
  on a shared host, most of which is account provisioning under load: the
  application's own share is about 7 s clone, 1 s prepare, 11 s `composer
  install` from the committed lock, and 16 s for `docker compose up -d`
  *including* the whole install stage behind the readiness gate. The first
  deploy on a host with no PHP 8.2 image costs about 4½ minutes more to build
  it. A redeploy is 28–48 s.
- **Logged in.** `POST users/login` over the public HTTPS domain with the
  generated password from `~/project/.panelalpha-admin-password` → `admin:
  true`. A wrong password on the same endpoint is a 400.
- **Authenticated pages render.** `/admin/dashboard`, `/admin/settings`,
  `/admin/utilities/system-report` and `/admin/settings/sections/new` all 200
  with their own titles. The system report reads `Craft Solo 5.11.3`, PHP
  8.2.33, MariaDB 12.2.2, Imagick 3.8.1.
- **The CMS works, not just the login.** Created an entry type, a `Blog`
  section and a published entry through the control panel; the section and
  entry type appear as YAML under `config/project/`, so project-config writes
  land on disk. Saving *Settings → General* rewrote `timeZone` in
  `project.yaml`.
- **Redeploy keeps the account.** After a rebuild: a session cookie issued
  *before* it still authenticated — which is the direct test that
  `CRAFT_SECURITY_KEY` survived, since Craft validates the identity cookie with
  it — the stored password still logged in, and `config/project/`, wiped with
  the rest of the checkout, was regenerated from the database with the
  `timeZone` change intact (`ProjectConfig::areChangesPending()` regenerates
  external config when it finds none). The upgrade stage skipped the install and
  ran `craft up`: *"No new migrations found."*
- **In-container state.** `Craft::$app->getPath()->getLicenseKeyPath()` →
  `/pa/license.key`, `getStoragePath()` → `/app/storage` (both bind mounts),
  edition `Solo`, security key set, app id from the environment, and the primary
  site's stored base URL is the literal `$APP_URL`, resolving to the live
  domain.
- **Exposure.** `.env`, `.env.default`, `.git/config`, `.git/HEAD`,
  `docker-compose.yml`, `.panelalpha-admin-password`, `cpresources/` and
  `uploads/` answer 403; `config/db.php`, `config/general.php`,
  `config/project/project.yaml`, `composer.json`, `composer.lock`,
  `src/Craft.php`, `src/config/app.php`, `vendor/autoload.php`,
  `bootstrap/bootstrap.php`, `templates/index.twig`, `storage/`,
  `package.json.upstream-dev`, `craft` and `CHANGELOG.md` answer 404. On disk,
  `~/.panelalpha/craft.env` is 0600 and `~/project/.env.default` is 644 and
  contains no secret — which is the point of the split.
- **The installer is shut.** Anonymously, `/index.php?p=admin/install` follows
  through to `/admin/login`; the word "install" does not appear in the response.
- **Footprint.** ~100 MB resident against a 768 m ceiling, 181 MB in
  `~/project` (83 MB checkout + 66 MB vendor).

Not verified, and worth knowing:

- The licence key. `/pa/license.key` holds the four bytes
  `bootstrap/bootstrap.php` writes as its writability probe; `api.craftcms.com`
  had not issued a real key by the time the accounts were torn down. The *path*
  is verified, the key is not.
- An asset volume end to end. `/app/web/uploads` is mounted and writable by the
  container's uid, but no filesystem was created in the CP and no file uploaded
  through it.
- Mail, the Plugin Store, and updating Craft from the control panel. See
  *Not configured*.
- PostgreSQL. `files/config/db.php` has a `pgsql` branch because Craft supports
  one; the engine only ever provisions MySQL, so it has never run.

## Not configured

- **Mail.** Craft sends verification and password-reset messages through the
  `mailer` component, which defaults to PHP's `mail()` — nothing the container
  has. Configure SMTP in *Settings → Email*. Nothing here does it, and nothing
  here depends on it, because the administrator is created with a known
  password rather than an invitation.
- **`backupOnUpdate`.** Left at Craft's default (true), but the shared PHP base
  image has no MySQL client in it — there is no `mysql-client` line in
  `resources/deploy/templates/dockerfile/php-base.stub` — so the backup Craft
  would take before an update has no `mysqldump` to run. The deploy path is
  unaffected (`craft-setup.sh` passes `--no-backup`), but a CP-initiated Craft
  update and the *Database Backup* utility will both report a failed backup.
  Take the account's own backup from the panel instead.
- **Updating Craft from the control panel — don't.** Craft's updater
  (`craft\services\Composer`) updates Craft by requiring `craftcms/cms` in the
  project's `composer.json`. Here that file *is* `craftcms/cms`'s own, so it
  would be asking the root package to require itself. Not tested; the safe and
  correct route is to change the branch or tag the project is deployed from and
  redeploy, after which `craft up` in the upgrade stage runs whatever migrations
  the new code brought — which is how this recipe expects Craft to move.
- **Plugins** must come from the Plugin Store, not from `composer.json`. See
  *Composer's plugins never run*. Untested either way.
- **No `overrides/app.sh`.** Craft has `users/create`, `users/set-password` and
  a full element API, so user management and SSO are both reachable; nothing
  here advertises them yet. On Solo there is only ever one user to manage.
- **The queue** runs on web requests (`runQueueAutomatically`, Craft's default).
  Good enough for a single-tenant site; a busy one wants
  `php craft queue/listen` under supervisor.
