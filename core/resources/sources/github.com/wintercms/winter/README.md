# Winter CMS (github.com/wintercms/winter)

Content management system — pages, themes and plugins, with a backend at
`/backend`. The maintained fork of October CMS, built on Laravel 9. SQLite is
enough for it: the whole install migrates and seeds in well under a second.

Detection reads it as `laravel` — `composer.json` and `artisan` — and the
runtime half of that is right. The rest is not, and the deploy before this
recipe ended `serving-unknown` with the container restart-looping on exit 1.

## Why this is a manifest and not `extends: laravel`

Winter registers its own migration command under Laravel's name.
`System\Console\WinterUp` (`modules/system/console/WinterUp.php`) is
`winter:up`, and its constructor adds two aliases for October compatibility:

```php
$this->setAliases(['october:up', 'migrate']);
```

An eagerly registered Symfony command beats the lazily loaded one Laravel puts
in its command map, so `artisan migrate` *is* `winter:up` — whose signature is
`winter:up {--seed}` and nothing else. The laravel recipe's install stage runs

```
php artisan migrate --force --no-interaction
```

and gets

```
The "--force" option does not exist.
```

`set -e` in the generated entrypoint turns that into exit 1, on `install` and
on every restart after it. Nothing ever bound port 8000, which is why the port
probe read `Failed to connect to 127.0.0.1 port 8000` and the deploy still
reported success.

An app config cannot take that command away. `StageResolver::commandsFor()`
merges `$manifest->stage($stage)` with `$appConfig->commands($stage)` and
`PlatformCommand::ordered()` sorts them by `before`; nothing matches them by
id, so a recipe's `migrate` would run *in addition to* the platform's, not
instead of it. And `AppConfig::readManifest()` strips `commands` out of the
manifest-shaped keys precisely because the class applies them itself — so
`extends: laravel` plus a `commands:` block cannot override one either.

So `panelalpha.yaml` states the manifest outright: `id: wintercms`,
`strategy: laravel`, and four commands of its own. Keeping
`strategy: laravel` is the point of doing it this way rather than writing a
whole new platform — `DeployStrategy::apply()` branches on the strategy, not on
the manifest id, so `$artisan` stays true and Winter keeps the shared PHP base
image, the `~/project` bind mount at `/app`, the account's uid, Composer on the
host, `pdo_sqlite` in the image and the public https URL in `APP_URL`.

## The document root is the repository root

`index.php` and a complete `.htaccess` are both committed at the top of the
checkout. `public/` is in `.gitignore` because it is *generated*, by
`php artisan winter:mirror public --relative`, which only `post-create-project-cmd`
runs and a deploy never does. `docroot: .` says so.

The base image would have coped — `panelalpha-serve.sh` falls back to `/app`
when the declared root is missing — but only after logging that the document
root does not exist, and only by accident.

Serving from the root is safe here because the committed `.htaccess` is a real
front controller, and the engine's vhost gives it `AllowOverride All` with
`mod_rewrite` enabled. Its catch-all rule sends every existing file that is not
an asset under `themes/`, `plugins/`, `modules/` or `storage/app/{uploads/public,media,resized}`
into `index.php`. Measured on the deployed account:

| Request | Result |
|---|---|
| `/` | 200, the Winter.Demo theme |
| `/backend` | 302 → `/backend/backend/auth/signin` |
| `/storage/database.sqlite` | 404 (rewritten into `index.php`) |
| `/composer.json` | 404 |
| `/.env` | 403, from the vhost's own `FilesMatch "^\."` |
| `/panelalpha/set-admin-password.sh` | 404 |

`winter:mirror public` was considered and rejected: it builds `public/` out of
symlinks to directories outside it, and the vhost's `<Directory />` is
`Require all denied`, so Apache would resolve those links to paths no
`<Directory>` block grants and answer 403.

## SQLite, and why the .env is written before anything reads it

