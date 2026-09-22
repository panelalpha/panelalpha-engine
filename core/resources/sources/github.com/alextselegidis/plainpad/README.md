# Plainpad (github.com/alextselegidis/plainpad)

Self-hosted note taking: a Laravel 12 JSON API and a Create React App SPA, GPLv3.
This branch is `main` at 1.2.0-beta.1.

The repository is not the application. `server/` is the API, `client/` is the
SPA, and `build.sh` is what joins them for a release: Composer dependencies into
a copy of `server/`, `npm run build` in `client/`, and the bundle copied over
`server/public/`, so a released Plainpad is one directory with `index.html` and
`api.php` beside each other. Nothing in the checkout is in that shape, and that
is the whole of why the deploy failed.

## What was wrong

Detection had nothing to work with. The repository root has no `composer.json`
and no `artisan`, so `php.yaml` and `apps/laravel` both declined. It has a
`docker-compose.yml`, but that is the maintainer's laptop stack — php-fpm built
from `docker/php-fpm`, nginx, MySQL 8 with `MYSQL_ROOT_PASSWORD=secret`,
phpMyAdmin and Mailpit, every port read from a root `.env` that does not exist —
and the compose probe passed on it. What claimed the project was
`platforms/php-plain.yaml`, whose `php-sources` probe walks two levels down and
found `server/server.php`.

That is the `php` strategy with the *repository root* as the application root.
`PhpDocroot::detect()` then looked for an index in `public/`, `web/`,
`public_html/`, `webroot/`, the root and `src/`, and found none — `server/public/`
holds `api.php`, not `index.php`, and the SPA that would have supplied
`index.html` had never been built. With no `PA_DOCROOT`,
`resources/deploy/assets/panelalpha-serve.sh` falls back to `/app`, Apache has
nothing to serve as a DirectoryIndex there, and every request answered 403.
`checks/php/entry-served.yaml` reads a 403 as `serving: missing_entry`, which is
the verdict this recipe fixes.

## Shape: three keys

| Key | Why |
|---|---|
| `app_root: server` | The Laravel application, not the repository, is what gets mounted at `/app`. It also leaves `.git/`, `docker-compose.yml`, `client/`, `docs/` and `build.sh` outside the container entirely, rather than one directory above the document root. |
| `docroot: public` | `server/public/`. Stated rather than probed: the directory has no index until the frontend build has written one into it, and a recipe should not depend on the order of two steps. |
| `database: mysql` | A database on the account's own MySQL server. `server/.env.example` asks for MySQL, the repository ships no compose file the engine would take a sidecar from, and — the deciding part — every deploy wipes and re-clones `~/project`, so SQLite under `server/database/` would be destroyed by the next redeploy while the notes in it were the point of the application. |

`extends: laravel` supplies the rest: PHP 8.2 against `"php": "^8.2"`, Composer
on the host, `key:generate`, `storage:link`, `migrate`, `optimize`.

## The frontend build

A recipe's `stage: build` commands never reach a PHP project.
`HostCompile::runPhpBuild()` reads only `install_command` and `build_command`,
and `PlatformValues::applyResolvedCommands()` projects those from the
**manifest's** build stage — while an app config's `commands` are deliberately
kept out of the manifest (`AppConfig::readManifest()` excludes them) and applied
separately by `StageResolver`, which only the entrypoint stages consult.

What *does* run for a PHP project is `HostCompile::runForPhp()`: it looks for a
`package.json` with a `build` script at the repository root and runs
`npm install && npm run build` in a Node container with `~/project`
bind-mounted. The repository root has no `package.json`, so `files/package.json`
supplies one whose `build` script is `panelalpha/build-client.sh`. That script
does build.sh's steps 3 and 4 and nothing else: install and build in `client/`,
then `cp -R client/build/. server/public/`, then delete `client/node_modules`
(~450 MB the account has no use for once the bundle exists).

Two lines in it are not optional:

- **`npm install`, not `npm ci`.** The committed `client/package-lock.json` is
  out of sync with `client/package.json` upstream: `npm ci` stops on
  `Missing: yaml@2.9.1 from lock file` and installs nothing.
