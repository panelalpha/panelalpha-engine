# Galette — github.com/galette/galette

Galette, a membership-management web app for non-profit associations (members,
contributions, mailings), GPL-3.0. supported-apps#1185. PHP, backed by the
account's own MySQL. No official Docker image, so this is a **php-strategy**
source recipe. Verified against **stable tag 1.2.1** and the **develop**
branch, whose layouts differ (below).

## PHP gate

`composer.json` requires `php: >=8.2` (1.2.1) or `>=8.3` (develop), so the engine
resolves an in-range PHP and Galette runs clean on
it. Every extension Galette declares (gd, intl, gettext, curl, simplexml,
pdo_mysql, fileinfo, filter, mbstring, session) is already in the engine's php
image, so the stock `composer install --no-plugins` satisfies the platform check
with nothing added.

## Layout: app_root

The repository is not its own application root. The whole app tree lives under
`galette/`; the build tooling (`package.json`, `gulpfile.js`, `ui/`) sits at the
top. So `app_root: galette` makes `galette/` the `/app` mount — Composer then
resolves `galette/composer.json` and installs `galette/vendor` — and
`docroot: webroot` serves `galette/webroot` (whose `index.php` front controller
keeps `config/` and `data/`, one level up, out of the served tree). Same shape
as the shipped phpBB recipe.

The recipe is found by the repository, not by the ref, and Galette moved its
manifest between them: up to the 1.2 tags `composer.json` is in `galette/`, from
the develop branch on it is at the repository root with
`vendor-dir: galette/vendor/` and autoload paths prefixed with `galette/`. On the
newer layout `hooks/prepare.sh` writes that manifest into `galette/` with the
autoload paths and vendor-dir rebased onto it, and copies the lock beside it.
Neither key is part of the lock's content-hash, so the lock still matches and
Composer installs exactly what it pins.

## What a bare deploy gets wrong

A no-recipe control deploy **fails** (verified): the engine's php frontend build
runs `npm ci && npm run build`, but Galette's `build` script is only `npx gulp`,
and `gulpfile.js` require()s `./semantic/tasks/build`, which exists only after the
separate `fomantic-install` step. So the stock build dies with
`Cannot find module './semantic/tasks/build'` (MODULE_NOT_FOUND) and aborts the
whole deploy. None of the three fixes is a change to upstream source:

1. **The asset build never completes.** `frontend_build` runs Galette's OWN
   `fomantic-install` then gulp instead of the `build` script (the same two steps
   its `first-build` script chains). `package.json` and `package-lock.json` stay
   as the deployed ref ships them, so `npm ci` matches on every ref.

2. **config/ and data/ live in the checkout, wiped every redeploy** (engine#173,
   ~/project is emptied). Galette keeps its DB connection (`config/config.inc.php`,
   which holds the DB password) in `config/` and all uploads/logs/exports/photos
   in `data/`. `overrides/docker-compose.override.yml` bind-mounts `~/.panelalpha/galette`
   (created by `hooks/prepare.sh`) into the `app` container as `/pa-data/galette`; `files/galette/panelalpha/galette-setup.sh`
   seeds `~/.panelalpha/galette/{config,data}` once from the fresh checkout, then
   symlinks `/app/config` and `/app/data` onto it. Relational content is in the
   account MySQL (`database: mysql`), which survives on its own. Verified: a
   created member and the admin login both survive `project_rebuild`.

3. **The installer is open to the first visitor.** Galette's
   `webroot/installer.php` never checks whether Galette is already installed — it
   always starts the wizard fresh, so a first visitor could re-point the site at
   their own database. `galette-setup.sh` drives Galette's OWN headless installer
   (`bin/console galette:install` — the deployed ref's own console, which
   `hooks/prepare.sh` copies into `galette/` because `bin/` is outside `app_root`)
   **before Apache serves**: it creates the schema from the account MySQL env,
   writes `config.inc.php`, and creates the super-admin (`superadmin`) with the
   login the engine generates (`credentials:` in `panelalpha.yaml`), read from
   `~/.panelalpha/app-credentials.env`, mounted read-only. Then it removes `installer.php`
   and the unauthenticated `compat_test.php` / `post_contribution_test.php` from
   the document root. Idempotent: a persisted `config.inc.php` means
   already-installed, so a redeploy skips the install and only re-links and
   re-locks.

## First run

The owner gets the super-admin login from `GET /projects/{name}/app-credentials`
(MCP `app_credentials_get`). An account installed before the engine owned the
login keeps its password: it is adopted from
`~/.panelalpha/galette/secrets/admin.txt`, which the older recipe wrote. No default
credential works — every guessable pair (admin/admin, superadmin/admin, …) is
refused. `install/` and `installer.php` are closed to anonymous visitors.

engine#231 (port alignment) does not apply: a php/Apache app on the engine's php
image serves on the standard port.

SOURCE: keyed to `github.com/galette/galette`, which clones anonymously
(verified `git ls-remote`). supported-apps#1185.