`.env.example` ships `DB_CONNECTION=mysql`, and
`EnvSidecars::specFromDbConnection()` reads exactly that: the first deploy of
this repository got a `mariadb:11` sidecar and a `project_dbdata` volume for an
application that does not need either. On a 3.7 GB host with the account capped
at 1800 MB that is worth not doing.

`hooks/prepare.sh` writes `.env` with `DB_CONNECTION=sqlite`. Two things make
that the whole fix:

- `EnvSidecars::variableMap()` reads `.env.example` and then `.env`, live
  values last — so the sidecar is never inferred.
- `ProjectEnvironment` copies `.env.example` to `.env` only when there is no
  `.env` (`if ($baseContents !== null && !$fs->fileExists($envPath))`) — so
  writing one in the prepare hook is not racing it.

With no database provisioned, `DatabaseSettings::connection()` is `''`,
`isMysql()` is false, and `PhpEnvironment` reaches its SQLite branch on its
own.

**Heimdall's `database_path()` trap does not apply.** Winter's
`config/database.php` is

```php
'database' => env('DB_DATABASE', storage_path('database.sqlite')),
```

with no wrapping, so an absolute path is used exactly as given and
`PhpEnvironment::sqlitePath()`'s fallback would have been correct. What is not
correct is the directory it names: `/app/database/` does not exist in a Winter
checkout. `panelalpha.yaml` sets `DB_DATABASE: /app/storage/database.sqlite`
instead — `storage/` is in the checkout and is Winter's own default location
for the file. It has to be set in `env:` rather than in `.env`, because the
generated service names `DB_DATABASE` under `environment:`, which outranks
`env_file:`.

**The file has to exist.** Laravel 9's `SQLiteConnector` will not create it:

```
Database file at path [/app/storage/database.sqlite] does not exist.
Ensure this is an absolute path to the database.
```

so the prepare hook creates an empty one, which is a valid SQLite database.
`winter:up` builds the schema in it.

## APP_KEY has to be generated on the host

This one was found by deploying, not by reading. The recipe originally kept the
laravel platform's `key:generate` install command. It ran, it reported
`Application key set successfully`, `.env` held a real key — and every request
answered 500:

```
Illuminate\Encryption\MissingAppKeyException:
No application encryption key has been specified.
```

```
$ docker exec project-app-1 sh -c 'printenv APP_KEY; grep ^APP_KEY /app/.env'

APP_KEY=base64:vv70WEgylQ4eZooT6aic412K0kPiOnq7bBuTUE1bQMI=
```

The generated service loads `.env` through `env_file:`, and Compose reads that
file when the container is **created** — while `APP_KEY=` is still the empty
line `.env.example` ships. That makes `APP_KEY` a real environment variable
with an empty value, and Laravel builds its Dotenv repository immutably, so the
key `key:generate` writes into the file afterwards is read, found to be
shadowed, and discarded. Every later boot has the same empty variable baked
into the container.

So `hooks/prepare.sh` generates `base64:$(openssl rand -base64 32)` into `.env`
before the container exists, and `panelalpha.yaml` has no `key:generate` at
all. Guarded on the absence of `.env`, because regenerating APP_KEY on a
redeploy would invalidate every session and encrypted value the account has
written.

This is the same shape as Bolt's `APP_SECRET` and LinkAce's `APP_KEY`: anything
a repository ships as an empty `.env` placeholder cannot be filled in from
inside the container.

## Readiness

`AppLauncher` runs `docker compose up -d` without `--wait` and the deploy is
finished when that returns — which for Winter is before it answers. The install
stage runs `winter:up` (roughly forty migrations across the system, backend and
cms modules plus the committed Winter.Demo plugin, then each module's
`DatabaseSeeder`), then the admin-password reset. Apache binds after all of it.

