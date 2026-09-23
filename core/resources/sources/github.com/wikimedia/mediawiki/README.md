# github.com/wikimedia/mediawiki

MediaWiki, the wiki engine behind Wikipedia, deployed from its **git
repository** rather than from a release tarball. Those are two different
artefacts and the difference is the whole of this recipe: the tarball ships
`vendor/` and expects you to write `LocalSettings.php` in a browser; the
checkout ships neither, and ships a `docker-compose.yml` for MediaWiki-Docker
that the tarball does not.

Tracker: `panelalpha/playground/supported-apps#363`.

## What the original failure was

Measured on `mariusz.panelalpha.tools`, with no recipe, project `mwctl`:

| | |
|---|---|
| detection | `Detected project type: Docker Compose` → `Using strategy: compose` |
| images started | `docker-registry.wikimedia.org/dev/bookworm-php85-fpm:1.0.0`, `…/bookworm-apache2:1.0.1-s3`, `…/bookworm-php85-jobrunner:1.0.0` |
| deploy | finished successfully in **62 s** |
| serving | `error_page`, HTTP **500** on `/`, 939 bytes |

The page says `MediaWiki 1.47 internal error — Installing some dependencies is
required.` It is **not** the missing `LocalSettings.php`. It is
`includes/PHPVersionCheck.php:150`:

```php
if ( !file_exists( __DIR__ . '/../vendor/autoload.php' ) ) {
```

reached from `PHPVersionCheck::run()` (`PHPVersionCheck.php:340`), which
`index.php:34` calls on its second statement — before MediaWiki loads anything
of its own. The response code comes from `PHPVersionCheck.php:237`:
`header( "$protocol 500 MediaWiki configuration Error" )`.

MediaWiki-Docker never runs `composer install`; upstream's `DEVELOPERS.md`
expects a developer to type it. So on the compose strategy that 500 is
permanent, and no amount of waiting or restarting changes it.

Behind it there is a second failure the compose strategy never reaches:
`/vendor` resolved, MediaWiki with no `LocalSettings.php` answers **200** with
`includes/Output/NoLocalSettings.php`, a page pointing at `/mw-config/` — the
web installer, which is first-visitor-wins.

## What the recipe does

| | |
|---|---|
| `panelalpha.yaml` | `extends: php` — a source recipe is consulted before detection (`PlatformSelector::forContext()` is `fromSource() ?? fromWalk()`, `app/Lib/Deploy/Platform/PlatformSelector.php:47`), so the repository's `docker-compose.yml` is never read and PhpStrategy's generated one is written over it by name. `database: mysql` for a database on the account's own MySQL server. One `install`/`upgrade` command. |
| `hooks/prepare.sh` | Creates `~/.panelalpha/mediawiki` (0700), generates the first bureaucrat's password once (0600), replaces `images/` with a symlink onto the mount, writes a php.ini. |
| `overrides/docker-compose.override.yml` | The `/data` mount, `PA_DOCROOT`, `MW_CONFIG_FILE`, `PHP_INI_SCAN_DIR`, `mem_limit`, a two-request healthcheck and an `alpine:3` `ready` gate. |
| `files/panelalpha-setup.sh` | `maintenance/run.php install` on the first deploy, `maintenance/run.php update` on every one after; writes the per-deploy settings file and upstream's `vendor/.htaccess`. |
| `files/.htaccess` | Denies `vendor/`, `docker-compose.*`, any stray `LocalSettings*`, and `*.log|sql|sqlite|bak|orig|rej|swp|save`. |
| `files/mw-config/.htaccess` | `Require all denied`. |

## Measured

Same host, same repository, same hour.

| | control (no recipe) | recipe |
|---|---|---|
| strategy | `compose` | `php` |
| containers | 3 wikimedia dev images | 1 app + 1 `ready` that exits |
| first deploy | 62 s | **54 s** (55 s on the run before the last two settings lines) |
| redeploy (`POST /projects/mwrec/rebuild`) | — | **29 s** |
| `GET /` | 500, `error_page` | **200**, `serving: ok` |
| app container memory | — | **74.9 MiB** fresh, **137.5 MiB** after the verification traffic, cap 768 MiB |
| account container on the host | 107 MiB | **143–189 MiB** |
| `~/project` on disk | 249 MB | 288 MB (`vendor/` is the difference) |

