# Heimdall (github.com/linuxserver/Heimdall)

Application dashboard — a page of tiles linking to the things you host, with
live status for the ones it knows. A Laravel 13 app on SQLite: no database
server, no queue, no cache, no build step that produces anything.

Detection: `laravel` — `composer.json` and `artisan`. That was already right,
and so was everything the strategy did with it. The repository commits
`vendor/` (15 210 files) and the compiled `public/css`, `public/js` and
`public/mix-manifest.json`, so the clone is a runnable Heimdall before Composer
runs at all; the host pass only drops 33 dev packages and re-discovers
providers. PHP 8.4 against `"php": "^8.4"`, `public/` as the document root, the
account's public https URL in `APP_URL`. The deploy reported success and the
container restart-looped on exit 1 — the `serving-unknown` verdict this recipe
turns into a served page.

## Why the source, and not `lscr.io/linuxserver/heimdall`

linuxserver.io publish an image, but it is not built here: this repository has
no `Dockerfile` at its root and none in its release workflow, and the `docker/`
directory is a two-service laptop harness (php-fpm with xdebug, an nginx, the
source bind-mounted) under `docker/compose.yaml`, a name Docker never
auto-loads. The image comes from a separate repository, `linuxserver/docker-heimdall`,
wrapped around their s6-overlay base — an init that wants to start as root,
take `PUID`/`PGID`, and own `/config`.

Ghost and Wiki.js needed the published image because their own checkouts
cannot produce a serving one. Heimdall's can, and does: the strategy already
builds nothing, because there is nothing to build. Swapping in an image built
from a different repository would replace a working deploy of *this* checkout
with a deploy of something else, and would trade the engine's uid and bind
mount for s6's. So the recipe keeps the source.

## The one thing that was wrong: `DB_DATABASE`

The engine already knows about this application.
`PhpEnvironment::sqlitePath()` carries the comment

> Heimdall wraps the variable in `database_path()`, so an absolute path
> doubles. Such a config gets a bare filename; anything else keeps the
> absolute path.

and a regex that looks for `database_path(env('DB_DATABASE'` right after the
`'database' =>` key. Heimdall has since put a ternary in front of it so its
tests can run against `:memory:`:

```php
'database' => env('DB_DATABASE', 'app.sqlite') === ':memory:'
    ? ':memory:'
    : database_path(env('DB_DATABASE', 'app.sqlite')),
```

The regex no longer matches, the absolute fallback wins, and the generated
compose file carries `DB_DATABASE: /app/database/database.sqlite` — which
`config/database.php` then wraps in `database_path()` regardless. The first
artisan call the entrypoint makes boots `AppServiceProvider::boot()`, which
calls `setupDatabase()`, which calls `touch()`:

```
touch(): Unable to create file /app/database/app/database/database.sqlite
because No such file or directory
at app/Providers/AppServiceProvider.php:167
```

`set -e` in the generated entrypoint made that exit 1, on `key:generate` and
then on every restart.

`panelalpha.yaml` restates `DB_DATABASE: app.sqlite` — the bare filename
Heimdall's own `.env.example` uses. `ComposeEnvironment::layer()` folds an app
config's `env:` over the strategy's, so one line in the recipe is the whole
fix. Repairing the regex upstream would retire it; the recipe would then be
setting the value the engine had worked out anyway.

Note that `.env` cannot be where this is corrected. The generated service loads
`.env` through `env_file:` **and** names `DB_DATABASE` under `environment:`, and
compose gives `environment:` precedence — so Heimdall's own
`DB_DATABASE=app.sqlite`, which is already sitting in the `.env` the engine
copied from `.env.example`, was being shadowed the whole time.

## Readiness

`AppLauncher` runs `docker compose up -d` without `--wait` and the deploy is
finished when that returns — which for Heimdall is well before it answers. The
install stage runs `key:generate`, `storage:link` and `migrate`, and inside the
*first* of those Heimdall does its own first-run work, because
`AppServiceProvider::boot()` runs for artisan as well as for a request:
`setupDatabase()` creates `database/app.sqlite`, runs `migrate --seed` over it
(20 migrations, the settings and users seeders) and then
`ProcessApps::dispatchSync()` fetches the 320 KB supported-apps list over the
network. Apache binds after all of it.

`overrides/docker-compose.override.yml` adds a healthcheck and a no-op `ready`
service (`alpine:3`, `entrypoint: exit 0`, `restart: "no"`) gated on
`condition: service_healthy`. Compose blocks on a `depends_on` health
condition, so `up -d` returns only once the site answers, and a clean exit 0 is
explicitly not a crash loop to `AppHealth::isCrashing()`.

The check is two requests. `GET /` is a 302 to `/login` once the admin account
has a password, and a redirect proves only that Apache is up; `GET /login`
renders `User::currentUser()` and `Setting::fetch()` out of the seeded schema,
so a 200 there is proof the migration *and* the seeders finished. `curl -f`
accepts the 302 and fails on the 500 a half-built database gives. No `-L`: the
`Location` is the account's absolute https URL, which is not this port.

