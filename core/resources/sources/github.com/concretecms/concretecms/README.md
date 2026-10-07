# Concrete CMS — `github.com/concretecms/concretecms`

Concrete CMS 9 (branch `9.5.x`). A PHP CMS whose selling
point is in-context editing: an editor signs in, the page they are looking at
grows a toolbar, and they change the page on the page. MySQL, no other
datastore, no Node build.

Detection needs no help — composer.json and no artisan, so `runtime: php`, and
`index.php` at the repository root, so the document root is the repository root.
Without this recipe the deploy still reports success and every request answers
HTTP 200 with a PHP fatal error.

## What goes wrong

`concretecms/concretecms` keeps its real package manifest in
`concrete/composer.json`. The root `composer.json` requires exactly one
package — `wikimedia/composer-merge-plugin` — and declares **no `autoload`
block at all**; the plugin merges the two at runtime.

`resources/platforms/php.yaml`'s `composer-install` runs
`composer install --no-dev --no-interaction --no-scripts --no-plugins
--optimize-autoloader`, and `PhpHostBuild::mayRunPlugins()` only drops
`--no-plugins` when every `composer-plugin` the lock pins is in
`PhpHostBuild::INSTALLER_PLUGINS`. This lock pins three — the merge plugin,
`mlocati/composer-patcher` and `composer/package-versions-deprecated` — and
none of them is on that list, so the flag stays. That is the correct decision:
a plugin is arbitrary PHP out of a customer repository and the host build runs
on the host daemon.

The packages still install. What does not happen is the merge, and
Composer's autoload dump walks the **root package's** requirements — which are
one plugin. The autoload map holds the merge plugin's own classes and no
`Concrete\Core\`. So:

```
Fatal error: Uncaught Error: Class "Concrete\Core\Foundation\ClassAutoloader"
not found in /app/concrete/bootstrap/autoload.php:11
Stack trace:
#0 /app/concrete/dispatcher.php(29): require()
#1 /app/index.php(2): require('/app/concrete/d...')
```

200, because PHP's `display_errors` prints the fatal into the body and leaves
the status alone — which is exactly what `resources/checks/php/no-fatal-error.yaml`
exists to catch.

`hooks/prepare.sh` does the merge itself before the build: it copies
`concrete/composer.json`'s package requirements and its `autoload` block into
the root `composer.json`, rebasing the relative paths on `concrete/`. The
engine's own `--no-plugins` install then dumps the full class map, and no
Composer plugin runs — on the host or anywhere else. That is the difference
between a recipe here and adding the three plugins to `INSTALLER_PLUGINS`:
the exception would have to trust them; this trusts none.

Two deliberate narrowings from what the plugin does, both in the hook's
comments: platform requirements (`php`, `ext-*`, `composer-runtime-api`) are
**not** copied — they cannot affect an autoload map, and
`PhpRuntime::requirementFor()` reads composer.json's constraints when the
lock's platform is contradicted, which this lock's is (`platform-overrides:
{"php": "7.3"}` against packages that want 8.x). Copying `"php": "^7.3||^8.0"`
into the root could move the project onto a different PHP minor. And `provide` / `bin` / `config` are not copied
because the install reads the lock.

**The one visible cost.** `require` is part of Composer's lock content-hash, so
the install now prints `Warning: The lock file is not up to date with the
latest changes in composer.json`. It is a warning; the lock is still what gets
installed.

## The other four things the engine cannot infer

**The document root is the repository root, and the repository is in it.**
There is no `public/`, `web/`, `public_html/` or `webroot/` here, so
`PhpDocroot` falls through `CANDIDATES` to the root — correct, and the reason
the app answers at all. It also means everything is one request away: without
the recipe `/composer.lock`, `/concrete/vendor/composer/installed.json`,
`/README.md`, `/INSTALL.md` and `/phpunit.xml` are served, and
`/concrete/vendor/autoload.php` and `/application/config/database.php` are
executed on request. With the recipe all of them answer 403.

The dotfile, `docker-compose.*` and `panelalpha[-.]` denials and
`Options -Indexes` are the generated vhost's
(`resources/deploy/templates/apache-vhost.stub`) and are not repeated.
`files/.htaccess` adds the rest: three subtree denials, a root-anchored
metadata denial, and "index.php is the only entry point" — every other path
ending in a PHP extension is denied, because Concrete reaches all of them by
`require`. Nothing the dashboard links to is a `.php` URL other than
`/index.php/...`, and uploads under `/application/files/` and core
assets under `/concrete/{css,js,images,themes}/` still serve.

The same file carries the pretty-URL rewrite, and `panelalpha/concrete-setup.sh`
turns `concrete.seo.url_rewriting` on to match — without it every link the CMS
generates carries `/index.php/` in it.

**The installer must not be left for a visitor.** An uninstalled Concrete
redirects *every* request to `/install`, and that wizard creates the
administrator: whoever arrives first owns the site.
`concrete/bin/concrete c5:install` is upstream's supported non-interactive
path, and it runs from the install stage before Apache binds, with the login
the engine generates (`credentials:` in `panelalpha.yaml`), delivered as
`~/.panelalpha/app-credentials.env` and returned by
`GET /projects/{name}/app-credentials` (MCP `app_credentials_get`). An account
installed before keeps its password, adopted from
`~/.panelalpha/concrete/concrete.env`.
On a finished site `/install` answers 404.

One ordering trap: `application/config/database.php`
decides which of two applications `concrete/bin/concrete` boots. With no
connection file the console runs in installer mode, which is the only mode
`c5:install` works in. With one, it boots the whole CMS before parsing the
command line and dies on `Table 'x.Packages' doesn't exist`
(`Concrete\Core\Package\PackageList::get()`, from `Application.php:235`)
against the empty database it was about to create. The setup script therefore
writes that file only on the **upgrade** path and removes it on the install
one.

