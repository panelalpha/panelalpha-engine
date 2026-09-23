# Concrete CMS — `github.com/concretecms/concretecms`

Concrete CMS 9 (9.5.4 on branch `9.5.x` as measured). A PHP CMS whose selling
point is in-context editing: an editor signs in, the page they are looking at
grows a toolbar, and they change the page on the page. MySQL, no other
datastore, no Node build.

Detection needs no help — composer.json and no artisan, so `runtime: php`, and
`index.php` at the repository root, so the document root is the repository root.
Without this recipe the deploy still reports success and every request answers
with a PHP fatal error. `serving-php_error`, HTTP 200, 60.2s.

## What was actually wrong

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

The 158 packages still install. What does not happen is the merge, and
Composer's autoload dump walks the **root package's** requirements — which are
one plugin. Measured on the control deploy:

```
$ composer dump-autoload --no-dev --optimize --no-plugins
Generated optimized autoload files containing 9 classes

$ cat concrete/vendor/composer/autoload_psr4.php
return array(
    'Wikimedia\\Composer\\Merge\\V2\\' => array($vendorDir . '/wikimedia/composer-merge-plugin/src'),
);
```

Nine classes out of 158 packages, and no `Concrete\Core\`. So:

```
GET /  ->  200, 358 bytes
Fatal error: Uncaught Error: Class "Concrete\Core\Foundation\ClassAutoloader"
not found in /app/concrete/bootstrap/autoload.php:11
Stack trace:
#0 /app/concrete/dispatcher.php(29): require()
#1 /app/index.php(2): require('/app/concrete/d...')
```

200, because PHP's `display_errors` prints the fatal into the body and leaves
the status alone — which is exactly what `resources/checks/php/no-fatal-error.yaml`
exists to catch, and it did.

`hooks/prepare.sh` does the merge itself before the build: it copies
`concrete/composer.json`'s package requirements and its `autoload` block into
the root `composer.json`, rebasing the relative paths on `concrete/`. The
engine's own `--no-plugins` install then dumps **9618 classes**, and no
Composer plugin runs — on the host or anywhere else. That is the difference
between a recipe here and adding the three plugins to `INSTALLER_PLUGINS`:
the exception would have to trust them; this trusts none.

Two deliberate narrowings from what the plugin does, both in the hook's
comments: platform requirements (`php`, `ext-*`, `composer-runtime-api`) are
**not** copied — they cannot affect an autoload map, and
`PhpRuntime::requirementFor()` reads composer.json's constraints when the
lock's platform is contradicted, which this lock's is (`platform-overrides:
{"php": "7.3"}` against packages that want 8.x). Copying `"php": "^7.3||^8.0"`
into the root could have moved the project onto a different minor than the
control deploy resolved. And `provide` / `bin` / `config` are not copied
because the install reads the lock.

**The one visible cost.** `require` is part of Composer's lock content-hash, so
the install now prints `Warning: The lock file is not up to date with the
latest changes in composer.json`. It is a warning; `Nothing to install, update
or remove` on a second pass confirms the lock is still what gets installed.

## The other four things the engine cannot infer

**The document root is the repository root, and the repository is in it.**
There is no `public/`, `web/`, `public_html/` or `webroot/` here, so
`PhpDocroot` falls through `CANDIDATES` to the root — correct, and the reason
the app answered 200 at all. It also means everything is one request away. On
the control deploy, measured:

| path | control | with recipe |
|---|---|---|
| `/composer.lock` | 200, 500 080 bytes | 403 |
| `/concrete/vendor/composer/installed.json` | 200, 452 065 bytes | 403 |
| `/concrete/vendor/autoload.php` | 200 (executed) | 403 |
| `/application/config/database.php` | 200 (executed, empty body) | 403 |
| `/README.md`, `/INSTALL.md`, `/phpunit.xml` | 200 | 403 |
| `/.git/*`, `/.env`, `/docker-compose.yml`, `/tests/`, `/build/` | 403 | 403 |

The dotfile, `docker-compose.*` and `panelalpha[-.]` denials and
`Options -Indexes` are the generated vhost's
(`resources/deploy/templates/apache-vhost.stub`) and are not repeated.
`files/.htaccess` adds the rest: three subtree denials, a root-anchored
metadata denial, and "index.php is the only entry point" — every other path
ending in a PHP extension is denied, because Concrete reaches all of them by
`require`. Verified against the dashboard: nothing it links to is a `.php` URL
other than `/index.php/...`, and uploads under `/application/files/` and core
assets under `/concrete/{css,js,images,themes}/` still serve.

The same file carries the pretty-URL rewrite, and `panelalpha/concrete-setup.sh`
turns `concrete.seo.url_rewriting` on to match — without it every link the CMS
generates carries `/index.php/` in it.

**The installer must not be left for a visitor.** An uninstalled Concrete
redirects *every* request to `/install`, and that wizard creates the
administrator: whoever arrives first owns the site.
`concrete/bin/concrete c5:install` is upstream's supported non-interactive
path, and it runs from the install stage before Apache binds, with a password
generated per account into `~/.panelalpha/concrete/concrete.env` at 0600.
On a finished site `/install` answers 404.

One ordering trap, and it cost a deploy: `application/config/database.php`
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
`GitRepository::cloneConfiguredRepository()` empties before every deploy
(engine#173). Three bind mounts in `overrides/docker-compose.override.yml`
move them to `~/.panelalpha/concrete/` — `config/`, `files/`, `packages/` —
and `hooks/prepare.sh` creates them owned by the account at 0700, because
`/home` is world-traversable and `database.php` is the account's MySQL
password in a file.

**And the account has a domain the database does not know about.** Concrete
stores the canonical URL verbatim in `generated_overrides/site.php` and builds
every absolute link and redirect from it; there is no `$VAR` indirection the
way Craft has one. The setup script re-states it from `APP_URL` on every
deploy, so the site follows the account's domain when it changes.

## What was measured

Host `mariusz.panelalpha.tools`, 2026-09-20, with other agents deploying on
the same host at the same time — so the wall-clock numbers are an upper bound,
and the control and the recipe were run the same way minutes apart.

| | control (no recipe) | with recipe |
|---|---|---|
| verdict | `serving-php_error` | `deploy-ok` |
| `serving` | `php_error` | `ok` |
| deploy | 60.2s (engine total 48s) | 90.3s (engine total 78s) |
| `GET /` | 200, `<title>` empty | 200, `Home :: Concrete CMS` |
| rebuild (`POST /projects/<u>/rebuild`) | — | 23s, content and credentials intact |

The ~30s is the install. `c5:install` on its own was timed at 43.5s in a hand
run on the control account (with `atomik_full`, the larger starting point);
`atomik_blank` is the default here and is smaller. The `ready` gate is what
makes `docker compose up -d` return only after the install rather than before
it, which is what the engine's probe would otherwise meet (engine#90).

Memory, after the install with an idle Apache: **108.9 MiB** in the app
container a few minutes after a rebuild, settling to **84.1 MiB** once the
opcache and the request workers go quiet — against the 768m ceiling this file
sets. The account's own container, which is the app plus its Docker daemon,
measures **120–150 MiB** over the same window. The install's own peak was not
measured; it is bounded by the `-d memory_limit=512M` the setup script passes
and is the reason for the ceiling below. `mem_limit: 768m` rather than `ServiceLimits`' 384m default
because the setup script asks PHP for 512M for the install and for `c5:update`;
a cgroup ceiling below that turns an overrun into a kill rather than an error.

Verified past the probe, over the public domain from outside the host:
signed in as `admin`, created and published a page from the dashboard sitemap,
and fetched it with no cookies — 200, `Recipe Verification Page :: Concrete
CMS`, `CCM_USER_REGISTERED = false`. Repeated after a rebuild: still there,
still 200, admin password unchanged (same md5 in the store and in the
container's environment).

## Known, and left alone

* **A page whose URL slug starts `panelalpha-` or `panelalpha.` is 403 for
  everybody.** `apache-vhost.stub:47` denies those basenames so that the
  engine's own files in a flat-PHP document root are not web content. Under a
  front controller every page is a virtual path, and the rule reaches them:
  a page named "PanelAlpha Verification" gets the slug
  `panelalpha-verification` and Apache refuses it before mod_rewrite ever
  runs. Measured: `/panelalpha-verification` → 403, `/panelalphaxyz` → Concrete's
  own 404. Not this recipe's to fix, and nothing here can work around it — a
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
  is not applied. Nothing in this deploy needed it — install, dashboard,
  page creation and rendering are all clean on PHP 8.3 — but it is a real
  difference from an upstream `composer install` and is the first thing to
  suspect if a dependency misbehaves.

## Knobs

All in `~/.panelalpha/concrete/concrete.env`, read into the container as a
second `env_file`. Changing one takes effect on the next deploy; the three
install-time ones do nothing once the site exists.

| name | default | what it does |
|---|---|---|
| `PA_CONCRETE_ADMIN_EMAIL` | `admin@example.com` | the first administrator's email |
| `PA_CONCRETE_ADMIN_PASSWORD` | generated, 20 chars | its password. The username is always `admin` — Concrete's installer has no flag for it |
| `PA_CONCRETE_SITE_NAME` | `Concrete CMS` | the site name |
| `PA_CONCRETE_STARTING_POINT` | `atomik_blank` | upstream's own CLI default: the Atomik theme, page types and templates, and an empty home page. `atomik_full` adds demo pages and images; `elemental_full` is the older theme's |

A copy of the administrator's credentials is written to
`~/project/.panelalpha-admin-password` at 0600 on every deploy, for whoever has
shell on the account. The leading dot and the `panelalpha` in the name are each
independently denied by the generated vhost.
