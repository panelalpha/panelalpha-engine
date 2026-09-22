# Servas (github.com/beromir/Servas)

A self-hosted bookmark manager, MIT. Laravel 12 with Fortify and Jetstream,
Inertia and Svelte 5 on the front, Tailwind 4, SQLite or MySQL behind it. This
branch is `main`.

## What was wrong

The repository is an ordinary Laravel checkout — `composer.json`, `artisan`,
`public/index.php`, a root `package.json` whose `build` script is `vite build` —
and it also ships a `Dockerfile`. `platforms/dockerfile.yaml` has priority 970
and `apps/laravel` 940, so detection read the Dockerfile and stopped.

`DockerfileStrategy` is the lightest strategy in the engine on purpose: the
author wrote that Dockerfile and their choices stand, so it builds the image,
publishes the port and adds nothing. What the author wrote is a FrankenPHP image
whose `ENTRYPOINT` runs `php artisan migrate --force`, has no `set -e` and no
wait, and then starts the server whatever happened. Beside it,
`EnvSidecars::variableMap()` read the repository's own `.env.example`, found
`DB_CONNECTION=mysql` and `DB_HOST=127.0.0.1`, and added a `mariadb:11` service
— correctly, on its own terms. The generated compose wired the app to it with a
bare `depends_on: [db]`, with no `condition: service_healthy`, although the
sidecar it had just written carries a healthcheck.

So the container came up while MariaDB was still initialising:

```
Starting Migration...
SQLSTATE[HY000] [2002] Connection refused (Connection: mysql, Host: db,
Port: 3306, Database: laravel, SQL: select exists (... 'migrations' ...))
```

and carried straight on to `config:cache`, `view:cache` and FrankenPHP. The
deploy reported success in **105.6s**, the port answered, and every request then
hit `SESSION_DRIVER=database` against a database with no tables:

```
SQLSTATE[42S02]: Base table or view not found: 1146
Table 'laravel.sessions' doesn't exist
```

HTTP 500, `checks/_baseline/no-server-error` fails, verdict
`serving-error_page`.

Two things made it worse than a race. `.env.example` ships `APP_ENV=local` and
`APP_DEBUG=true`, and nothing in the dockerfile path overrides them — so that
500 was the full Laravel debug page, stack trace and database credentials, on a
public HTTPS address. And `.dockerignore` excludes `.env`, so the key the engine
generated reached the container only through `env_file:`.

## The fix

`extends: laravel`. A source recipe is found by its path rather than by
detection, so the Dockerfile stops being consulted and Servas is deployed as
what it is: `~/project` bind-mounted at `/app`, Composer on the host in the PHP
8.4 image `"php": "^8.4"` asks for, `HostCompile::runForPhp()` running the
repository's own `vite build` into `public/build`, Apache on `public/`, and the
staged entrypoint. Upstream's `Dockerfile`, `Caddyfile`, `docker-entrypoint.sh`
and `docker/` are left where they are; none of them is under the document root
and nothing reads them any more.

**No `id:`**, deliberately, the way `plainpad` and `heimdall` extend this same
manifest. A recipe that states its own id becomes a manifest only
`SourceRecipes::all()` can resolve, and `EntrypointWriter::write()` resolves the
decision's platform through `PlatformRegistry::find()` at the very end of the
deploy — which reaches that walk through a per-worker cache. Measured on a
worker whose cache predated this file: `Recipe named by
github.com/beromir/servas`, the prepare hook ran, `files/` were written, and
then **no `panelalpha-entrypoint.sh` was written at all** — `panelalpha: no
/app/panelalpha-entrypoint.sh on the mount; serving directly`, and
`key:generate`, `migrate` and `servas-install` were dropped in silence. That is
engine#169. Inheriting the `laravel` id keeps the lookup on a shipped manifest,
where it cannot miss.

## Where the data lives

`~/.panelalpha/servas`, bind-mounted at `/data` by
`overrides/docker-compose.override.yml`:

| Path | What |
|---|---|
| `/data/database.sqlite` | every bookmark, tag, group and user |
| `/data/app.key` | the application key |
| `/data/credentials` | the generated account, 0600 |