An override, not a replacement compose file: `overrides/docker-compose.yml` is
written into the checkout *before* detection runs, so it would make Heimdall
look like a compose project and take the laravel strategy — the bind mount, the
docroot, the Composer pass, the staged entrypoint — off the table entirely.

## Security: the seeded admin has no password

`database/seeders/UsersSeeder.php` creates user 1 as `admin` with
`password = null`, and `App\Http\Middleware\CheckAllowed` reads that literally:

```php
// Continue with passwordless user
if (empty($current_user->password)) {
    return $next($request);
}
```

So a freshly seeded Heimdall serves **every route** to anyone who has the
address. Measured on a real deploy of this repository, before this recipe:
`/settings`, `/users` and `/items/create` all HTTP 200, no credentials, full
administration. That is the correct default for the LAN dashboard Heimdall was
written to be, and the wrong one for an account the engine has just given a
public HTTPS name and a certificate.

So `files/panelalpha/set-admin-password.php` generates a 20-character password,
writes it to `~/project/.panelalpha-admin-password` (mode 0600) and sets it on
user 1 — **only while the password is still null**. Re-running it on an account
whose operator has since chosen their own password in the web interface does
nothing: rotate what upstream shipped, never what somebody chose. The file is
written before the update, so there is never a password in the database that
nothing recorded, and the `UPDATE` repeats `AND password IS NULL` to close the
window between the two.

It speaks plain PDO rather than booting the framework. Booting Laravel here
would run `AppServiceProvider::boot()` a fourth time in one deploy and make a
fourth network request for the supported-apps list; the two columns it needs
(`users.id`, `users.password`) have been in
`2018_10_12_122907_create_users_table.php` since 2018, and a `$2y$` bcrypt hash
is exactly what `Illuminate\Hashing\BcryptHasher::check()` verifies with
`password_verify()`. If either stops being true, that file is where it breaks.

The dashboard is then behind a login. To open it to the public again — which is
how many people want a dashboard — log in and turn on *Public front* for the
user in Settings; `CheckAllowed` lets the `dash` and `tags.show` routes through
for a user with that flag while everything else still needs the password.

## Not fixed, and upstream's

`php artisan optimize` fails:

```
Unable to prepare route [tag/{slug}] for serialization.
Another route has already been assigned name [tags.show].
```

`routes/web.php` registers `tags.show` twice, once through
`Route::resource('tags', TagController::class)` and once by hand in the
`Route::name('tags.')->prefix('tag')` group, and `route:cache` refuses to
serialize that. The laravel manifest marks `optimize` optional, and
`OptimizeCommand` runs `config:cache` before `route:cache`, so the config cache
is written, the route cache is not, and the deploy continues. The cost is a
stack trace in the container log on every start.

## Not configured

**Mail.** `.env.example` points `MAIL_HOST` at `smtp.mailtrap.io` with no
credentials. Heimdall only needs mail for password resets, and the recipe puts
the one password there is in a file instead, so nothing here depends on it. Set
`MAIL_*` through the account's env vars to add a server.

**`ALLOW_INTERNAL_REQUESTS`** stays at its default `false`. Heimdall refuses
server-side fetches of user-supplied URLs that resolve to private or reserved
addresses, which is the right answer for an internet-facing account; a LAN
dashboard whose tiles point at `192.168.x` needs it set to `true` and should
understand it is turning off an SSRF guard.

## Files

| File | Why |
|---|---|
| `panelalpha.yaml` | `extends: laravel`, `DB_DATABASE: app.sqlite`, the admin-password command, and the account of what was wrong |
| `files/panelalpha/set-admin-password.php` | gives the seeded `admin` account a password while it still has none, and records it |
| `overrides/docker-compose.override.yml` | a two-request healthcheck plus a `ready` gate, so the deploy waits for the migration, the seeders and the app-list fetch |

## Verified

On a 2-core / 3.7 GB engine, account capped at 1200 MB: `deploy-ok`, deploy
60s with the shared PHP base image already built (the first deploy on a host
without it spends about 195s more building that image), port probe `HTTP 302`,
domain `302`, `serving: ok`, all twelve baseline checks passing, and HTTP 200
with the title `Heimdall` once the redirect is followed.

Beyond the status code:

- `/`, `/settings`, `/users` and `/items/create` all 302 to `/login` for an
  anonymous client; after `POST /login` with the password from
  `~/project/.panelalpha-admin-password`, `/settings` renders 18 KB of the real
  settings form. A wrong password leaves it at 302.
- `POST /items` as that session creates an item and the dashboard renders it —
  a real authenticated write through to SQLite.
- `database/app.sqlite` holds the 15 seeded settings rows and one `admin` user
  whose password is now a `$2y$12$` bcrypt hash;
  `storage/app/supportedapps.json` is 324 KB of app definitions fetched at
  first boot.
- Re-running `panelalpha/set-admin-password.php` prints *"the admin account
  already has a password; left alone"* and leaves the credentials file byte
  for byte unchanged.
- With `database/app.sqlite`, the credentials file and the config cache
  deleted, `docker compose up -d` printed `Waiting` then `Healthy` for the app
  before starting `ready` and returned in 7.6s — the whole first run (key,
  migrate, seed, the app-list fetch, a fresh password) inside that window,
  which is the window a probe without the gate lands in.