**The site's configuration is not code.** Concrete writes the connection,
every setting changed from the dashboard (`generated_overrides/`), the Doctrine
proxies and every uploaded file inside `~/project`, which
`GitRepository::cloneConfiguredRepository()` empties before every deploy. Three bind mounts in `overrides/docker-compose.override.yml`
move them to `~/.panelalpha/concrete/` — `config/`, `files/`, `packages/` —
and `hooks/prepare.sh` creates them owned by the account at 0700, because
`/home` is world-traversable and `database.php` is the account's MySQL
password in a file.

**And the account has a domain the database does not know about.** Concrete
stores the canonical URL verbatim in `generated_overrides/site.php` and builds
every absolute link and redirect from it; there is no `$VAR` indirection the
way Craft has one. The setup script re-states it from `APP_URL` on every
deploy, so the site follows the account's domain when it changes.

## Readiness and memory

The `ready` gate is what makes `docker compose up -d` return only after the
install rather than before it, which is what the engine's probe would otherwise
meet.

`mem_limit: 768m` rather than `ServiceLimits`' 384m default because the setup
script asks PHP for 512M (`-d memory_limit=512M`) for the install and for
`c5:update`; a cgroup ceiling below that turns an overrun into a kill rather
than an error.

## Known, and left alone

* **A page whose URL slug starts `panelalpha-` or `panelalpha.` is 403 for
  everybody.** `apache-vhost.stub:47` denies those basenames so that the
  engine's own files in a flat-PHP document root are not web content. Under a
  front controller every page is a virtual path, and the rule reaches them:
  a page named "PanelAlpha Verification" gets the slug
  `panelalpha-verification` and Apache refuses it before mod_rewrite ever
  runs. Not this recipe's to fix, and nothing here can work around it — a
  `.htaccess` cannot un-deny what the vhost denied.
* **The dashboard's own menu keeps `/index.php/` links** even with
  `url_rewriting` on and the cache cleared. The front end emits clean URLs;
  both forms work.
* **A fresh install shows two dialogs** — a "tell us about your site" survey
  and a data-collection notice. Both are Concrete's, both are dismissible, and
  neither is disabled here: turning off a vendor's telemetry prompt is the
  site owner's decision, not the host's.
* **`application/languages/`** is not bind-mounted. A translation installed
  from the dashboard is re-downloaded after a redeploy. Add a fourth mount if
  that becomes annoying.
* **`updates/`** is not mounted either. A core update downloaded from the
  marketplace would be thrown away by the next deploy, which is right: the
  code here comes from the repository, so the way to move to 9.6 is to deploy
  9.6.
* **`mlocati/composer-patcher` never runs**, so `concretecms/dependency-patches`
  is not applied. Install, dashboard, page creation and rendering do not need
  it, but it is a real difference from an upstream `composer install` and is the first thing to
  suspect if a dependency misbehaves.

## Knobs

All in `~/.panelalpha/concrete/concrete.env`, read into the container as a
second `env_file`. Changing one takes effect on the next deploy; the
install-time ones do nothing once the site exists.

| name | default | what it does |
|---|---|---|
| `PA_CONCRETE_SITE_NAME` | `Concrete CMS` | the site name |
| `PA_CONCRETE_STARTING_POINT` | `atomik_blank` | upstream's own CLI default: the Atomik theme, page types and templates, and an empty home page. `atomik_full` adds demo pages and images; `elemental_full` is the older theme's |

The first administrator's login is not a knob here: the engine generates it
(`PA_CONCRETE_ADMIN_EMAIL`, `PA_CONCRETE_ADMIN_PASSWORD`; the username is always
`admin`, as Concrete's installer has no flag for it) and returns it from
`GET /projects/{name}/app-credentials`.
