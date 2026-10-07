# github.com/wikimedia/mediawiki

MediaWiki, the wiki engine behind Wikipedia, deployed from its **git
repository** rather than from a release tarball. Those are two different
artefacts and the difference is the whole of this recipe: the tarball ships
`vendor/` and expects you to write `LocalSettings.php` in a browser; the
checkout ships neither, and ships a `docker-compose.yml` for MediaWiki-Docker
that the tarball does not.

## What goes wrong without it

Detection reads the repository's `docker-compose.yml` (`Using strategy:
compose`) and starts MediaWiki-Docker's development images
(`docker-registry.wikimedia.org/dev/bookworm-php85-fpm`, `…/bookworm-apache2`,
`…/bookworm-php85-jobrunner`). The deploy finishes, and `/` answers HTTP
**500**.

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
| `hooks/prepare.sh` | Creates `~/.panelalpha/mediawiki` (0700), (the first bureaucrat's login is the engine's, `credentials:`, mounted read-only at `/pa/app-credentials.env`), replaces `images/` with a symlink onto the mount, writes a php.ini. |
| `overrides/docker-compose.override.yml` | The `/data` mount, `PA_DOCROOT`, `MW_CONFIG_FILE`, `PHP_INI_SCAN_DIR`, `mem_limit`, a two-request healthcheck and an `alpine:3` `ready` gate. |
| `files/panelalpha-setup.sh` | `maintenance/run.php install` on the first deploy, `maintenance/run.php update` on every one after; writes the per-deploy settings file and upstream's `vendor/.htaccess`. |
| `files/.htaccess` | Denies `vendor/`, `docker-compose.*`, any stray `LocalSettings*`, and `*.log|sql|sqlite|bak|orig|rej|swp|save`. |
| `files/mw-config/.htaccess` | `Require all denied`. |

## Exposure

Bodies, not status codes. MediaWiki has a front controller, so a 404 from
Apache and a 404 from MediaWiki mean opposite things, and a 200 can be a PHP
file that executed and printed nothing rather than a file that leaked.

Denied (403, Apache's own body): `/LocalSettings.php`, `/.env`,
`/docker-compose.yml`, `/docker-compose.override.yml`, `/panelalpha-setup.sh`,
`/panelalpha-entrypoint.sh`, `/.git/config`, `/.git/HEAD`,
`/vendor/autoload.php`, `/vendor/composer/installed.json`, `/mw-config/`,
`/mw-config/index.php`, `/mw-config/config.css`, `/includes/Setup.php`,
`/cache/`, `/maintenance/run.php`, `/languages/messages/MessagesEn.php`,
`/sql/tables.json`, `/tests/phpunit/bootstrap.php`, `/images/` (no listing),
`/.htaccess`.

An uploaded `.php` under `/images/` is **not executed** — upstream's
`images/.htaccess` `php_flag engine off` is in force through the symlink, so it
is served as literal source.

Served on purpose, and all of it is public in a public repository:
`composer.json`, `composer.lock`, `package.json`, `README.md`, `INSTALL`,
`HISTORY`, `Gruntfile.js`. `autoload.php` answers 200 with zero bytes because
it is executed, not shown.

## Known limits

* **The engine's public proxy will not carry a `multipart/form-data` POST.**
  A GET and an `application/x-www-form-urlencoded` POST go through, but a
  multipart POST — any size, with or without a file — is answered
  `302 → https://www.withoutdns.com/internal-server-error.html` and then hangs,
  while the same request inside the container succeeds. This is not MediaWiki
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
  operator who wants a release should ask for one. The `install`/`update` pair
  is exactly what upstream supports on a release branch too, so nothing in the
  recipe is trunk-specific.
