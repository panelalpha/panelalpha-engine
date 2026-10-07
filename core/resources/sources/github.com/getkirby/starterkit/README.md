# Kirby Starterkit

Upstream: <https://github.com/getkirby/starterkit>

The runnable Kirby site. `getkirby/starterkit` is a `"type": "project"`
composer package that ships a root `index.php`, the whole CMS in a committed
`kirby/`, and demo `content/` + `site/`. It is the deployable sibling of
`getkirby/kirby`, the core *library*, which has no `index.php`/`content/`. No
second repo is cloned: this repo *is* the project.

Kirby is flat-file. Its database is three directories inside the checkout —
`content/` (pages and files), `site/accounts/` (Panel password hashes) and
`site/config/` (config and the content salt). A rebuild empties `~/project`
(`ProjectTree::clearContents`), which would destroy every one of
them: pages, the Panel account, the admin password and `content.salt`.
**Getting that data across a rebuild is the entire recipe.**

## The persistence design

The three stateful trees live in `~/.panelalpha/kirby` and are symlinked back
into the fresh checkout by `hooks/prepare.sh` — the same lever the shipped
Apaxy recipe uses. `~/.panelalpha` is scaffolded by the engine, owned
by the account, and untouched by a rebuild; it is the only writable place
outside `~/project`, because the account home is `chown root:root` on every
deploy (`core/app/System/Project.php:813`). A named volume is not usable —
nothing in the product can put the demo content or a Panel upload *into* one.

`overrides/docker-compose.override.yml` mounts `../.panelalpha/kirby` at `/data`
in the app container (the compose file's parent is the account home, exactly as
Apaxy mounts `../.panelalpha/apaxy/files`). `prepare.sh`, after the clone and
before the container, seeds the persisted store once and then replaces the
checkout copies with symlinks to `/data`:

| checkout path | → | persisted at |
|---|---|---|
| `content` | → | `/data/content` (seeded once from the demo content) |
| `site/config` | → | `/data/config` (seeded once; a production `config.php` is written with `debug: false` and a random 64-hex `content.salt`) |
| `site/accounts` | → | `/data/accounts` (empty; the Panel writes here) |
| `site/sessions` | → | `/data/sessions` (empty; login sessions) |

The symlink targets are the *container* path `/data`; on the host they dangle,
which is harmless — Apache serves pages through `index.php` (PHP file reads),
never the symlink target directly, and the vhost sets `+FollowSymLinks` on the
document root (`apache-vhost.stub`) so PHP resolves them. `media/` is left in
the checkout: it is regenerable thumbnails Kirby rebuilds from `content/` on
demand, and pointing it at `/data` would put its target under the vhost's
`<Directory /> Require all denied`, 403-ing every image.

`content.salt` is set explicitly (Kirby otherwise derives it from the site
path and warns; `kirby/src/Cms/App.php:441`). Because the whole `site/config`
directory is persisted, upstream's own `config.php` is not carried across
updates — an accepted trade for a flat-file CMS whose config holds the secret.

### Closing the installer

Kirby's installer is open whenever `users()->count() === 0`
(`kirby/src/Cms/System.php:259`); first visitor wins. `prepare.sh`
generates an admin password once (kept 0600 at `~/.panelalpha/kirby/admin.pw`,
outside the document root) and the `kirby-init-admin` **start**-stage command
creates the admin from it *inside the container, before the serve command execs
Apache* — so the account exists before the port opens. It is idempotent (does
nothing once a user exists), so a redeploy keeps the persisted account and its
password. After a deploy `/panel/installation` answers **302** (closed), not the
setup form.

## The one real obstacle: composer misplaces Kirby's root

The php platform resolves dependencies on the host (`PhpHostBuild`), and the
shared base image re-runs `composer install` if `vendor/autoload.php` is
missing (`panelalpha-base-entrypoint.sh`). Either way `getkirby/cms` lands in
`/app/vendor/`. `kirby/bootstrap.php` **prefers `/app/vendor/autoload.php`**
when it exists, so `index.php`'s bare `new Kirby()` (no explicit roots)
autodetects its index root from the loaded CMS location — `/app/vendor/getkirby`
— and reads `content/`, `site/` and `accounts/` from that empty tree. Every
page 404s.