Past the health probe, over the account's real public HTTPS domain: log in as
the bureaucrat (`clientlogin` → `PASS`, groups `bureaucrat, interface-admin,
sysop`), create a page, edit it again, read both revisions back, render it
anonymously with its wikitext markup intact, find it through the search API
and through `Special:Search`, read it through `api.php` and `rest.php`, upload
a 1200×900 PNG and fetch both the original and a generated 320 px thumbnail
back anonymously with `X-Content-Type-Options: nosniff` and MediaWiki's
sandboxing CSP on both.

Across the redeploy: `LocalSettings.php` byte-identical (so `$wgSecretKey`,
`$wgUpgradeKey` and `$wgDBpassword` unchanged), the bureaucrat's password
unchanged and still accepted, both page revisions still there, the uploaded
file byte-identical and still served with its thumbnail — while a marker file
dropped in `~/project` was gone, which is what proves the re-clone really
happened.

## Exposure

Bodies, not status codes. MediaWiki has a front controller, so a 404 from
Apache and a 404 from MediaWiki mean opposite things, and a 200 can be a PHP
file that executed and printed nothing rather than a file that leaked.

Denied (403, 335-byte Apache body): `/LocalSettings.php`, `/.env`,
`/docker-compose.yml`, `/docker-compose.override.yml`, `/panelalpha-setup.sh`,
`/panelalpha-entrypoint.sh`, `/.git/config`, `/.git/HEAD`,
`/vendor/autoload.php`, `/vendor/composer/installed.json`, `/mw-config/`,
`/mw-config/index.php`, `/mw-config/config.css`, `/includes/Setup.php`,
`/cache/`, `/maintenance/run.php`, `/languages/messages/MessagesEn.php`,
`/sql/tables.json`, `/tests/phpunit/bootstrap.php`, `/images/` (no listing),
`/.htaccess`.

An uploaded `.php` under `/images/` is **not executed** — upstream's
`images/.htaccess` `php_flag engine off` is in force through the symlink. The
control proves it: the identical file answers 32 bytes of executed output at
the document root and 50 bytes of literal source under `/images/`.

Served on purpose, and all of it is public in a public repository:
`composer.json`, `composer.lock`, `package.json`, `README.md`, `INSTALL`,
`HISTORY`, `Gruntfile.js`. `autoload.php` answers 200 with zero bytes because
it is executed, not shown.

## Known limits

* **The engine's public proxy will not carry a `multipart/form-data` POST.**
  A GET is 200 and a `application/x-www-form-urlencoded` POST is 200 on the
  same URL, but a multipart POST — any size, with or without a file — is
  answered `302 → https://www.withoutdns.com/internal-server-error.html` and
  then hangs. The same request made inside the container against
  `127.0.0.1:8000` succeeds and the upload completes. This is not MediaWiki
  and not this recipe; it means `Special:Upload` in a browser cannot work over
  a `panelalpha.online` name until it is fixed.
* Article URLs are `/index.php/Page_Title`, not short `/Page_Title`. Short
  URLs need rewrite rules whose correctness depends on what else is in the
  document root, and MediaWiki's own `$wgUsePathInfo` form works everywhere.
  `GET /` is the main page (`$wgMainPageIsDomainRoot`).
* The job queue is upstream's default `$wgJobRunRate = 1`, run at the end of
  web requests. The MediaWiki-Docker jobrunner container this recipe replaced
  is a development convenience.
* No `overrides/app.sh`. The engine's app-management commands (`users:list`,
  `users:add`, SSO) are not wired up; MediaWiki has `maintenance/createAndPromote.php`
  and `maintenance/resetUserEmail.php` to build one on when that is wanted.
* The wiki is named `Wiki` and the bureaucrat `Admin`, because nothing in the
  deploy knows what the operator wants to call either.
* **The default branch is `master`, which is `1.47.0-alpha`.** This repository
  is the Gerrit mirror, and its HEAD is MediaWiki's development trunk; the
  stable lines are `REL1_41` … `REL1_46`. A recipe cannot pin a branch — the
  branch is an input to the deploy (`git_branch`), not a manifest key — so an
  operator who wants a release should ask for one. Everything here was
  measured on `master`; the `install`/`update` pair is exactly what upstream
  supports on a release branch too, so nothing in the recipe is trunk-specific.