- **`CI=false` around the build.** `DindHostBuilder::toolchainEnv()` exports
  `CI=true` for every host Node build, and react-scripts turns eslint warnings
  into errors when it sees that. This tree has warnings upstream ships and
  releases — two `import/no-anonymous-default-export` in `src/stores/`, one
  duplicate `componentDidUpdate` in `src/views/Notes/Notes.js`, and one per
  locale file.

The SPA is a `HashRouter` with `homepage: "./"`, so every in-app route is
`/#/...` and every asset path is relative: no rewrite rule is needed beyond the
`.htaccess` the repository already ships in `server/public/`, which sends
anything that is not a real file to `api.php`.

## Security

A stock Plainpad has three separate ways in. All three are closed here.

**`server/public/setup.php`** is a public installer. Its only guard is
`file_exists(__DIR__ . '/../.env')`, so between the clone and the moment the
engine writes `server/.env` it is a page that lets the first visitor pick the
database and then calls the install endpoint, which seeds the admin account.
`hooks/prepare.sh` deletes it — the engine installs the application itself and
there is nothing left for it to do.

**`POST /api.php/v1`** is what that installer calls: `ApplicationController::install()`,
unauthenticated, running `migrate:fresh --seed` whenever `Schema::hasTable('migrations')`
is false. The install stage migrates before Apache ever binds, so by the time
anything can reach the endpoint the table exists and it answers 401. Verified on
a live deploy.

**The seeded admin password.** `database/seeders/UsersSeeder.php` creates
`admin@example.org` with `12345678` — printed in the repository's own README, in
the seeder's echo, and identical in every Plainpad ever deployed. This is the
same first-visitor-wins class as Koillection, Outline, NocoDB and Mattermost,
except that here the password is not even unknown. So:

