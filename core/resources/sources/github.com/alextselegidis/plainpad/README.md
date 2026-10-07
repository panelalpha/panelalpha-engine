# Plainpad (github.com/alextselegidis/plainpad)

Self-hosted note taking: a Laravel 12 JSON API and a Create React App SPA, GPLv3.
This branch is `main` at 1.2.0-beta.1.

The repository is not the application. `server/` is the API, `client/` is the
SPA, and `build.sh` is what joins them for a release: Composer dependencies into
a copy of `server/`, `npm run build` in `client/`, and the bundle copied over
`server/public/`, so a released Plainpad is one directory with `index.html` and
`api.php` beside each other. Nothing in the checkout is in that shape, and that
is the whole of why a plain deploy fails.

## Why the generic platform fails

Detection has nothing to work with. The repository root has no `composer.json`
and no `artisan`, so `php.yaml` and `apps/laravel` both decline. It has a
`docker-compose.yml`, but that is the maintainer's laptop stack — php-fpm built
from `docker/php-fpm`, nginx, MySQL 8 with `MYSQL_ROOT_PASSWORD=secret`,
phpMyAdmin and Mailpit, every port read from a root `.env` that does not exist —
and the compose probe passes on it. What claims the project is
`platforms/php-plain.yaml`, whose `php-sources` probe walks two levels down and
finds `server/server.php`.

That is the `php` strategy with the *repository root* as the application root.
`PhpDocroot::detect()` then looks for an index in `public/`, `web/`,
`public_html/`, `webroot/`, the root and `src/`, and finds none — `server/public/`
holds `api.php`, not `index.php`, and the SPA that would supply `index.html` is
never built. With no `PA_DOCROOT`,
`resources/deploy/assets/panelalpha-serve.sh` falls back to `/app`, Apache has
nothing to serve as a DirectoryIndex there, and every request answers 403.
`checks/php/entry-served.yaml` reads a 403 as `serving: missing_entry`.

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
(the account has no use for it once the bundle exists).

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
anything can reach the endpoint the table exists and it answers 401.

**The seeded admin password.** `database/seeders/UsersSeeder.php` creates
`admin@example.org` with `12345678` — printed in the repository's own README, in
the seeder's echo, and identical in every Plainpad ever deployed. This is the
same first-visitor-wins class as Koillection, Outline, NocoDB and Mattermost,
except that here the password is not even unknown. So:

- The engine generates the password per account (`credentials:` in
  `panelalpha.yaml`, returned by `GET /projects/{name}/app-credentials`, MCP
  `app_credentials_get`) and writes `~/.panelalpha/app-credentials.env` before
  the prepare hook; `hooks/prepare.sh` writes its bcrypt hash (cost 10,
  `config/hashing.php`'s own default) at `server/.panelalpha-admin.hash` (0600).
- `panelalpha/install.php` puts the hash on the account, **only while the stored
  one still verifies `12345678`**. A password the operator later chooses in the
  application is never reset under them.
- The hash file is kept for the life of the checkout rather than consumed. The
  upgrade stage runs on every container start, so an operator who drops the
  database and restarts gets a fresh `UsersSeeder` row; with the file gone, that
  row would carry the published password on a public site until the next
  redeploy.

Nothing sensitive is web-readable: `.git/config`, `.env`, `docker-compose.yml`, `.panelalpha-admin.hash`,
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
it. The site would go on serving, because Plainpad's API routes never resolve
the encrypter, but on a string that is not a key.

So `hooks/prepare.sh` generates one into `~/.panelalpha/plainpad-app-key` (0600)
and `panelalpha/install.php` writes it into `.env` in the install *and* upgrade
stages — before `optimize`, which is a start-stage command and is what compiles
`.env` into the config cache the running application reads. One key, from the
first boot onwards. Teaching
`materializeNestedEnvExamples()` the two passes the root path already takes
would retire this half of the recipe.

## Seeding

`migrate` creates four empty tables and stops. Every row Plainpad needs in order
to run — the admin account and the nine `settings` rows the SPA reads — lives in
`DatabaseSeeder`, which upstream runs *only* from the unauthenticated install
endpoint. Since the install stage migrates first, that endpoint can never run
again, so without `panelalpha/install.php` the site would serve a login page
with no account behind it: a successful deploy, HTTP 200, and unusable.

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
| `hooks/prepare.sh` | moves the workstation compose aside, deletes `setup.php`, hashes the engine's admin password and persists the `APP_KEY` into `~/.panelalpha/` |
| `files/package.json` | the root `package.json` `HostCompile::runForPhp()` looks for |
| `files/panelalpha/build-client.sh` | build.sh's client build and merge, with `npm install` and `CI=false` |
| `files/server/panelalpha/install.php` | APP_KEY into `.env`, the seed, and the admin password — all three guarded |
| `overrides/docker-compose.override.yml` | a two-request healthcheck plus a `ready` gate |

## Known limits

- **No `overrides/app.sh`.** Panel user management (`users:list`, `users:add`,
  SSO) is not provided. Plainpad's user API is admin-only and its auth is a
  bearer token in the `sessions` table.
- **Mail is not configured.** `SettingsSeeder` seeds `smtp.mailtrap.io:2525`
  with no credentials, which is upstream's placeholder. Plainpad needs mail only
  for password recovery, and the one password there is is kept by the engine.
  An admin can set a real server under Settings.
- The engine leaves an empty root-owned `~/project/node_modules` behind (the
  host build's cache mount point) and a two-line `package-lock.json` from the
  root `npm install`. Cosmetic.
