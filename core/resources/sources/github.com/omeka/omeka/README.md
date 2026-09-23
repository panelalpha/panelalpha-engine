# Omeka Classic

<https://github.com/omeka/Omeka> — tracker issue
[#1002](https://git.modulesgarden.tech/panelalpha/playground/supported-apps/-/work_items/1002).

A digital-collections publishing platform: Zend Framework 1 (vendored in
`application/libraries`), MySQL through mysqli, a public site at `/`, an admin
at `/admin`, and uploaded files plus three derivative sizes under `files/`.
Themes and plugins are git submodules. This recipe was written against
`OMEKA_VERSION = 3.2.1` (commit `ee3faa02`).

**This is not Omeka S.** `omeka/omeka-s` is a separate application with its own
recipe beside this one — Laminas MVC, Doctrine, sites under `/s/<slug>`. The
two share an installer lineage and the shape of their problems, and almost
nothing else: different layout, different config file names, different
installer API, different theme mechanism.

The recipe directory must be lowercase `omeka/omeka`. `RepoUrl::segments()`
lowercases host, owner and repo, so a directory named `omeka/Omeka` matches
nothing.

## What was wrong

Detection was right about everything it decided — `php` strategy, PHP 8.3, the
shared Apache base image, `~/project` bind-mounted, the repository root as the
document root — the deploy reported success in 60 seconds, and every request
answered **HTTP 500** with Omeka's own page:

> Omeka has encountered an error

In production Omeka prints no more than that (`$displayError =
$this->getEnvironment() != 'production'` in `Omeka_Application::_displayErrorPage`).
Re-run under `APPLICATION_ENV=development`:

```
Omeka fatal error: Your Omeka database configuration file is missing.
```

`application/config/application.ini` carries `resources.db.inipath = BASE_DIR
"/db.ini"`; `Omeka_Application_Resource_Db::init()` throws a
`Zend_Config_Exception` on its first line when that file is absent, and
`Omeka_Application::initialize()` turns it into
`header("HTTP/1.0 500 Internal Server Error")` on every path.

Three files are missing for the same reason, and each is only reachable after
the one before it is fixed. All three are in `.gitignore`, all three ship as a
`.changeme` template a human is expected to rename:

| gitignored | template | what it is | symptom without it |
| --- | --- | --- | --- |
| `/db.ini` | `db.ini.changeme` | database credentials | HTTP 500 on every path |
| `/application/config/config.ini` | `config.ini.changeme` | site configuration | HTTP 500, one line later |
| `/.htaccess` | `.htaccess.changeme` | front-controller rewrite | 404 on every route but `/`; `GET /db.ini` returns the MySQL password |

Nothing in a checkout says which database to point the first one at: no `.env`,
no compose file, no `DATABASE_URL`. `database: mysql` is the manifest key that
asks for one.

## What the recipe does

| file | when | why |
| --- | --- | --- |
| `hooks/prepare.sh` | account shell, after clone | `.htaccess` and `config.ini` from upstream's `.changeme` templates; `files/` symlinked out to `~/.panelalpha/omeka`; the super-user password generated; the php.ini staged |
| `files/panelalpha-setup.sh` | install + upgrade stage | writes `db.ini` from `DB_*`, then runs the installer |
| `files/panelalpha-install.php` | called by the above | Omeka's own `Installer_Default`, or its migrations |
| `files/panelalpha-php.ini` | copied to `/data/php` | the php.ini the base image does not load |
| `files/application/logs/.htaccess` | in the checkout | `errors.log` is inside the document root |
| `overrides/docker-compose.override.yml` | compose | the `/data` mount, `PHP_INI_SCAN_DIR`, `mem_limit`, the readiness gate |

### The installer is first-visitor-wins

`install/install.php` renders the form that creates the site's super user, and
the only thing in front of it is `SHOW TABLES LIKE 'omeka_options'`. On a
public HTTPS address that means the first stranger to load `/install` owns the
site. `files/panelalpha-install.php` runs the same `Installer_Default` from the
install stage instead, before Apache binds, with a password generated per
account into `~/.panelalpha/omeka/admin-credentials` (0600, in a 0700
directory). Afterwards `/install` answers *"Omeka is installed."*

Two things the browser does for the web installer had to be done by hand, and
both were found by running it, not by reading it:

* **`Resource matching "Helpers" not found`** — `install/application.ini` asks
  for `resources.layout`; Zend's Layout resource bootstraps FrontController;
  `pluginPaths.Omeka_Application_Resource` makes that resolve to Omeka's own
  Frontcontroller resource, which bootstraps a `Helpers` resource the install
  application does not have. `install/install.php` registers plain
  `Zend_Application_Resource_FrontController` first for exactly this reason.
* **`Route default is not defined`** — `Installer_Task_Options` stores the
  public navigation, which `Omeka_Navigation::getNavigationOptionValueForInstall()`
  builds by assembling URLs through Zend's router. That router only grows its
  `default` route when `Zend_Controller_Front::dispatch()` routes a request, so
  a process that never calls `run()` must call `addDefaultRoutes()` itself.

### A failed install looks exactly like a finished one

Every file in `application/schema` is `CREATE TABLE IF NOT EXISTS`, and MySQL
commits DDL implicitly — so the transaction `Installer_Default::install()`
opens rolls back neither the schema nor the rows the first three tasks wrote.
When the fourth task failed (the router bug above), the database was left in
precisely the state upstream's `isInstalled()` reads as installed. The next
deploy took the migrate branch and replayed fifteen years of migrations against
a current schema:

```
Zend_Db_Statement_Mysqli_Exception: Duplicate column name 'added'
  application/migrations/20100810120000_detachCollectorsFromEntities.php:28
```

and the account was unrecoverable. So the marker this recipe uses is the last
thing a successful install writes — the `omeka_version` option, which
`Installer_Task_Migrations` inserts empty and only `Installer_Task_Options`
fills in. Empty version **and** an empty items table means a site nobody has
used: the `omeka_`-prefixed tables are dropped and the install runs again.
Empty version **with** items means something a person has to look at, and the
script changes nothing and says so.

### The upgrade branch is not optional

While a migration is pending,
`Omeka_Controller_Plugin_Upgrade::dispatchLoopStartup()` answers every public
request with `die("Public site is unavailable until the upgrade completes.")`
— an HTTP **200** carrying one sentence, which a status check would call
healthy. The upgrade stage runs `Omeka_Db_Migration_Manager::migrate()` and
`finalizeDbUpgrade()`, which is what `UpgradeController::migrateAction()` does
when an administrator posts `/admin/upgrade`.

### Thumbnails

Omeka's default derivative strategy is
`Omeka_File_Derivative_Strategy_ExternalImageMagick`, which shells out to
`convert`. The base image ships `ext/imagick` and no ImageMagick CLI at all, so
every upload would fail its derivatives — at upload time, long after the deploy
said it was fine. `prepare.sh` appends
`fileDerivatives.strategy = "Omeka_File_Derivative_Strategy_Imagick"` to
`config.ini`; that is Omeka's own implementation over the extension that is
there.

### Uploads, and where they live

`bootstrap.php` hard-codes both `FILES_DIR = BASE_DIR . '/files'` (where Omeka
writes) and `WEB_FILES = WEB_ROOT . '/files'` (the URL a visitor fetches), so
originals and derivatives are inside the directory a redeploy deletes and
re-clones (engine#173), while the `omeka_files` rows pointing at them survive.
`storage.adapterOptions.localDir` would move the writes but not the URL, so
`prepare.sh` replaces `files/` with a symlink to the `/data` bind mount —
Castopod's arrangement, for the same reason.

The size limit is engine#185: with no php.ini loaded, `upload_max_filesize` is
the compiled-in 2M. Measured — the add-item form says *"The maximum file size
is 2 MB."* without `files/panelalpha-php.ini` and *"128 MB."* with it.

## Two things the engine gets right that this depends on

* **Submodules.** `themes/` and `plugins/` are git submodules *and* in
  `.gitignore`, so a plain checkout has an empty `themes/` and no public theme
  at all — while `Installer_Default` hard-codes `DEFAULT_PUBLIC_THEME =
  'default'`, which is the `theme-thanksroy` submodule.
  `ProjectGit::initSubmodules()` fetches them ("Submodules fetched" in the
  deploy log), and that is the only reason a public page renders.
* **TLS.** `bootstrap.php` picks the scheme of every absolute URL from
  `$_SERVER['HTTPS']` / `HTTP_X_FORWARDED_PROTO`, which the base image's
  `auto_prepend_file` (`panelalpha-proxy.ini`) sets. That is why
  `PHP_INI_SCAN_DIR` lists the image's own `conf.d` first and explicitly: it
  *replaces* the compiled-in path rather than adding to it.

## Verified

Clean deploy, `deploy-ok`, `serving: ok`, HTTP 200 on the public domain, 75s.
Then, on that account:

1. signed in at `/admin/users/login` over the public HTTPS domain with the
   generated credential → 302 `/admin/`, dashboard 200;
2. created an item with a 3.2 MB PNG attached;
3. the public `/items/show/1` renders it, and `/files/original/<hash>.png` and
   `/files/fullsize/<hash>.jpg` both answer 200 — the derivative proves the
   Imagick strategy;
4. redeployed: `~/project` was emptied (a marker file placed there is gone),
   the setup script took the upgrade branch (*"already installed and up to
   date (version 3.2.1)"*), the generated password was not regenerated, and the
   item, the original and the derivatives were all still served.

`db.ini`, `application/config/config.ini`, `docker-compose.override.yml`,
`panelalpha-*` and `application/logs/*` all answer 403.

## Left alone

* **Long-running jobs.** `application/scripts/background.php` under
  `Omeka_Job_Dispatcher_Adapter_BackgroundProcess` runs batch operations an
  administrator starts by hand. The site, the admin area, items, collections,
  file upload and derivative generation are all in-request and unaffected.
* **`admin@<account domain>`.** The installer form requires a valid address for
  the super user and for `administrator_email`, and a hook is told none. The
  account's own hostname is used when ZF1's fixed TLD list accepts it, and
  `admin@example.com` otherwise. An administrator changes it in their own user
  form.
* **`composer.json`, `composer.lock`, `build.xml`, `db.ini.changeme`** answer
  200. They are upstream's own files, carry nothing secret, and denying them
  would mean second-guessing the project's own `.htaccess`.

## Not this recipe's bug

Uploading through the `*.panelalpha.online` edge does not work, and it is not
Omeka. Measured against a deployed account: a `multipart/form-data` POST of
100 KB, 500 KB or 1 MB **stalls** until the client gives up (engine#170), and
one of 2 MB or 5 MB is refused immediately with **HTTP 413**. The same POST to
the account's own address succeeds in 0.3 s. Ordinary form posts (the login)
and every GET go through the edge fine.