- `hooks/prepare.sh` generates a 20-character password per account and writes it
  to `~/.panelalpha/plainpad-admin` (0600), along with a bcrypt hash (cost 10,
  `config/hashing.php`'s own default) at `server/.panelalpha-admin.hash` (0600).
  `~/.panelalpha` rather than the checkout, because a redeploy re-clones
  `~/project` while the MySQL account in it lives on — a credentials file in the
  checkout would be destroyed and the password lost. The account already owns
  that directory; the home above it is root-owned 755.
- `panelalpha/install.php` puts the hash on the account, **only while the stored
  one still verifies `12345678`**. A password the operator later chooses in the
  application is never reset under them.
- The hash file is kept for the life of the checkout rather than consumed. The
  upgrade stage runs on every container start, so an operator who drops the
  database and restarts gets a fresh `UsersSeeder` row; with the file gone, that
  row would carry the published password on a public site until the next
  redeploy.

Nothing sensitive is web-readable. Measured against the live domain:
`.git/config`, `.env`, `docker-compose.yml`, `.panelalpha-admin.hash`,
`.panelalpha-app-key` and `../.env` all 403 at the proxy; `server/.env`,
`setup.php`, `composer.json`, `artisan`, `storage/logs/laravel.log` and
`docker-compose.override.yml` all 404 through Laravel, because with
`app_root: server` they are not under the document root and most are not even in
the container. `Options -Indexes` in the shipped `.htaccess` means `/static/`
and `/assets/` are 403 rather than listings. The only extra files the document
root serves are the CRA bundle and upstream's own `robots.txt`, `logo.png` and
`web.config` (IIS rewrite rules, no secrets).

## APP_KEY, and why the recipe has to carry it

`ProjectEnvironment::apply()` runs `withGeneratedSecrets()` and
`withoutPublishedSecrets()` over a `.env.example` **at the repository root**, so
a root-level Laravel app gets a real `APP_KEY` before it ever boots.
`materializeNestedEnvExamples()` (`core/app/System/Project/Dind/ProjectEnvironment.php:267`)
copies a nested one verbatim, with neither pass. Plainpad's is
`server/.env.example`, so `server/.env` arrives saying `APP_KEY={KEY}`.

`key:generate` repairs that on the first deploy and only then — it is an
install-stage command on purpose, because rotating the key later throws away
every encrypted value. But every deploy re-clones `~/project`, so the *second*
deploy gets a fresh `server/.env` with `{KEY}` in it and nothing left to replace
it. Measured on a redeploy before this was handled: the site went on serving,
because Plainpad's API routes never resolve the encrypter, but it was running on
a string that is not a key.

So `hooks/prepare.sh` generates one into `~/.panelalpha/plainpad-app-key` (0600)
and `panelalpha/install.php` writes it into `.env` in the install *and* upgrade
stages — before `optimize`, which is a start-stage command and is what compiles
`.env` into the config cache the running application reads. One key, from the
first boot onwards, verified identical across a redeploy. Teaching
`materializeNestedEnvExamples()` the two passes the root path already takes
would retire this half of the recipe.

## Seeding

`migrate` creates four empty tables and stops. Every row Plainpad needs in order
to run — the admin account and the nine `settings` rows the SPA reads — lives in
`DatabaseSeeder`, which upstream runs *only* from the unauthenticated install
endpoint. Since the install stage migrates first, that endpoint can never run
again, so without `panelalpha/install.php` the site would serve a login page
with no account behind it: `deploy-ok`, `serving: ok`, HTTP 200, and unusable.

The seed is guarded on the `users` table being empty and calls upstream's own
`db:seed` rather than a copy of its INSERTs, so `SettingsSeeder` stays the file
that changes when a setting is added. It is the one step that exits non-zero on
failure: an application with no account is a broken deploy and should say so.

## `APP_REPOSITORY`

Restated in `env:` so it becomes a real compose environment variable.
`php artisan optimize` runs on every start and caches the config, and a cached
config means Laravel never loads `.env` at runtime — while
`AutoUpdateServiceProvider` reads `env('APP_REPOSITORY')` directly, outside any
config file. Left in `.env` alone it is null after the first `optimize`, and an
admin's `GET /api.php/v1` then throws a `DownloadException` on
`file_get_contents('/update.json')` on every request.

With it set, the admin is offered upstream's updates as designed. Applying one
unzips a release over the git checkout, which the next redeploy re-clones away;
set `APP_REPOSITORY` to an empty string through the account's env vars to turn
the offer off.

## Readiness

`AppLauncher` runs `docker compose up -d` without `--wait`, and the deploy is
finished when that returns — which for Plainpad is before it answers.
`overrides/docker-compose.override.yml` adds a healthcheck and a no-op `ready`
service (`alpine:3`, `exit 0`, `restart: "no"`) gated on
`condition: service_healthy`, so `up -d` returns only once the application is up.

The check is two requests. `GET /` is the SPA's `index.html` — a static file, so
a 200 proves only that Apache is serving the right directory.
`GET /api.php/v1/notes` with no token is the one that proves anything: the
`custom-token` guard in `AuthServiceProvider` queries the `sessions` table before
it can answer, so a 401 there cannot happen unless the autoloader, `APP_KEY`, the
MySQL credentials and the migration all worked. A half-built database answers
500. Both are read-only, idempotent, and outside the login throttle, so the check
can never lock the operator out. The command contains no `$` — compose
interpolates a healthcheck string before the shell sees it.

An override rather than a replacement compose file: `overrides/docker-compose.yml`
is written into the checkout *before* detection runs, so a compose file in the
project root would make Plainpad look like a compose project and take the
laravel strategy — with it `app_root`, the document root, the Composer pass, the
host Node build and the staged entrypoint — off the table entirely.

## The workstation compose file

`docker-compose.yml` at the repository root is moved to
`docker-compose.workstation.yml` by the prepare hook. `database: mysql` already
stops `RuntimeSidecars` from mining it (`PhpStrategy::apply()` skips sidecar
harvesting entirely when a manifest declares a database), and the engine
overwrites the file with its own anyway — but it is read before that happens,
and a file that is not the application should not be in the way.

## Files

| File | Why |
|---|---|
| `panelalpha.yaml` | `extends: laravel`, `app_root`, `docroot`, `database`, `APP_REPOSITORY`, the install command, and the account of what was wrong |
| `hooks/prepare.sh` | moves the workstation compose aside, deletes `setup.php`, generates and persists the admin password and the `APP_KEY` into `~/.panelalpha/` |
| `files/package.json` | the root `package.json` `HostCompile::runForPhp()` looks for |
| `files/panelalpha/build-client.sh` | build.sh's client build and merge, with `npm install` and `CI=false` |
| `files/server/panelalpha/install.php` | APP_KEY into `.env`, the seed, and the admin password — all three guarded |
| `overrides/docker-compose.override.yml` | a two-request healthcheck plus a `ready` gate |

## Verified

On a 2-core / 3.7 GB engine, account capped at 1200 MB: `deploy-ok`, deploy
**120.6s** with the shared PHP 8.2 base image already built (about 30s of that
is the CRA build), port probe `HTTP 200`, domain `200`, `serving: ok`, all twelve
baseline and `php` checks passing, and HTTP 200 with the title `Plainpad`.

Beyond the status code, against the public HTTPS domain:

- `POST /api.php/v1/sessions` with `admin@example.org` / `12345678` — the
  password upstream seeds — answers **401**. With the password from
  `~/.panelalpha/plainpad-admin` it answers **201** with a session token.
- With that token: `POST /api.php/v1/notes` creates a note (201) and
  `GET /api.php/v1/notes` reads it back with its title, content and pinned flag
  intact. `GET /api.php/v1/settings` returns the nine seeded rows and
  `GET /api.php/v1/users` the single `admin@example.org` account — both
  admin-only routes.
- In a real browser at the public domain, the SPA loads `main.*.js`, its chunks,
  the CSS and its webfonts from the merged document root and renders the login
  screen. A login from that page posts to
  `https://<domain>/api.php/v1/sessions` — so the `REACT_APP_BASE_URL=api.php`
  baked into the bundle resolves correctly through the proxy — and the published
  password is refused there with 401. Reading `/api.php/v1/notes` and
  `/api.php/v1/users` from that page's own origin with a session token returns
  the note and the admin user.
- `POST /api.php/v1` — the unauthenticated `migrate:fresh --seed` endpoint —
  answers 401, and `GET /setup.php` 404.
- The readiness gate was measured with the application stopped and the app
  database's six tables dropped: `docker compose up -d` printed
  `Container project-app-1 Waiting` … `Healthy` before starting `ready` and
  returned after **7.0s**, with `migrate`, both seeders, the APP_KEY write and
  `optimize` inside that window — which is the window a probe without the gate
  lands in.
- That same wipe is the test for keeping the hash file: the re-seed put
  `12345678` back and `install.php` replaced it in the same boot. Afterwards the
  published password answered 401 and the generated one 201.
- A redeploy (`POST /projects/{u}/rebuild`) runs the **upgrade** stage: `migrate`
  reports *Nothing to migrate*, `install.php` reports *the admin account no
  longer has the seeded password; left alone*, `APP_KEY` in `server/.env` is
  byte-identical to `~/.panelalpha/plainpad-app-key`, the note is still there,
  and there is still exactly one user and nine settings rows. The same password
  still logs in.
- `docker compose ps` shows `project-app-1 … Up (healthy)` and
  `project-ready-1 … Exited (0)` after every deploy.
- `~/project` is 61 MB with `client/node_modules` removed.

## Not done

- **The authenticated screens were not driven in a browser.** Every
  authenticated call above was made against the public HTTPS domain — the same
  requests the SPA itself makes, one of them from the page's own origin — but
  nobody typed the password into the login form and watched the notes view
  render. What that would add over the evidence here is the client-side render
  of data already proven to be served.
- **No `overrides/app.sh`.** Plainpad's user API is admin-only and its auth is a
  bearer token in the `sessions` table, so `users:list`, `users:add` and an SSO
  handshake are all reachable — a script that mints a session row directly would
  do it. Nothing here needs it, and it was left out rather than written
  untested.
- **Mail is not configured.** `SettingsSeeder` seeds `smtp.mailtrap.io:2525`
  with no credentials, which is upstream's placeholder. Plainpad needs mail only
  for password recovery, and the one password there is sits in a file instead.
  An admin can set a real server under Settings.
- The engine leaves an empty root-owned `~/project/node_modules` behind (the
  host build's cache mount point) and a two-line `package-lock.json` from the
  root `npm install`. Cosmetic.
