# SPIP — git.spip.net/spip/spip

SPIP 5.0.x-dev, the French CMS *Système de Publication pour l'Internet*
(GPL-3.0). supported-apps#1267. The canonical `git.spip.net/spip/spip` clones
anonymously (verified `git ls-remote`), so the recipe is keyed to it — no mirror
row needed.

SPIP 5 is a **Composer distribution**, not the application tree: git ships only
`spip.php`, `index.php`, `bin/spip`, `config/spip/` and `composer.json`. The
whole application (`ecrire/`, `prive/`, `plugins-dist/`, `squelettes-dist/`,
`vendor/`) is fetched by `composer install` from `get.spip.net`. `spip.php`
boots `SpipHttpKernel` through `vendor/autoload_runtime.php` (symfony/runtime).
`composer.json` requires `php: ^8.4`, so the engine resolves **PHP 8.4**
(in-range 8.1–8.5); HEAD runs clean there (verified: `panelalpha/php:8.4`).
Detection reads it as plain `php` (composer.json, no artisan).

## What the bare deploy gets wrong

A no-recipe control deploy **fails**: the engine's stock php install runs
`composer install --no-dev --no-scripts --no-plugins` (verified in the deploy
log: *"The spip-league/composer-installer plugin was not loaded as plugins are
disabled"*), so the layout plugin never runs, `ecrire/`/`prive/` are left under
`vendor/`, and the kernel 500s on every request. On top of that there is no
database, no persistence, no docroot pinning and a world-open installer.

The recipe fixes it, none of it a change to upstream source:

1. **The application tree isn't laid out.** SPIP's non-standard directory layout
   is placed by `spip-league/composer-installer`, a `composer-plugin` the lock
   pins. The engine disables it (`--no-plugins`), and — see **Engine gap** — a
   source recipe cannot override the host build command. So
   `files/panelalpha/spip-setup.sh` re-runs `composer install` **inside the app
   container** (start stage, before Apache), where SPIP's own
   `config.allow-plugins` limits execution to `composer-installer` +
   `symfony/runtime`. `vendor/` is already resolved by the host build, so this
   is mostly the plugin placing the tree; gated on `ecrire/` being absent, so a
   restart skips it.

2. **URLs, config and media live in the checkout, wiped every redeploy**
   (engine#173). SPIP keeps its DB connection file and crypto keys in
   `config/connect.php` + `config/cles.php` (both under `_DIR_ETC`) and uploaded
   media in `IMG/`. `SPIP_ETC_DIR=/pa-data/spip/config` (manifest env) points
   `_DIR_ETC` at the bind-mounted account home, so connect.php + cles.php +
   mes_options.php survive; because that env also redirects where SPIP loads its
   framework config (`<config-dir>/spip/*.php`, e.g. `logger.php` which defines
   `spip.logger.log_path`), the setup script copies `config/spip/` there too.
   `IMG/` has no env override and is symlinked onto `/pa-data`. Relational
   content lives in the account MySQL (`database: mysql`) and survives on its
   own. `overrides/docker-compose.override.yml` provides the `/pa-data` mount.

3. **The installer is a first-visitor-wins web wizard, and it is closed
   headless.** SPIP 5 ships **no CLI installer**; `ecrire/?exec=install` is a
   multi-step web wizard (DB → create tables → super-admin). Its boot is not
   CLI-safe on an empty database (the SQL-error path renders an HTML template
   and reads HTTP inputs, which aborts under `php-cli`), so the schema and admin
   cannot be created by a plain PHP script. Instead `files/panelalpha/spip-install.sh`
   drives SPIP's **own web wizard** over a throwaway `php -S` bound to
   **127.0.0.1 only**, during the start hook, **before Apache serves** — SPIP's
   real install code, reachable by nothing but the script. The database step is
   answered from `mes_options.php`'s `_INSTALL_*` constants (seeded from the
   account MySQL env), the schema is created, the bundled plugins are activated,
   and the super-admin is created with SPIP's server-side hashing at the
   `etape_3b` POST. The admin password is generated **once** into
   `~/.panelalpha/spip/admin-credentials.txt` (0600) and reused. `adresse_site`
   is corrected to `APP_URL` afterwards (the walk ran over loopback).
   Idempotent: a redeploy finds a webmestre and skips the whole thing.

Hardening: `mes_options.php` and `connect.php` (the DB password) live under
`SPIP_ETC_DIR`, outside the document root. The `.htaccess` is SPIP's own
`htaccess.txt` (dotfiles, composer files, PHP-in-IMG blocked) plus an explicit
403 of `config/` and `tmp/` and a 404 of `htaccess.txt`/`*.md`/`.env.dist`. The
`panelalpha/` tooling ships its own deny `.htaccess`; `.pa-php` is blocked by
the dotfile rule.

## First run (fully automatic — no window)

The install runs in the start hook, which is `before: true`, so it completes
**before Apache binds**: the site is never publicly reachable with an open
installer. The first anonymous visitor gets the finished site (`/` → 200), the
installer already refused (`?exec=install` → 403) and the admin login gate
(`ecrire/` → 302). The owner's credentials are in
`~/.panelalpha/spip/admin-credentials.txt` (0600) — surfaced only into their
store, never into `~/project` or over HTTP. Zero manual steps.

The `php -S` server binds `127.0.0.1` only and is killed as soon as the walk
finishes, so nothing but the script ever reaches the wizard. `PHP_CLI_SERVER_WORKERS`
gives it concurrency, without which SPIP's own self-request during the final
install step deadlocks a single-threaded server. If the walk somehow fails, the
boot still serves and the owner can install by hand — but the verified path
needs no owner action.

## Engine gap (verified, not a recipe fault)

A source recipe cannot override the PHP **build** command. `AppConfig::manifest()`
drops `commands`/`env` (applied separately), and `PhpStrategy::apply()` passes
the base-derived `$decision` — whose `install_command` is php.yaml's
`--no-plugins` default — straight to `HostCompile::runPhpBuild()`; the recipe's
build-stage command is never folded in
(`app/System/Project/Dind/Strategy/PhpStrategy.php:113`,
`app/System/Project/Dind/HostCompile.php:407`). Separately,
`spip-league/composer-installer` is not on `PhpHostBuild::INSTALLER_PLUGINS`
(`app/Lib/Deploy/Platform/Runtime/Php/PhpHostBuild.php:64`), so
`mayRunPlugins()` will not drop `--no-plugins` for it (all-or-nothing with the
whitelisted `symfony/runtime`). Either fix — whitelist the plugin, or fold a
source recipe's build command into the decision — would let this recipe drop the
in-container composer re-run (fix 1). The in-container re-run is the workaround
until then.

## Verified (mariusz2, PHP 8.4, 2026-09-21)

Fresh deploy, no manual steps. Deploy ~18s engine + container-boot layout +
headless install (well within the 300s start-hook budget); app container
**61 MiB**. **Window closed:** the first anonymous request to a freshly deployed
site got `/` → 200 (installed "Mon site SPIP"), `?exec=install` → **403**,
`ecrire/` → 302 — the webmestre was already seeded. Login verified with the
auto-generated password from `~/.panelalpha/spip/` (SPIP `auth_identifier_login`
OK; bad password rejected). An article published through SPIP's editing pipeline
renders on the public site (title, breadcrumb, body, 200). Exposure by body:
`/config/connect.php`, `/config/cles.php`, `/.env`, `/.git/config`, `/tmp/`,
`/IMG/` listing and `/panelalpha/*` all **403**, `/composer.json` 404,
`/htaccess.txt`/`*.md` 404, DB password present nowhere. **Redeploy survival**
(`project_rebuild`): site live with no re-install, `?exec=install` still 403,
seeded admin still authenticates (cles.php persisted), the article persists
(MySQL), an uploaded `IMG/` file persists byte-for-byte (bind mount), and the
seed is a no-op — `spip_auteurs` holds exactly one webmestre, no duplicate.
