# Kirby Starterkit

Upstream: <https://github.com/getkirby/starterkit> · tracker:
panelalpha/playground/supported-apps#1521

The runnable Kirby site. `getkirby/starterkit` is a `"type": "project"`
composer package that ships a root `index.php`, the whole CMS in a committed
`kirby/`, and demo `content/` + `site/`. It is the deployable sibling of #844
(`getkirby/kirby`, the core *library*, which has no `index.php`/`content/` and
was rejected). No second repo is cloned: this repo *is* the project.

Kirby is flat-file. Its database is three directories inside the checkout —
`content/` (pages and files), `site/accounts/` (Panel password hashes) and
`site/config/` (config and the content salt). A `POST /rebuild` empties
`~/project` (engine#173, `ProjectTree::clearContents`), so on the #844 attempt
every one of them was destroyed: page → 404, Panel account replaced, admin
password and `content.salt` regenerated. **Getting that data across a rebuild
is the entire recipe**, and it is proven below, byte for byte.

## The persistence design

The three stateful trees live in `~/.panelalpha/kirby` and are symlinked back
into the fresh checkout by `hooks/prepare.sh` — the same lever the shipped
Apaxy recipe (#798) uses. `~/.panelalpha` is scaffolded by the engine, owned
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
(`kirby/src/Cms/System.php:259`); first visitor wins (engine#200). `prepare.sh`
generates an admin password once (kept 0600 at `~/.panelalpha/kirby/admin.pw`,
outside the document root) and the `kirby-init-admin` **start**-stage command
creates the admin from it *inside the container, before the serve command execs
Apache* — so the account exists before the port opens. It is idempotent (does
nothing once a user exists), so a redeploy keeps the persisted account and its
password. Measured: after deploy, `/panel/installation` → **302** (closed), not
the setup form; a real Panel HTTP login with the persisted password → **200**.

## The one real obstacle: composer misplaces Kirby's root

The php platform resolves dependencies on the host (`PhpHostBuild`), and the
shared base image re-runs `composer install` if `vendor/autoload.php` is
missing (`panelalpha-base-entrypoint.sh`). Either way `getkirby/cms` lands in
`/app/vendor/`. `kirby/bootstrap.php` **prefers `/app/vendor/autoload.php`**
when it exists, so `index.php`'s bare `new Kirby()` (no explicit roots)
autodetects its index root from the loaded CMS location — `/app/vendor/getkirby`
— and reads `content/`, `site/` and `accounts/` from that empty tree. Every
page 404s.

Measured in the container:

```
new Kirby()                         → index=/app/vendor/getkirby   site children=0
new Kirby(['roots'=>['index'=>'/app']]) → content=/app/content     site children=6
```

The starterkit ships its whole runtime in the committed `kirby/` (it is a
downloadable zip, not a composer install), so the vendor tree is redundant.
The `kirby-drop-vendor` start command (`rm -rf /app/vendor`, `before: true`)
removes it before serving; the bootstrap then falls back to
`kirby/vendor/autoload.php` and the root resolves to `/app`. This is
configuration — dropping an engine-generated build artifact — not a source
patch: no upstream file is touched, no version pinned.

**This is an engine gap, not a recipe quirk.** A control deploy of the stock
repo with the recipe removed — same host, same commit — is `deploy-ok` at 43s
but serves **HTTP 404 `error.notFound`** on `/`. So the tracker's "the
starterkit deploys and serves HTTP 200" holds only for *deployability*; under
the current php strategy the stock repo does not serve its own pages. See
*Engine defect* below.

## Verified

Recipe account `kirbyrcp` on `mariusz2.panelalpha.tools` (2 cores, 3.7 GB),
`github.com/getkirby/starterkit@d5d9afb`, php 8.2 base image, `via: recipe`,
`strategy: php`, PA_DOCROOT `/app` (`docroot: .`; `PhpDocroot` folds `''`/`'.'`
here because the root has `index.php`, engine#172 — declared for intent).

* **Deploy:** `deploy-ok`, first deploy 54s (preparing 9s, cloning 6s,
  running), rebuild 16s. Control (no recipe) 43s.
* **Serving:** `/` → **HTTP 200**, 10,401 B, `<title>Mægazine | Home</title>`,
  demo content rendered.
* **Past the probe, over public HTTPS** (`kirbyrcp-4e56.panelalpha.online`):
  Panel login with the generated password (real `POST /api/auth/login`) → 200;
  created page `pa-test` (`POST /api/site/children`) and published it
  (`PATCH …/status` → `listed`) → 200; the page renders anonymously (`/pa-test`
  → 200, "PA Test Page" / "persistence marker 42"); a **file uploaded through
  the Panel** (`POST /api/pages/pa-test/files`, multipart `marker.png`) → 200
  in **18 ms from inside the container** — engine#170's 60 s multipart stall is
  a property of the public proxy path, so uploads were driven at
  `localhost:8000`, and it serves at `/media/pages/pa-test/…/marker.png` → 200.

### Redeploy survival — the acceptance test

A page, a Panel account, an uploaded file, an admin password and a
`content.salt` were created, then `POST /projects/kirbyrcp/rebuild` re-cloned
`~/project` (log: `Cloning into '/home/kirbyrcp/project'`, `index.php` mtime
reset, symlinks recreated by `prepare.sh`). Fingerprints before and after,
md5 where a file:

| thing | before | after | |
|---|---|---|---|
| `content.salt` | `f09eaafc…dab3d8` | `f09eaafc…dab3d8` | ✅ same |
| `site/config/config.php` (md5) | `693b4e0c…` | `693b4e0c…` | ✅ same |
| Panel account id | `NtpuOTTa` | `NtpuOTTa` | ✅ same |
| `accounts/*/.htpasswd` (md5) | `d47ef677…` | `d47ef677…` | ✅ same |
| `accounts/*/index.php` (md5) | `dbc7b07b…` | `dbc7b07b…` | ✅ same |
| page `content/1_pa-test/default.txt` (md5) | `44b4869d…` | `44b4869d…` | ✅ same |
| uploaded `marker.png` (md5) | `2605723f…` | `2605723f…` | ✅ same |
| admin password | `GuGgws…Aqbee7` | `GuGgws…Aqbee7` | ✅ same |
| `/pa-test` anonymous | — | HTTP 200, marker text present | ✅ renders |
| `/media/…/marker.png` | — | HTTP 200 | ✅ serves |
| password login (`validatePassword`) | OK | **OK** | ✅ same password |

Every stateful byte survived the rebuild that destroyed all of it on #844.

### Exposure (bodies, not codes)

| request | result |
|---|---|
| `/site/accounts/NtpuOTTa/.htpasswd` | **403** — Apache server-level deny (`<DirectoryMatch "/\.">`); the password hash is never served |
| `/site/config/config.php` | **404** — `.htaccess` rewrites `^site/` to `index.php`; the salt is not served |
| `/content/1_pa-test/default.txt`, `/content/site.txt` | **404** — `^content/` rewritten; page sources not served (rendered pages are) |
| `/.git/config` | **403** |
| `/kirby/bootstrap.php` | **404** — `^kirby/` rewritten |
| `/panelalpha-entrypoint.sh`, `/docker-compose.yml` | **403** — vhost `FilesMatch` |
| `/panel/installation` | **302** — installer closed |
| `/composer.json` | **200** — upstream ships it at the root and blocks only dotfiles/`content`/`site`/`kirby`; non-secret (name, type, the Kirby version constraint, all public on GitHub). Noted, not fixed. |

`admin.pw`, the generated init script and the persisted config all live under
`/data`, which is not below the document root — no URL maps to them.

### Memory

`project-app-1` (Apache + PHP 8.2) idles at **42.8 MiB**; the account DinD
container at **120 MiB**. No sidecar (flat-file, no database).

## Engine defect (described, not filed)

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
  every page after a clean deploy (measured on the control account). The recipe
  papers over it, but re-runs and drops a ~vendor install every boot.

## Not covered / trade-offs

* **Nagware.** Kirby is proprietary; an unlicensed deploy runs with full Panel
  function and shows an "activate your licence" banner in the Panel. Not a
  technical blocker; the operator decides whether nagware belongs in the
  catalogue. If a licence is activated, `.license` lands in the persisted
  `site/config` and survives.
* **Composer waste.** The host build still installs `vendor/` each deploy only
  for the start command to delete it (~a few seconds). Removing `composer.json`
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
* **Sessions** are persisted, so a login survives a redeploy; not required by
  the acceptance test but included since it is free.
