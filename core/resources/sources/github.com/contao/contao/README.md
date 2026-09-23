# Contao (github.com/contao/contao)

**This repository is the Contao development monorepo, not a Contao website.**
`composer.json` says `"type": "symfony-bundle"`; the tree is thirteen bundle
directories — `core-bundle/`, `manager-bundle/`, `news-bundle/`, `api-bundle/`
and the rest — plus `webpack.config.js`, `phpstan.neon`, `rector.php` and a
`monorepo.yml` that splits them into their own packages. There is no
`public/`, no `config/`, no `bin/console`, no `.env`, nothing that answers
HTTP. The README says it in as many words:

> The purpose of this package is to develop the Contao bundles in a monorepo.
> Use it when you want to create a pull request or report an issue.
>
> **Please do not use `contao/contao` in production**! Use the split packages
> instead.

Detection read it correctly: `composer.json` and no `artisan`, so the `php`
strategy, PHP 8.4 against `"php": "^8.4"`, the shared Apache base image with
`~/project` bind-mounted — and no `index.php` anywhere. The tracker's
`serving-missing_entry` was a true statement about the tree.

## Why there is a recipe here anyway

`github.com/contao/contao` is the Contao project's home on GitHub: the issue
tracker in every bundle's `composer.json` support block points at it, the
packagist badge is on it, and a customer who pastes this URL wants Contao the
CMS. What Contao ships as a *website* is the **Managed Edition**, and that is
not a codebase either —
[`contao/managed-edition`](https://github.com/contao/managed-edition) contains
four files, of which the only meaningful one is a `composer.json` requiring the
released split packages. There is no application to clone; the application is a
manifest.

So `hooks/prepare.sh` writes that manifest. The monorepo is moved to
`.contao-monorepo/` rather than deleted — it is what the customer asked to be
cloned, and it sits outside the document root — and it still decides two things
about the deploy: the PHP constraint (`require.php`, which the engine reads to
pick the base image) and the release series, taken from
`extra.branch-alias`: a clone of `main` develops `6.1-dev`, so the recipe
installs the newest released `6.0.*`. Clone the `5.3` branch and you get 5.3.

This is the same reading the Seafile and Ghost recipes take, one step less
severe. There the checkout is a different program, or a build that gets
skipped. Here it is the application's own source — one release ahead of what
actually gets installed, which is the thing upstream tells you not to run.

**If you wanted the monorepo built and tested, this is not that.** Use
`composer create-project contao/managed-edition` and point it at
`contao/contao: dev-main`, which is what the README's *Development* section
describes.

## What the customer gets

Contao 6.0 with the official example website
([`contao/contao-demo`](https://github.com/contao/contao-demo)) already in
place: a home page, news, a calendar, an FAQ, forms, a members area, a search
page and a styled theme. Front end on `/`, back end on `/contao`.

**Why content and not an empty site.** Contao seeds nothing and has had no web
installer since 5.0 — the install tool was removed. A fresh Contao with no
root page answers `404 Page not found` to every URL, which is a *correct*
answer from a CMS with no pages and a useless one for somebody who just clicked
deploy. `contao/contao-demo` is upstream's answer to exactly that (`composer
create-project contao/contao-demo` is how docs.contao.org says to get a demo),
and it is itself the Managed Edition manifest plus `files/`, `templates/`,
`theme.xml` and `var/backups/backup__*.sql`, a complete database dump.

The restore is guarded on `tl_page` being empty rather than on the deploy
stage, so a first deploy that failed halfway and was retried cannot overwrite a
site that already has content. To start from nothing instead, delete the pages
in the back end, or drop the backup file before the first deploy.

## Security

### The demo ships a working administrator, and the recipe kills it

`var/backups/backup__20260101000000.sql` contains `tl_user` row 1: `k.jones`
(Kevin Jones), `admin = 1`, `login = 1`, with a bcrypt hash that
`password_verify()` matches against **`kevinjones`** — checked against this
dump, not assumed. `tl_member` seeds `j.smith` the same way. Restored
unchanged onto a public domain, that is the Contao back end handed to anyone
who has read the demo's documentation.

`panelalpha/contao-setup.sh` gives **every** account the dump created a random
password and sets `disable = 1`, in the install stage, before Apache is
started. Disabled rather than deleted: the demo's pages, articles and news
items carry those ids in their author and permission columns, and removing the
rows would leave the example site pointing at users that do not exist. Verified
on the deployed site over its own HTTPS domain: `admin` and the generated
password land on the Dashboard, while `k.jones` / `kevinjones` is bounced back
to `/contao/login` and `/contao` keeps rendering the login form.

To use a demo member account, re-enable it in the back end and set a password
there.

### The account's own administrator

Contao has no sign-up page, so one is created:
`~/project/.panelalpha-admin-password` (0600) holds `admin`, a generated
20-character password and `admin@example.com`, and the setup script creates the
user with `contao:user:create --admin` — only when that username does not
already exist, so a redeploy never resets a password the customer has changed.

The password is generated **once**, into `~/.panelalpha/contao.env`, and reused
from there. `~/project` is cleared before every clone while the account's MySQL
database is not, so a regenerated password would be one the database never
learns. (`$HOME` itself is root-owned 0755 and an account cannot create files
directly in it, hence the subdirectory.)

### What is not web-readable

`docroot: public` is declared rather than left to `PhpDocroot`'s probe order,
because the answer decides what a visitor can fetch. Outside the document root
and therefore unreachable: `.env`, `.env.local`,
`.panelalpha-admin-password`, `composer.json`, `composer.lock`, `.git/`,
`vendor/`, `var/` (logs, cache, the database dump) and the whole
`.contao-monorepo/` tree. Verified on the deployed site: the dotfiles and
`.contao-monorepo/` answer 403, the rest 404, and none of them return content.

`files/` *is* reachable, through the `public/files/contaodemo` symlink
`contao:symlinks` creates. That is Contao's media library and is meant to be:
the `.public` marker file in each folder is what controls it.

## How it is installed, and why not on the host

The `php` manifest's build step runs
`composer install --no-dev --no-interaction --no-scripts --no-plugins
--optimize-autoloader` in a throwaway container **on the host daemon**, and
`PhpHostBuild::mayRunPlugins()` lifts `--no-plugins` only when the project's
lock pins nothing but an allowlist of scaffolding plugins. Contao's two are not
on that list, and both are load-bearing:

* **`contao/manager-plugin`** subscribes to `POST_INSTALL_CMD` and writes
  `vendor/contao/manager-plugin/.generated/plugins.php`. `PluginLoader` does
  `include` on exactly that path and nothing else, so without it Contao's
  kernel registers **zero bundles** — no routes, no DCA, no back end.
  `composer dump-autoload` is not a substitute; the listener is on
  `POST_INSTALL_CMD`, not `POST_AUTOLOAD_DUMP`.
* **`contao-components/installer`** is the custom installer for the
  `contao-component` package type. Its `getInstallPath()` is
  `extra.contao-component-dir . '/' . basename($package)`, so nineteen
  packages — TinyMCE, MooTools, jQuery, the Contao back-end theme — belong in
  `assets/`. With the plugin off they stay in `vendor/` and every back-end
  script and stylesheet is a 404.

Measured on this host: that host pass installs 180 packages in about 25 s and
leaves no `public/`, no `assets/` and no `plugins.php`. This is the shape of
engine#168.

So the host pass is left to do the part it is good at — resolving the graph and
writing `composer.lock`, with a per-account Composer cache, which makes the
second pass an install and not a resolve — and `panelalpha/contao-setup.sh`
runs `composer install` again **in the account's own container**, with plugins
and scripts enabled. There, Composer asks the components installer where each
`contao-component` belongs, finds `assets/` empty and fetches those nineteen
into it; the manager plugin writes `plugins.php`; and the root package's
`post-install-cmd` runs `vendor/bin/contao-setup`, which installs the skeleton
(`public/index.php`, `public/preview.php`, `public/.htaccess`, `bin/console`),
the bundle assets, the symlinks and a warm prod cache. The stale
`vendor/contao-components/*` copies the first pass left behind are then
deleted — about 30 MB.

The demo's own `composer.json` is **not** used verbatim: it carries no
`config.allow-plugins`, because it is meant to be installed by `composer
create-project`, which asks interactively. A non-interactive install without it
dies on *"contao-components/installer contains a Composer plugin which is
blocked by your allow-plugins config"*. The manifest `prepare.sh` writes is
`contao/managed-edition`'s plus that block, plus the `php` constraint from the
checkout.

`public/index.php` has to exist **before** any of that: the PHP strategy
refuses a project it cannot find an `index.php` in, and that check runs long
before the install stage. `prepare.sh` copies
`.contao-monorepo/manager-bundle/skeleton/public/index.php` into place — the
same file `skeleton:install` will overwrite with the installed release's copy.

## APP_SECRET, and a `.env` gotcha used on purpose

The generated compose file carries `env_file: - .env`, read at container
*creation*, so every line of a project `.env` becomes a real environment
variable that Symfony's Dotenv will never overwrite. That normally shadows
values an application means to write for itself. Here it is what is wanted.

`ContaoSetupCommand` generates a **new** `APP_SECRET` into `.env.local`
whenever the kernel secret it sees is empty, and it runs on every deploy. Left
alone, the secret would rotate on each redeploy and take every session and
remember-me cookie with it. `prepare.sh` writes the generated secret into
`.env` instead, where it is a process environment variable before contao-setup
looks — measured: after a full install `.env.local` contains `DATABASE_URL`
and nothing else.

`DATABASE_URL` goes the other way, into `.env.local`: the credentials do not
exist when the prepare hook runs. The password is `rawurlencode`d before it is
put in the DSN — a `/`, `@` or `#` in a generated password would otherwise move
the host or truncate the URL.

## Database

`database: mysql`. Contao is MySQL or nothing — `doctrine/dbal` on a `mysql:`
DSN, and `contao:migrate` builds roughly 35 `tl_*` tables from the DCA. The
checkout has no compose file and no `DATABASE_URL` to point the engine at one,
so the manifest key asks for a database on the account's own MySQL server:
visible in the panel, openable in phpMyAdmin, inside the account's backup, and
costing the host neither a container nor a volume.

`contao:migrate` runs with `--no-backup`. It otherwise dumps the whole database
into `var/backups` before every migration, and `var/` is inside the checkout,
which the next deploy clears — so the dump costs time and disk and is gone
before anyone could use it.

## php.ini

The shared PHP base image loads no `php.ini` at all (`php --ini` →
*"Loaded Configuration File: (none)"*), and its `conf.d` is inside the image
where the account uid cannot write. The compose override points
`PHP_INI_SCAN_DIR` at that directory *plus* `/app/panelalpha/php`, which
reaches the Apache module and the CLI both — and the CLI is what runs the whole
install stage. `files/panelalpha/php/zz-contao.ini` raises `memory_limit` to
512M (Contao asks for 256M and warns below it; `cache:warmup` over the DCA set
is the peak), the upload limits to 32M from 2M/8M — a file manager that rejects
a photograph is not a file manager — and `max_input_vars` to 5000, because a
long back-end record posts past the default 1000 and PHP drops the rest of the
POST silently.

The image's own directory is listed first and explicitly: `PHP_INI_SCAN_DIR`
*replaces* the compiled-in path rather than adding to it, and dropping it would
unload every `docker-php-ext-*.ini` — intl, gd, pdo_mysql, opcache, the lot.

## Readiness

`AppLauncher` runs `docker compose up -d` without `--wait` and the probe hits
as soon as it returns (engine#90). The install stage here is a Composer
install, a database restore and a migration — minutes on a shared host — and a
Contao whose prod cache is still being built answers 500, which was seen
repeatedly while developing this recipe.

`ready` — `alpine:3`, `entrypoint: exit 0`, `restart: "no"` — waits on the app
healthcheck, so `up -d` blocks until Contao answers. A clean `exit 0` is not a
crash loop to `AppHealth::isCrashing()`.

The healthcheck is two requests, both on `127.0.0.1:8000`:

1. `GET /` — the demo front page. **A 301 is a pass**: the demo's root page has
   `useSSL = 1`, so a loopback request carrying no `X-Forwarded-Proto` is
   redirected to `https://`, and `curl -f` fails only from 400 up. What it
   proves is that the kernel booted, the bundles are registered and the
   database answered — a Contao that cannot do those things 500s here.
2. `GET /contao/login`, grepped for `name="password"` rather than trusted for
   its status. The back end is the half that a missing `plugins.php` or an
   empty `assets/` breaks while the front end still renders.

`start_period: 600s`, because the install stage does the Composer install, the
demo restore and the migration before Apache binds.

## Trusted proxies

`TRUSTED_PROXIES` is Contao's own knob, read by `ContaoKernel::fromRequest()`
before the kernel boots (`manager-bundle/src/HttpKernel/ContaoKernel.php:229`)
and passed to `Request::setTrustedProxies` with
`X_FORWARDED_FOR|PORT|PROTO`. The engine's virtual host sends all three, so
this is what makes Contao see `https` and log the visitor's address rather than
the proxy's. `TRUSTED_HOSTS` is deliberately left unset — Contao resolves which
site to serve from the `Host` header and an account may answer on several
names — which also means `X-Forwarded-Host` stays untrusted.

## Memory

`mem_limit: 1400m` on the app, 64m on `ready`: 1464 m inside a 2000 m account.
`ServiceLimits` caps an app-role service it did not write at 384m and the
install stage does not fit in it — a Composer install over 180 packages, then
contao-setup, then the demo restore and `contao:migrate`. In an override file
the limit reaches Docker as written; the hardener never sees it. Measured idle
after a first boot: about 115 MB.

## Not configured

* **Mail.** Contao sends password resets, form notifications and newsletters
  through a `MAILER_DSN` the engine does not provide. Set it in `.env.local`.
* **The cron.** Contao runs its scheduled jobs from the front-end request when
  no CLI cron is registered, which is the default here and is enough for a
  small site. `php vendor/bin/contao-console contao:cron` on a real schedule is
  better for a busy one.
* **No `overrides/app.sh`.** `contao:user:create`, `contao:user:password` and
  `contao:user:list` exist, so user management is reachable, but nothing here
  advertises it yet.
* **The Contao Manager** (the web UI for installing extensions) is a separate
  `.phar` and is not installed. Extensions are a `composer require` in the
  account's shell followed by a redeploy.