The starterkit ships its whole runtime in the committed `kirby/` (it is a
downloadable zip, not a composer install), so the vendor tree is redundant.
The `kirby-drop-vendor` start command (`rm -rf /app/vendor`, `before: true`)
removes it before serving; the bootstrap then falls back to
`kirby/vendor/autoload.php` and the root resolves to `/app`. This is
configuration — dropping an engine-generated build artifact — not a source
patch: no upstream file is touched, no version pinned.

**This is an engine gap, not a recipe quirk.** Without the recipe the stock
repository deploys successfully but serves **HTTP 404 `error.notFound`** on
`/`: under the current php strategy it does not serve its own pages. See
*Engine defect* below.

## Exposure

| request | result |
|---|---|
| `/site/accounts/<id>/.htpasswd` | **403** — Apache server-level deny (`<DirectoryMatch "/\.">`); the password hash is never served |
| `/site/config/config.php` | **404** — `.htaccess` rewrites `^site/` to `index.php`; the salt is not served |
| `/content/...` | **404** — `^content/` rewritten; page sources not served (rendered pages are) |
| `/.git/config` | **403** |
| `/kirby/bootstrap.php` | **404** — `^kirby/` rewritten |
| `/panelalpha-entrypoint.sh`, `/docker-compose.yml` | **403** — vhost `FilesMatch` |
| `/panel/installation` | **302** — installer closed |
| `/composer.json` | **200** — upstream ships it at the root and blocks only dotfiles/`content`/`site`/`kirby`; non-secret (name, type, the Kirby version constraint, all public on GitHub). |

`admin.pw`, the generated init script and the persisted config all live under
`/data`, which is not below the document root — no URL maps to them.

## Engine defect

`kirby/bootstrap.php` and any PHP project whose `index.php` boots from its own
committed runtime rather than `vendor/autoload.php` are broken by the php
strategy always producing a `vendor/`:

* **Where:** the host build (`PhpHostBuild`, driven by php.yaml's
  `composer-install`) and `panelalpha-base-entrypoint.sh` (re-runs
  `composer install` when `/app/vendor/autoload.php` is absent).
* **What it does:** installs `getkirby/cms` into `/app/vendor`. Kirby's
  bootstrap prefers `/app/vendor/autoload.php`, so `new Kirby()` autodetects
  its index root as `/app/vendor/getkirby` and reads an empty content tree.
* **What it should do:** for a `"type": "project"` app that ships a runnable
  tree and does not enter through `vendor/autoload.php`, either skip the
  composer step or not force a vendor tree that shadows the app's own. A
  manifest opt-out ("this project vendors itself, do not composer it") would
  let a recipe say so without a `rm -rf /app/vendor` workaround.
* **What it costs:** a working, unmodified upstream app serves HTTP 404 on
  every page after a clean deploy. The recipe
  papers over it, but re-runs and drops a ~vendor install every boot.

## Not covered / trade-offs

* **Nagware.** Kirby is proprietary; an unlicensed deploy runs with full Panel
  function and shows an "activate your licence" banner in the Panel. Not a
  technical blocker; the operator decides whether nagware belongs in the
  catalogue. If a licence is activated, `.license` lands in the persisted
  `site/config` and survives.
* **Composer waste.** The host build still installs `vendor/` each deploy only
  for the start command to delete it. Removing `composer.json`
  in `prepare.sh` would skip it, but the platform's `composer-install` command
  merges in regardless and could fail against a missing manifest, so the safe
  `rm -rf /app/vendor` is used instead.
* **Config updates.** Persisting `site/config` freezes it at first deploy;
  upstream `config.php` changes do not propagate. Inherent to holding the salt
  and licence there.
* **Admin email** defaults to `admin@example.com` (settable via
  `PA_KIRBY_ADMIN_EMAIL` in `prepare.sh`'s environment). The password is
  generated per install and printed to the deploy log and kept at
  `~/.panelalpha/kirby/admin.pw`.
* **Sessions** are persisted, so a login survives a redeploy.