SQLite rather than `database: mysql`: it suits a single-user bookmark manager,
it keeps a database container off a 3 GB host, and — the deciding part — the
squashed schema (below) is loadable as SQLite and not as MySQL. But the engine's
SQLite default is `/app/database/database.sqlite`, **inside the checkout**, and
a redeploy clears and re-clones `~/project` (engine#173): every bookmark would
be destroyed by the next deploy. `~` is root-owned 0755 and nothing can be
created there, so the data directory is a child of `~/.panelalpha`, which is
created with the account and belongs to it.

engine#167 does **not** apply here. Heimdall wraps the variable in
`database_path()` and doubles an absolute path; Servas's `config/database.php:41`
is `env('DB_DATABASE', database_path('database.sqlite'))`, with the wrapper only
on the *default*, so an absolute path is used as written. Verified on a live
deploy: the file the site writes is the one on the mount.

## The schema will not load itself

Servas squashed its first thirteen migrations into `database/schema/`. The eight
files left in `database/migrations/` are increments (one of them,
`create_personal_access_tokens_table`, is already inside the dump) — **none of
them creates `users`, `links`, `tags` or `groups`**.

`php artisan migrate` loads a dump by shelling out.
`Illuminate\Database\Schema\SqliteSchemaState::load()` runs
`sqlite3 "<database>" < <dump>`, and the MySQL half runs the `mysql` client. The
shared PHP base image has `pdo_sqlite` and `sqlite3` as *extensions* and neither
binary — so either way the migration dies on `sqlite3: not found` and the deploy
ends with an empty database, which is the same 500 by a different road.

`hooks/prepare.sh` loads the dump through PDO on the account instead, before the
container exists. The dump carries its own `INSERT INTO migrations` rows, so
`MigrateCommand::prepareDatabase()` then sees `hasRunAnyMigrations()` true, skips
`loadSchemaState()` and applies the remaining increments normally. Measured on a
live deploy: the hook records the thirteen squashed migrations as batch 1, then
`migrate` applies all seven remaining increments as batch 2 over thirteen
tables; on a redeploy it reports *Nothing to migrate*.

The sqlite dump is 36 lines of plain SQL that `PDO::exec()` runs directly. The
mysql one is mariadb-dump output — `bigint(20)`, `/*!40101 ... */` conditionals
— which is not.

## Security

**Registration is closed, and an account is created instead.** Servas ships no
installer, no seeded user and no admin role. `config/fortify.php:135` reads
`env('SERVAS_ENABLE_REGISTRATION', true)`, and that form is the only way anybody
ever gets in — so a stock deploy on a public HTTPS address is a bookmark manager
whose first visitor becomes its owner, with nothing to remove them afterwards
and no way to tell it happened. So `hooks/prepare.sh` generates a 20-character
password into `~/.panelalpha/servas/credentials` (0600), `.env` sets
`SERVAS_ENABLE_REGISTRATION=false`, and `panelalpha/servas-install.php` creates
the account before Apache binds — guarded on the `users` table being empty, so a
redeploy over a surviving database never creates a second one and never resets a
password somebody chose. Measured on the live domain: `/register` is **404**.
An operator who wants other people to sign up sets the variable back to `true`
in `~/project/.env`; that is a decision for whoever owns the installation, and
it should not be the default on an address the engine has just published.

**No committed `APP_KEY`.** `.env.example` carries an empty `APP_KEY=`, so
engine#179's class of bug does not arise, and `ProjectEnvironment::apply()` would
generate one. But it generates a *new* one every deploy, because `.env` is
re-created after every re-clone while the database in `/data` lives on: a
redeploy would invalidate every session and make every Fortify
`two_factor_secret` already written undecryptable. `key:generate` is
install-stage and cannot fix the second deploy either. So the key is generated
once into `~/.panelalpha/servas/app.key` and written into `.env` by
`servas-install.php` on the install *and* upgrade stages — after `key:generate`,
and after the engine has taken its 644 world-readable `.env.default` copy of
whatever the hook left (engine#173), which is why the key is never in the file
the hook writes. Verified across a redeploy: `APP_KEY` byte-identical,
`.env.default` contains no `APP_KEY` line at all.

**No seeded admin.** `database/seeders/DatabaseSeeder.php` does create
`admin@admin.com` with a factory password and 100 fake links, but nothing runs
it — the laravel manifest migrates and never seeds, and this recipe does not
either.

**Nothing sensitive is web-readable.** Measured against the live domain:
`.env`, `.env.default`, `.env.example`, `.env.backup`, `.git/config`,
`docker-compose.yml`, `panelalpha-entrypoint.sh` and `.htaccess` all **403** at
the proxy; `composer.json`, `artisan`, `Dockerfile`, `Caddyfile`,
`docker-entrypoint.sh`, `docker/.env.prod.example`, `database/database.sqlite`,
`database/schema/sqlite-schema.dump`, `storage/logs/laravel.log`,
`panelalpha/servas-install.php`, `docker-compose.override.yml` and
`vendor/autoload.php` all **404** through Laravel, because the document root is
`public/` and none of them is in it. `/build/` and `/assets/` are 403 rather
than listings (`Options -Indexes` in the shipped `.htaccess`). The database is
not under the document root at all — it is on the bind mount.

## `key:generate`, and why it is left to fail

The `.env` the hook writes carries no `APP_KEY` line at all, so
`KeyGenerateCommand::writeNewEnvironmentFileWith()` finds nothing to replace,
prints `Unable to set application key. No APP_KEY variable was found in the .env
file.` and returns — **exit code 0**, the stage carries on, and there is no
moment at which `.env` holds a key other than this installation's. That one
error line in the deploy log is the whole cost, and it is cheaper than a
throwaway key that has to be overwritten a command later.

## The `.env` mode is load-bearing

`EnvSidecars::variableMap()`
(`core/app/Lib/Deploy/Sidecar/EnvSidecars.php`) reads `.env.example` and then
`.env` with plain `file_get_contents()` as www-data, rather than through the
account's file layer that the rest of the deploy uses — so a `.env` written 0600
is invisible to it and `.env.example` wins. With the hook's `.env` at 0600 this
recipe got a `mariadb:11` container of its own and `DB_CONNECTION: mysql`,
`DB_HOST: db` in the compose `environment:` block, where they beat `env_file`
for `php artisan` while the web request went on reading sqlite out of `.env`.
One database for the migration and another for the site.

The hook leaves it **0644** — the mode the engine writes its own `.env` with,
and there is nothing secret in it. `panelalpha/servas-install.php` chmods it
0600 when it adds the key.

## Readiness

Measured, and there is no healthcheck or `ready` gate. The install stage is
`key:generate` (a no-op), `storage:link`, seven migrations over a SQLite file
the hook already built, one account insert and `optimize`. `docker compose up
-d` returned after **1.0s** and the first HTTP 200 came **1.1s** after that.
Two clean deploys probed `serving: ok` on the first try. engine#90 is real for
applications that migrate and seed on boot; this is not one of them, and a gate
that waits on nothing is a second container per account for no reason.

## Verified

On a 2-core / 3.7 GB engine, account capped at 1200 MB: `deploy-ok`, deploy
**75.3s** with the shared PHP 8.4 base image already built (about 15s of that is
the Vite build), port probe **HTTP 200**, `serving: ok`, all twelve baseline and
`php` checks passing.

Against the public HTTPS domain, with the credential from
`~/.panelalpha/servas/credentials`:

- `GET /login` 200, `GET /register` **404**.
- `POST /login` with the generated password answers 200 `{"two_factor":false}`;
  `GET /` then redirects to `/links` and renders 200.
- `POST /tags` creates the tag `panelalpha`; `GET /all-tags` returns it.
- `POST /links` with `{"link": "https://panelalpha.com/engine-docs", "title":
  "PanelAlpha engine docs", "tags": [1], "groups": []}` answers **302** to
  `/links/2`, and `GET /links/2` renders the `SingleLink/Index` Inertia page
  with the title, the URL and `tags: [{id: 1, name: "panelalpha"}]` intact.
  That is the product.
- The Vite bundle serves from `public/build`: `manifest.json` 200 and its
  entries 200.
- **Redeploy** (`POST /projects/{u}/rebuild`) runs the upgrade stage: `migrate`
  reports *Nothing to migrate*, `servas-install` reports *the installation
  already has an account; nothing to do*, `APP_KEY` in `.env` is byte-identical
  to `~/.panelalpha/servas/app.key`, `database.sqlite` is byte-identical to
  before the rebuild, the same password still logs in, and `GET /links/2` still
  returns the bookmark with its tag.

## Not done

- **The authenticated screens were not driven in a real browser.** Every request
  above went to the public HTTPS domain and the Inertia page props were read out
  of the server's own `data-page` payload, but nobody typed the password into
  the form and watched the Svelte app render the bookmark list.
- **No `overrides/app.sh`.** Servas has no admin role and no user API, so
  `users:list`, `users:add` and an SSO handshake would all have to write the
  `users` and `sessions` tables directly. Nothing here needs it and it was left
  out rather than written untested.
- **Mail is not configured.** Servas needs it only for password recovery, and
  Fortify's `resetPasswords()` is commented out upstream anyway; the one
  password there is sits in a file instead.
- **`php artisan optimize` fails its `route:cache` step** and always will:
  `routes/web.php` registers two closure routes (`/` and `/groups`) and a
  closure cannot be serialised. The platform marks the command optional, so the
  config and view caches are written, the route cache is not, and the cost is a
  stack trace in the container log on every start. Upstream's to fix.
- **`MEMCACHED_HOST` and `REDIS_HOST` are still `127.0.0.1` in
  `.env.example`.** Nothing reads them — `CACHE_DRIVER=file` and
  `QUEUE_CONNECTION=sync` in the hook's `.env` — and no sidecar was mined for
  them, but they are one `CACHE_DRIVER=redis` away from pointing at nothing.
