# XBackBone (github.com/SergiX44/XBackBone)

XBackBone is a self-hosted file and screenshot host — the thing a ShareX or
Flameshot key binds to. Uploads go in over an API token, come back out as a
short URL.

**`master` is not the Slim application the tags are.** The newest tag, 3.8.2,
is the old codebase; `master` is a rewrite onto **Laravel 13, Fortify,
Livewire 4 and PHP 8.4**, with no tag yet. An account cloning this URL gets the
rewrite, so that is what this recipe deploys.

## Why detection was right and the deploy still failed

The repository is a **monorepo of two halves**, and neither is deployable on
its own:

```
app/    the skeleton that is deployed — public/index.php, bootstrap/, storage/,
        the `xbb` console, and a composer.json whose whole content is
        `require: xbackbone/core`
core/   the Laravel package that holds the application — XBB\, the routes, the
        views, the migrations, the guided installer, and the compiled
        public/build bundle
```

The repository root has **no index.php and no composer.json at all**. That is
exactly the `serving-missing_entry` verdict, and detection read the tree
correctly. `app/bootstrap/app.php` is what ties the halves together: it boots
`vendor/xbackbone/core/bootstrap/app.php` and then overrides only the public,
environment, storage and bootstrap paths — so `base_path()` stays inside the
package (that is where `config/`, `database/` and the published `public/build`
live) while `.env`, `storage/` and `public/` are the skeleton's.

## What the recipe does

`hooks/prepare.sh` promotes `app/` to the project root and wires `core/` in as
a Composer **path repository**. Upstream's own install is
`composer create-project xbackbone/app`, which resolves
`xbackbone/core: dev-master` from Packagist — the split of this same directory.
Doing that here would be worse than it looks: `app/composer.json` has no lock
file (`.gitignore` excludes it) and asks for a dev branch, so the account would
run whatever the split repository's master happened to be at deploy time rather
than the commit it cloned, and a different one again on the next redeploy.

Two lines are patched into that composer.json and nothing else is touched:

| patch | why |
| --- | --- |
| `require.php: ^8.4` | `core/composer.json` declares it; `app/composer.json` declares no PHP at all, and `PhpRuntime` reads only the root manifest. Without it the account gets the engine default of 8.3 and Composer refuses the resolve before anything is installed. |
| the path repository for `core`, `symlink: false`, `versions: {xbackbone/core: dev-master}` | so resolution does not depend on the shape of the engine's clone, and so the mirrored copy is a real directory inside the mount. |

The hook runs **before detection** (`AppConfigBootstrap::runScripts()` is called
from `PrepareFromSource::prepare()` ahead of `DetectProjectStrategy::detect()`),
so the patched manifest is what picks the image. That is the whole reason this
can be done in a hook at all.

Nothing else about the build needed saying. `composer install --no-scripts
--no-plugins` resolves 129 packages in ~15 s, and the php manifest's optional
`post-autoload-dump` step then runs the skeleton's own scripts — `xbb
package:discover` and the two `vendor:publish` lines that copy the core's
compiled `public/build` and its images into `public/`. There is **no Node
build**: the monorepo commits the production bundle, and after the restructure
there is no `package.json` at the project root for `HostCompile::runForPhp()`
to find. **engine#168** does not bite either: the only Composer plugin in the
graph is `php-http/discovery`, which nothing needs at runtime.

## Where the data lives — the thing the engine cannot infer

For an upload host this is the whole question, and both of XBackBone's defaults
are inside the checkout:

- the local disk root is `storage_path('app')`, **hard-coded** in
  `config/filesystems.php` with no environment variable in front of it — and
  that config directory belongs to the core *package*, so there is nothing in
  the skeleton to override;
- the SQLite database defaults to `APP_ROOT/xbb.db`.

A redeploy re-clones `~/project` after clearing it (**engine#173**), so an
account would lose every uploaded file *and* its entire database on the next
deploy. So:

```
~/.panelalpha/xbackbone/      bind-mounted at /data by the compose override
  ├── xbb.db                  DB_DATABASE
  ├── uploads/                storage/app is a symlink to it
  ├── app.key                 the account's APP_KEY, 0600
  └── admin-credentials       the generated administrator, 0600
