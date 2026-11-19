# S-Cart (github.com/gp247net/s-cart)

A Laravel e-commerce platform: storefront, cart, checkout, CMS content, an
admin panel and an extension system. The repository is a thin Laravel 13
skeleton — `routes/web.php` is six lines and returns a welcome view — and the
shop is three Composer packages that register everything themselves:
`gp247/core` (admin shell, users, settings, extensions), `gp247/front` (the
storefront template) and `gp247/shop` (catalogue, orders, payments).

Detection picks `laravel`, from `composer.json` and `artisan`, and is right
about everything that follows from it — PHP 8.3 against `"php": "^8.3"`,
`public/` as the document root, Composer and the Vite build on the host, the
account's public https URL in `APP_URL`. Without the recipe the deploy reports
success, nothing answers on 8000 and the domain gives 502.

## Signing in

Open `/gp247_admin` (a 302 to `/gp247_admin/auth/login`) and sign in with the
login the engine generates, `SCART_ADMIN_USER` (default `admin`) and
`SCART_ADMIN_PASSWORD`: read it with `GET /projects/{name}/app-credentials`
(MCP `app_credentials_get`). See
[3. The admin account](#3-the-admin-account-is-admin--admin).

## Why the generic platform fails

### 1. Half of the workstation compose file was adopted

`docker-compose.yml` in the repository root is a laptop stack: an `app`
php-fpm built from `docker/php` with xdebug, an nginx `webserver` publishing
`8000:80` and speaking FastCGI to `app:9000`, a `queue`, a `scheduler`, a
profile-gated `mysql-local`, and a `node` whose command is
`npm ci || npm install && npm run dev -- --host 0.0.0.0`.

`RuntimeSidecars` reads that file for the backing services a repository implies
but does not run. `app`, `queue` and `scheduler` are recognised as the
application and dropped; `mysql-local` is dropped as opt-in (`profiles:`).
`webserver` and `node` are neither, so they are kept:

```
Keeping runtime services from compose: webserver, node
```

and merged into the generated stack. The nginx then answers on 8000 by proxying
FastCGI to an `app:9000` that does not exist here — in the generated stack the
application is Apache on 8000 in the shared PHP base image — and the domain
answers 502. `node` would meanwhile run `npm install` at container start and
hold a Vite dev server open on the account.

`hooks/prepare.sh` moves the file into `docker/`. It has to move
`docker-compose.prod.yml` too: with the primary name gone, the fallback scan
globs `docker-compose.*.yml` in the project root for a stack template, and that
file describes the same six services. The glob does not descend, so `docker/` is
far enough away. Moved rather than deleted — it is the customer's repository and
that file is how they run it on their own machine.

The hook is the right place because an app config's `hooks/prepare.sh` runs
*before* detection (`AppConfigBootstrap`), which is before anything reads the
checkout for compose files or for `.env`.

### 2. Nothing installed the shop

`php artisan migrate --force` creates `users`, `cache` and `jobs` and stops.
Every `gp247_*` table, the seeded menus, roles, permissions, languages, store
and admin user, the `GP247Front` template published into `app/GP247` and
`public/GP247`, and `storage:link`, all come from `php artisan gp247:install`
(`GP247\Core\Commands\InstallAll`), which runs `gp247:core-install` →
`gp247:front-install` → `gp247:shop-install`.

#### It cancels its own migrations in production

None of the three install commands passes `--force` to the migrations it runs —
`$this->runArtisan('migrate', ['--path' => …])` in `GP247\Core\Commands\Install`,
`GP247\Front\Commands\FrontInstall` and `GP247\Shop\Commands\ShopInstall`
alike — and Laravel's `ConfirmableTrait` stops an unforced migration in
production. In a non-interactive session the confirmation defaults to no, so the
install logs three cancelled migrations reported as successes:

```
APPLICATION IN PRODUCTION.
WARN  Command cancelled.
---------------> Migrate default done!
```

and then the seeders running against tables that were never created:

```
SQLSTATE[42S02]: Base table or view not found: 1146
Table 'scart.gp247_admin_menu' doesn't exist
```

The seeders themselves are called with `'--force' => true`, so the omission is
only on the migrate calls, and there is no flag or environment variable that
supplies it from outside. `panelalpha/install.sh` runs that one process as
`APP_ENV=local` — the narrowest lever there is. It lasts for the length of the
install, it is not written to `.env`, and every request the account serves
afterwards is `APP_ENV=production`, which is what the compose file's
`environment:` says. Nothing in the install depends on the environment name
otherwise: the store domain the seeders record comes from `APP_URL`.

#### It is not idempotent

It needs `--force=1`, because it refuses to prompt in a non-interactive session:

> Refusing to install without confirmation. Pass --force=1 for unattended install.

and `--force=1` skips the confirmation rather than making it idempotent.
`front-install` and `shop-install` each call their own uninstall first; the
command's own docblock says "running this on a live site destroys front/shop
data". So `panelalpha/install.sh` runs it only when GP247's own
`gp247-installed.txt` marker is absent — `storage/app/private/` on Laravel 11+,
`storage/app/` before that, both checked.

That marker is in `~/project`, which the engine empties on every deploy, while
the database lives on the `dbdata` volume. So a missing marker is not enough:
when `gp247_admin_store` already has a row, the script treats the deploy as a
redeploy, re-runs only the file side of the install (the `gp247:core-public`,
`gp247:functions-except`, `lfm_public`, `gp247:front-public` and
`gp247:front-template` publishes, `storage:link`) and writes the marker back.
The GP247 service providers gate every route on that marker, so without it the
site would not serve the shop at all; without the database check a rebuild would
reinstall over the live shop. A database it cannot reach fails the step rather
than guessing.

### 3. The admin account is `admin` / `admin`

`GP247\Core\Database\Seeders\DataDefaultSeeder` creates the single
administrator with a hardcoded hash:

```php
public $adminUser     = 'admin';
public $adminPassword = '$2y$10$JcmAHe5eUZ2rS0jU1GWr/.xhwCnh2RU13qwjTPcqfmtZXjZxcryPO';
```

`password_verify('admin', …)` on that hash returns true, and
`GP247\Core\Commands\Install::welcome()` prints `User/password: admin/admin`
when it finishes, so the deploy log says it as well. On an account that has just
been given a public HTTPS name, that is a shop whose administration is open to
anyone who has read the manual.

Current gp247/core releases no longer leave it there: `gp247:install`
generates a random password and prints it, once, to the console, which on a
deploy is the deploy log. That is not open to anyone, and not usable either:
nobody deploying the shop reads it there. Either way the visitor had no way
in, and an earlier version of this recipe added a third: it generated a
password into `~/.panelalpha/scart/admin-password` and told nobody.

So the engine generates the login (`credentials:` in `panelalpha.yaml`:
`SCART_ADMIN_USER`, default `admin`, and `SCART_ADMIN_PASSWORD`), keeps it on
the project and writes it to `~/.panelalpha/app-credentials.env`, which the
compose override adds to the app's `env_file`.

- `files/panelalpha/install.sh` passes the password to
  `gp247:install --admin-password`, GP247's own way: `InitialAdminPassword::assign`
  sets it only while the account still has the seeded hash, and the install
  then prints "the one you passed with --admin-password" instead of a password.
- `files/panelalpha/set-admin-password.php` (install and upgrade stages, after
  the install) is for installs that predate that. It gives the seeded `admin`
  the engine's login (and renames it when `SCART_ADMIN_USER` is not `admin`)
  only while its password is still one nobody chose: `admin`; the one the
  older recipe recorded in `~/.panelalpha/scart/admin-password` (that record is
  then removed); or the random one `gp247:install` printed, which GP247 sets
  with a query that leaves `updated_at` empty, while every save through the
  admin panel, a password change included, fills it. Any other password was
  chosen in the panel and is left alone (*"the admin account has a password
  chosen in the panel; left alone"*), so a restart or a redeploy never resets
  it. The `UPDATE` repeats the old hash in its `WHERE` clause, so a password
  changed while it runs is not overwritten.

It boots the framework rather than speaking raw PDO, because all three things it
needs are things a hand-rolled script would have to guess: the connection
(`config/database.php`, a sidecar today and an operator's managed MySQL
tomorrow), the table prefix (`GP247_DB_PREFIX`, `gp247_` by default and settable
in `.env`), and the hasher the login form will check against.

## Build-stage commands do not belong in an app config

`panelalpha.yaml` declares no build-stage commands, and that is not an
oversight. An app config's `commands:` are applied by the app-config layer and
are deliberately left out of the manifest it describes —
`AppConfig::readManifest()` drops the key, because "copying them into the
manifest would run every command twice" — while `install_command` and
`build_command`, the only two things `HostCompile::runPhpBuild()` reads, are
projections of the *manifest's* staged commands. A build-stage command written
in a source recipe therefore never reaches the host build.

Declaring `composer-install`, `package-discover` and `asset-publish` here
exactly as `laravel.yaml` spells them would claim work that does not happen:
what runs is `PhpHostBuild::DEFAULT_INSTALL` — the same
`composer install --no-dev --no-interaction --no-scripts --no-plugins
--optimize-autoloader` — so the tree is resolved either way, and neither
`package:discover` nor `vendor:publish` runs.

Package discovery is done in the install stage instead, inside the container,
where an app config's commands do run. Laravel's `PackageManifest` would build
`bootstrap/cache/packages.php` lazily on the first artisan call and that works;
doing it once, first, means the three gp247 providers are registered before
anything depends on them rather than as a side effect of the first thing that
does.

## MariaDB, not SQLite

`config/database.php` reads `env('DB_DATABASE', database_path('database.sqlite'))`
with no wrapping, so `PhpEnvironment::sqlitePath()`'s doubling trap (as seen with
Heimdall) does not apply: an absolute path would be used as given. And SQLite
very nearly works: `gp247:install` migrates and seeds, `/` serves the
storefront, and the admin login form renders and signs in.

The page it logs you in to is the one that fails.
`GP247\Shop\Admin\Models\AdminOrder` builds the dashboard's four statistics out
of raw MySQL — `DATE_FORMAT()`, `DATE_SUB()`, `CURRENT_DATE()` — and
`/gp247_admin` answers:

```
SQLSTATE[HY000]: General error: 1 near "(": syntax error
(View: vendor/gp247/shop/src/Views/admin/component/order_month.blade.php)
```

HTTP 500 for every administrator, behind a storefront that looks perfectly
healthy. The same page on MariaDB is a 200. Deleting the offending widgets from
`gp247_admin_home` would paper over it, at the price of a shop whose reports are
a landmine and an upstream that will add more raw SQL. 512 MB for the database
is the cheaper half of that trade.

So `hooks/prepare.sh` writes `DB_HOST=127.0.0.1` into `.env`, and that single
value is what asks for the database: `EnvSidecars` reads `.env.example` and then
`.env`, and turns `DB_CONNECTION=mysql` into a `mariadb:11` companion **only
when `DB_HOST` names a local address**. Upstream's `mysql-local` is not one — it
is the name of a service in the compose file the hook just moved aside — so left
alone the deploy would have had a Laravel app configured for MySQL and no MySQL
anywhere. `DB_DATABASE`, `DB_USERNAME` and the generated `DB_PASSWORD` on the
lines beside it become the sidecar's `MYSQL_*`, and the app's real `DB_HOST` is
written into the generated `environment:` as the sidecar's service name. The
`wait-for-mysql` loop the PHP strategy puts ahead of the install stage covers
the first boot.

## `APP_KEY`, and why this file is not `extends: laravel`

The generated service loads `.env` through `env_file:`, and Compose reads that
when the container is **created**: an `APP_KEY=` line makes `APP_KEY` a real,
empty environment variable, and Laravel's Dotenv is immutable, so it never
overwrites one. A `key:generate` inside the container writes a good key into
`.env` while every request still answers `MissingAppKeyException`.

So the key is generated in the prepare hook, on the host, before the container
exists — and `panelalpha.yaml` states the whole manifest rather than
`extends: laravel`, because an app config's `commands:` are merged with the
manifest's and never matched by id: there is no way to *remove* the platform's
`key-generate`, only to add to it.

`GP247_ENCRYPTION_KEY` is generated the same way. Upstream ships it empty, and
`gp247:doctor` warns about that for a reason: with no value, encrypted columns
(SMTP passwords, OAuth secrets, licences) fall back to `APP_KEY`, and an
`APP_KEY` rotation then destroys them. Setting it on day one is free.

Both keys and the database password are generated once into
`~/.panelalpha/scart/secrets.env` (0600 in a 0700 dir) and written into `.env`
from there. They cannot live only in `.env`: the engine empties `~/project` on
every deploy. A guard on `.env` not existing would generate all three again on
a rebuild — the install stage would then die on
`SQLSTATE[HY000] [1045] Access denied for user 'scart'` against the MariaDB
volume created with the old password, and the new keys would make the sessions
and every encrypted column unreadable.

## What survives a rebuild

The engine empties `~/project` before every deploy, so everything the shop
writes at runtime is kept under `~/.panelalpha/scart/` (created by
`hooks/prepare.sh`, bind-mounted by `overrides/docker-compose.override.yml`):

| Path on the account | In the container | What |
|---|---|---|
| `~/.panelalpha/scart/uploads/` | `/app/storage/app/public` | uploads: the `public` and `gp247` disks (product, banner, logo, avatar ... images from the file manager), served as `/storage/...` through the `public/storage` symlink that `storage:link` recreates on every deploy |
| `~/.panelalpha/scart/admin-password` | `/panelalpha/admin-password` | an older version's admin password note, gone once its admin moves to the engine's login |
| `~/.panelalpha/scart/secrets.env` | `/panelalpha/secrets.env` | `APP_KEY`, `GP247_ENCRYPTION_KEY`, the MariaDB password |

The database is on the `dbdata` volume. Not kept: `storage/logs` (logs go to
stderr anyway), and extensions or templates installed from the admin
marketplace into `app/GP247` / `public/GP247`, which the install stage
re-publishes from the vendor packages on every deploy.

## Readiness

`AppLauncher` runs `docker compose up -d` without `--wait` and the deploy is
finished when that returns, which for S-Cart is minutes before it answers: the
database's first boot, `migrate`, then three package installs each with a large
schema and its seeders, then the template publish and the engine's login, all
in the install stage before Apache binds.

`overrides/docker-compose.override.yml` adds a healthcheck and a no-op `ready`
service (`alpine:3`, `entrypoint: exit 0`, `restart: "no"`) gated on
`condition: service_healthy`. Compose blocks on a `depends_on` health condition,
so `up -d` returns only once the shop answers, and a clean exit 0 is explicitly
not a crash loop to `AppHealth::isCrashing()`.

The check is two requests: `GET /`, the storefront rendered out of the seeded
store and shop tables, and `GET /gp247_admin/auth/login`, the admin login form,
which is a 200 to an anonymous client and renders from the seeded schema. `start_period` is 300s.

## Not configured

**Mail.** `.env.example` ships `MAIL_MAILER=log`, so password-reset and order
mail is written to the log rather than sent. Set `MAIL_*` through the account's
env vars to add a server. Nothing in this recipe depends on mail.

**The admin URL.** `GP247_ADMIN_PREFIX=gp247_admin` is upstream's default and
the recipe leaves it alone, because the healthcheck and the credentials'
`login_path` both name that path. Changing it in `.env` works; the healthcheck
then probes a path that 404s, so change the override too.

**`GP247_API_LICENSE`** is empty, which is upstream's default. The extension
marketplace (`gp247:ext-*`) needs one; nothing in a plain shop does.

**Queue and scheduler.** The repository's compose runs `queue:work` and
`schedule:run` in their own containers. The generated stack does not, and this
recipe does not add them: `QUEUE_CONNECTION=database` means queued work simply
waits, and nothing in a fresh install queues anything. A shop that starts using
queued mail or scheduled jobs needs them back.

## Files

| File | Why |
|---|---|
| `panelalpha.yaml` | the whole manifest (so `key-generate` is not inherited), the `env:` the container needs, the install and password commands, and the `credentials:` |
| `hooks/prepare.sh` | moves both workstation compose files out of the root before detection, creates `~/.panelalpha/scart/uploads`, and writes a `.env` with the keys and database password kept in `~/.panelalpha/scart/` |
| `files/panelalpha/install.sh` | runs `gp247:install --force=1 --admin-password` (the engine's password) exactly once, guarded on GP247's own marker and on a seeded database, as `APP_ENV=local` so its unforced migrations are not cancelled; on a redeploy re-publishes the install's files and restores the marker |
| `files/panelalpha/set-admin-password.php` | gives an older install's admin the engine's login while its password is still one nobody chose |
| `overrides/docker-compose.override.yml` | the engine's login as `env_file`; bind-mounts `~/.panelalpha/scart` (uploads, an older password note) into the app; a two-request healthcheck plus a `ready` gate, so the deploy waits for the database, the three installs and the template publish |

The engine's login, checked locally (2026-11-19, 338bbb9a, gp247/core 3.15,
PHP 8.3 with the recipe's install and start commands, MariaDB 11, this
override):

- a fresh install: the deploy log says "Password: the one you passed with
  --admin-password"; `POST /gp247_admin/auth/login` with the generated login
  302 to `/gp247_admin`, which is then 200 (the dashboard); `admin`/`admin`
  is refused (302 back to the form).
- an install from before (the random password `gp247:install` printed), one
  with the older recipe's recorded password (the record was removed) and one
  still on the seeded `admin`: each moved to the generated login, and the old
  password was refused after it.
- a password changed the way the admin panel saves it survived a restart that
  ran the install stage again, and the generated one was refused after it.