`overrides/docker-compose.override.yml` adds a healthcheck and a no-op `ready`
service (`alpine:3`, `entrypoint: exit 0`, `restart: "no"`) gated on
`condition: service_healthy`. Compose blocks on a `depends_on` health
condition, so `up -d` returns only once the site answers, and a clean exit 0 is
explicitly not a crash loop to `AppHealth::isCrashing()`.

Two requests, and the second is the one that proves anything. `GET /` renders
the Demo theme, which already needs a migrated `cms_themes`; `GET
/backend/backend/auth/signin` renders the login form out of `backend_users` and
the seeded roles, so a 200 there is proof the *seeders* finished and not only
the migrations. `/backend` alone is a 302 to that address and would prove much
less.

The probe curls `127.0.0.1`, so the `Host` header is `127.0.0.1:8000`. Winter
registers no trusted-host middleware and answers it normally.

## The admin account

`Backend\Database\Seeds\DatabaseSeeder` creates user 1 as `admin` /
`admin@example.com` with `Str::random(22)`, prints it to the console and stores
it nowhere:

```
The following password has been automatically generated for the "admin"
account: n3Jm6Rtq47GnxtSIbUX8RB
```

That is a better default than several of the apps tested alongside this one —
a fresh Winter is *not* open to whoever finds the address; `/backend` redirects
an anonymous client to the login form. But the only copy of that password is in
the deploy log, which is not where an account's credentials live.

`files/panelalpha/set-admin-password.sh` generates 20 base64url characters from
`random_bytes`, writes them to `~/project/.panelalpha-admin-password` (0600)
*before* setting them, and then sets them with Winter's own
`php artisan winter:passwd admin <password>` — the app's own CLI, which hashes
the password exactly the way the login form verifies it. If `winter:passwd`
fails the credentials file is removed again, so nothing is ever left claiming a
password that was not set.

It does nothing once that file exists: rotate what upstream seeded, never what
somebody chose afterwards in *Settings → Administrators*. `winter:passwd` and
not `user:create`, because `Backend\Console\UserCreate` refuses to run outside
`app.env = local` without `--force` and would leave the seeded account in place
beside the new one.

## Known: the three modules are installed twice

`composer.json` requires `winter/wn-system-module`, `winter/wn-backend-module`
and `winter/wn-cms-module` as `dev-develop`, and expects `composer/installers`
to place them at `modules/<name>/`. The repository *also* commits those three
directories — 3 000 files of them — so a clone is already complete.