```

The symlink is the only lever the hard-coded root leaves. It dangles on the
account (nothing is at `/data` outside the container) and resolves inside it.
`~` itself is root-owned 0755 and nothing can be created in it; `~/.panelalpha`
is created with the account and belongs to it, which is why the data directory
is a child of that one — and the prepare hook creates it before the mount is
made, so Docker never gets to create it as root.

`DB_DATABASE` is used verbatim by `config/database.php`; there is no
`database_path()` wrapper, so **engine#167** does not apply.

## The .env is written, not derived

mod_php does not publish the container environment as `$_SERVER`
(`variables_order = "GPCS"`, and the image's `auto_prepend_file` shim only
corrects the proxy's scheme), and Laravel's `env()` reads `$_SERVER` and
`$_ENV`. A value that lives only in the compose file reaches `php xbb` on the
install stage and **never reaches a web request**. So the hook writes a real
`.env`.

`APP_URL` is a placeholder there. The account's public address is in the
container environment at install time, and `panelalpha/xbb-install.php` hands
it to XBackBone's own installer, which writes it into `.env`.

## Security

### The installer, and who becomes the administrator

XBackBone ships a guided web installer.
`XBB\Installer\Http\Middleware\EnsureInstalled` redirects every request to
`/install` until setup is complete, and whoever loads that page picks the
database, the storage backend and the first administrator. On an account that
has just been given a public HTTPS name, that window is open to anyone who
knows the address.

`files/panelalpha/xbb-install.php` closes it on the **install** stage, before
Apache binds, by calling `XBB\Installer\Actions\FinalizeInstallation` — the
same action the wizard's last step calls, with the same payload. Nothing is
reimplemented and nothing is bypassed. Afterwards `/install` answers a redirect
to `/login`, enforced by the marker the action writes
(`storage/installed`); upstream also marks the guard as a Livewire persistent
middleware, so a snapshot captured from `/install` before setup finished cannot
be replayed against the shared Livewire endpoint afterwards.

`stage: build` would not run at all (**engine#171**), so the command is on
`install` and `upgrade`. It is idempotent: it returns early once the
application reports itself installed, and `FinalizeInstallation` reuses an
existing administrator with the same address rather than creating a second one.

The password is generated **once per account** into
`~/.panelalpha/xbackbone/admin-credentials` (0600) — once, not per deploy,
because the database survives the redeploy and a regenerated password would
stop matching the account in it.

### The committed application key

`app/.env.example` ships a **committed** `APP_KEY`:

```
APP_KEY=base64:88Nwiwz8SgR2v7Spx27RDdj7uCYidIwKCmzQCs4l0V4=
```

That is the same key in every clone of this repository there is, and the engine
copies a project's `.env.example` into `.env` when the project has no `.env` of
its own — so an unguarded deployment would sign its cookies and encrypt its
columns with a key printed in a public repository. The hook writes a fresh
`.env` from scratch instead and generates a key into
`~/.panelalpha/xbackbone/app.key`.

The key is put into `.env` by the **install script**, not by the hook, because
the engine copies whatever `.env` the hook leaves into a world-readable
`.env.default` (**engine#173**) and an application key has no business in that
file. Measured on the deployed account: `.env` is 0600 and carries the key,
`.env.default` is 0644 and carries none.

### Self-registration

**Off by default, and nothing here had to close it.** `XBB\Features\SignUp`
resolves to `false` and `XBB\Actions\Fortify\CreateNewUser` aborts 404 unless
it is active. Measured on the deployed account, `/register` is **404**. An
administrator can open it under Settings.

### What is not reachable

Measured against the deployed account over its public HTTPS domain:

```
302  /                      -> /dashboard -> /login
200  /login
404  /register              (sign-up closed)
302  /install               -> /login   (installer closed)
200  /up
403  /.env      403  /.env.default      403  /.git/config   403  /docker-compose.yml
404  /composer.json   404  /composer.lock   404  /xbb   404  /bootstrap/app.php
404  /vendor/autoload.php   404  /core/composer.json   404  /core/config/database.php
404  /core/.env             404  /panelalpha/xbb-install.php
404  /storage/installed     404  /storage/logs/laravel.log
404  /xbb.db   404  /app.key   404  /admin-credentials   404  /data/xbb.db
401  POST /api/v1/upload    (no token, and with a bad token)
```

The document root is `public/`, so the database, the uploads, `config.php`'s
equivalent (`core/config/`), the `.git` directory, the compose files and the
credentials are not under it at all. `storage/` is not under it either, and
nothing runs `storage:link` — XBackBone serves every uploaded file through
`ResourceController`, behind `EnsureResourceAccessible`.

## The queue, and what does not work

Previews are generated by a queued job and a hosting account runs no worker, so
the installer is told to use the **synchronous** queue: an upload generates its
own thumbnail inline. That is slower per upload and the only arrangement that
produces one at all here. An operator who adds a worker can switch
`QUEUE_CONNECTION` in `.env`.

The base image has **no ffmpeg**, so video thumbnails do not render. Images,
PDFs and SVGs do, through gd and imagick — both are in the image, along with
`zip`, `exif` and `pdo_sqlite`, which is the whole extension set
`core/composer.json` asks for.

## No readiness gate

`AppLauncher` runs `docker compose up -d` without `--wait` (**engine#90**), and
the usual fix is a healthcheck plus a no-op `ready` service. Measured here, the
entire install stage — `migrate` over a fresh SQLite file, the administrator,
and `optimize` — takes **0.91 s**, and Apache binds **1.7 s** after the
container starts:

```
12:31:57.686  container started
12:31:58.360  [panelalpha] install: xbackbone-install
12:31:59.266  [panelalpha] XBackBone installed; administrator admin@…
12:31:59.398  Apache … resuming normal operations
```

There is nothing real to wait for, and a `ready` container on a 3 GB host is a
cost with nothing to buy. The health probe answered on the first attempt on
both deploys.

## What was verified

On `mariusz2`, `--memory-limit=1200`, commit `524b9bc`:

- deploy **60.2 s** (45 s of it the `running` stage, ~15 s Composer), verdict
  **`deploy-ok`**, `healthy: true`, `serving: ok`, all twelve baseline and
  `php/*` health checks pass, `https://<domain>/` → **200** after the redirect
  chain.
- **Login over the public HTTPS domain** with the generated credential:
  `POST /login` → 302 to `/dashboard`, which renders (`Gallery | XBackBone`,
  95 KB).
- **Upload round trip.** An API token taken from `/integrations/sharex` over
  the authenticated HTTPS session; `POST /api/v1/upload` → **201**, and the
  file came back over the public HTTPS domain at `/raw/<code>.txt` and
  `/download/<code>.txt` byte-for-byte, with the preview page rendering. A PNG
  went the same way and `/thumbnail/<code>` answered `image/png`. Both landed
  in `~/.panelalpha/xbackbone/uploads/` under their sha1.
- **Install idempotency.** With `storage/installed` removed, the config cache
  dropped and `APP_INSTALLED=false` — the state a redeploy leaves — the install
  command ran again: still one user, still two resources, the same password
  still logs in, the uploaded files still fetchable.

The multipart upload had to be sent to the account's own address: through the
`*.panelalpha.online` test edge it stalled for 60 s and returned a `302` from
openresty, which is **engine#170** and not XBackBone.
