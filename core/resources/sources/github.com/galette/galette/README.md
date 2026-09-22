# Galette — github.com/galette/galette

Galette, a membership-management web app for non-profit associations (members,
contributions, mailings), GPL-3.0. supported-apps#1185. PHP, backed by the
account's own MySQL. No official Docker image, so this is a **php-strategy**
source recipe. Verified against **stable tag 1.2.1** — deploy this tag, not
master.

## PHP gate

`galette/composer.json` requires `php: >=8.2`, so the engine resolves an in-range
PHP (measured: `panelalpha/php:8.2`, in range 8.1–8.5) and Galette runs clean on
it. Every extension Galette declares (gd, intl, gettext, curl, simplexml,
pdo_mysql, fileinfo, filter, mbstring, session) is already in the engine's php
image, so the stock `composer install --no-plugins` satisfies the platform check
with nothing added.

## Layout: app_root

The repository is not its own application root. `composer.json` and the whole app
tree live under `galette/`; only the build tooling (`package.json`,
`gulpfile.js`, `ui/`) sits at the top. So `app_root: galette` makes `galette/`
the `/app` mount — Composer then resolves `galette/composer.json` and installs
`galette/vendor` — and `docroot: webroot` serves `galette/webroot` (whose
`index.php` front controller keeps `config/` and `data/`, one level up, out of
the served tree). Same shape as the shipped phpBB recipe.

## What a bare deploy gets wrong

A no-recipe control deploy **fails** (verified): the engine's php frontend build
runs `npm ci && npm run build`, but Galette's `build` script is only `npx gulp`,
and `gulpfile.js` require()s `./semantic/tasks/build`, which exists only after the
separate `fomantic-install` step. So the stock build dies with
`Cannot find module './semantic/tasks/build'` (MODULE_NOT_FOUND) and aborts the
whole deploy. None of the three fixes is a change to upstream source:

1. **The asset build never completes.** `files/package.json` overlays a one-line
   build shim: `build` now runs Galette's OWN `fomantic-install` then gulp (the
   same two steps its `first-build` script chains). No dependency version is
   touched, so the shipped `package-lock.json` still matches and `npm ci` passes.
   This is build *configuration* (the ESMira precedent), not a source patch. If a
   future tag changes its dependency set, redeploy that tag with a refreshed
   overlay.

2. **config/ and data/ live in the checkout, wiped every redeploy** (engine#173,
   ~/project is emptied). Galette keeps its DB connection (`config/config.inc.php`,
   which holds the DB password) in `config/` and all uploads/logs/exports/photos
   in `data/`. `overrides/docker-compose.override.yml` bind-mounts `~/.panelalpha`
   into the `app` container as `/pa-data`; `files/galette/panelalpha/galette-setup.sh`
   seeds `~/.panelalpha/galette/{config,data}` once from the fresh checkout, then
   symlinks `/app/config` and `/app/data` onto it. Relational content is in the
   account MySQL (`database: mysql`), which survives on its own. Verified: a
   created member and the admin login both survive `project_rebuild`.

3. **The installer is open to the first visitor.** Galette's
   `webroot/installer.php` never checks whether Galette is already installed — it
   always starts the wizard fresh, so a first visitor could re-point the site at
   their own database. `galette-setup.sh` drives Galette's OWN headless installer
   (`bin/console galette:install`, through `galette-console.php` because upstream's
   `bin/console` assumes the un-flattened repo layout that `app_root` removes)
   **before Apache serves**: it creates the schema from the account MySQL env,
   writes `config.inc.php`, and creates a super-admin named `superadmin` with a
   24-char password generated once into `~/.panelalpha/galette/secrets/admin.txt`
   (0600, reused across redeploys, never logged). Then it removes `installer.php`
   and the unauthenticated `compat_test.php` / `post_contribution_test.php` from
   the document root. Idempotent: a persisted `config.inc.php` means
   already-installed, so a redeploy skips the install and only re-links and
   re-locks.

## First run

The owner gets the super-admin login from
`~/.panelalpha/galette/secrets/admin.txt` (reachable over SFTP). No default
credential works — every guessable pair (admin/admin, superadmin/admin, …) is
refused. `install/` and `installer.php` are closed to anonymous visitors.

engine#231 (port alignment) does not apply: a php/Apache app on the engine's php
image serves on the standard port.

SOURCE: keyed to `github.com/galette/galette`, which clones anonymously
(verified `git ls-remote`). supported-apps#1185.