There is no `composer.lock`, so the engine installs with `--no-plugins`
(engine#168: a Composer plugin is arbitrary PHP out of a customer repository
and the install runs on the host daemon). `composer/installers` is a plugin, so
it does not run, and the three packages land in `vendor/winter/wn-*-module`
instead — 47 MB of second copy, and `autoload_psr4.php` maps `System\`,
`Backend\` and `Cms\` there.

The effect is smaller than it looks but it is real. `config/app.php` names
`System\ServiceProvider`, which Composer resolves to the vendor copy; that
provider then calls
`ClassLoader::autoloadPackage('System\\', 'modules/system/')` and Winter's own
loader answers everything after it. Counted on a booted console kernel: three
classes come from `vendor/winter/wn-*-module` — `System\ServiceProvider`,
`Backend\ServiceProvider`, `Cms\ServiceProvider` — and every other module class
comes from `modules/`. Views, translations, migrations and web assets are all
read from `modules/` by path.

It works, and it is verified working below. It is still two trees resolved at
two different moments (the clone is the monorepo's `develop`; the packages are
whatever the split repositories' `dev-develop` tips are when Composer runs), so
a provider could in principle register something the other tree does not have.

Fixing it here was tried and rejected: dropping the three requires from
`composer.json` and adding a `psr-4` map to `modules/` does produce a single
clean tree — verified — but it means rewriting an upstream `composer.json` with
`awk` on every deploy, and Winter's lowercase directory names (`modules/backend/behaviors/`)
are not PSR-4, so `--optimize-autoloader` skips them with a screen of warnings
and correctness then rests entirely on Winter's own class loader. The engine
honouring `config.allow-plugins` for `composer/installers` specifically, or
`composer install --no-plugins` plus a `--no-plugins`-safe installer pass,
would retire this section.

## Not configured

**Mail.** `.env.example` sets `MAIL_MAILER=log`, which is the right default
here: password resets are written to `storage/logs/system.log` rather than
silently failing against a mail server that is not there. Set `MAIL_*` through
the account's env vars to add one.

**`php artisan optimize`** is deliberately not run. Winter's routes are partly
generated from CMS pages held in the database, and `cms.enableRoutesCache`
defaults to `false` for that reason; caching them at boot would freeze a
routing table the CMS expects to rebuild.

## Files

| File | Why |
|---|---|
| `panelalpha.yaml` | the manifest: `strategy: laravel`, `docroot: .`, SQLite, and `winter:up` in place of `migrate --force` |
| `hooks/prepare.sh` | writes `.env` (SQLite, before the sidecar inference reads it) and creates the empty database file Laravel refuses to create |
| `files/panelalpha/set-admin-password.sh` | replaces the seeded password that only the deploy log has, and records the new one |
| `overrides/docker-compose.override.yml` | a two-request healthcheck plus a `ready` gate, so the deploy waits for the migration and the seeders |

## Verified

On a 2-core / 3.7 GB engine, account capped at 1800 MB: `deploy-ok`, deploy
75.3s with the shared PHP base image already on the host, port probe HTTP 200
in 34 ms, domain 200, `serving: ok`, every baseline check passing, and the
public domain answering 200 with the title *Winter CMS - Demonstration*. One
service in the generated compose — `app` — plus the `ready` gate, which exited
0; no database sidecar and no MySQL database provisioned for the account. The
app container sat at 81 MiB and `storage/database.sqlite` at 324 KB.

Beyond the status code:

- `POST /backend/backend/auth/signin` with the password from
  `~/project/.panelalpha-admin-password` → 302 to `/backend/backend`, and
  `/backend` then renders the dashboard (200). `/backend/cms`,
  `/backend/cms/themes`, `/backend/backend/users` and
  `/backend/system/settings` are all 200 for that session and 302 to the login
  form for an anonymous client. A wrong password leaves the form at 200 with no
  session, and `/backend` stays 302.
- `/storage/database.sqlite`, `/composer.json`, `/config/database.php`,
  `/vendor/autoload.php` and `/panelalpha/set-admin-password.sh` all 404
  through the committed `.htaccess`; `/.env` is 403 from the vhost.
- The container log shows the whole install stage: migration table, System,
  Backend and Cms modules migrated, System and Backend seeded, the Winter.Demo
  plugin migrated, `Migration complete`, then
  `[panelalpha] admin password written to ~/project/.panelalpha-admin-password`.

One note for whoever tests the next recipe on a *running* engine. The first run
of this recipe after `rsync` deployed with no `panelalpha-entrypoint.sh` at all
— the container logged `panelalpha: no /app/panelalpha-entrypoint.sh on the
mount; serving directly`, Apache came up against an empty database and every
page was a `no such table: system_settings` 500. Detection had found the recipe
(`Recipe named by github.com/wintercms/winter`) and the decision recorded
`deploy_platform: wintercms`, but `EntrypointWriter::write()` resolves the
manifest again through `PlatformRegistry::find()` → `SourceRecipes::findById()`
→ `SourceRecipes::all()`, whose static `$allCache` had been filled by a
queue worker that started hours before the directory existed. `find()` returned
null, `write()` returned false, and every stage command was dropped silently.
`SourceRecipes::for()` — the slug lookup detection uses — has its own cache and
was fresh, which is why the two disagreed. `php artisan queue:restart` in the
core container fixed it with no change to the recipe.
