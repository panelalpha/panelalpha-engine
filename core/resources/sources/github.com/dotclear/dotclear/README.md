# Dotclear — git.dotclear.org/dev/dotclear

Dotclear 2.40-dev, a self-contained PHP blogging platform (GPL-2.0). The tracker
row points at the runnable distribution, not a component: `index.php` at the
repository root is the public blog's front controller and `admin/index.php` is
the backend. There is no `vendor/` to install — `composer.json` requires only
`"php": ">=8.2"` and Dotclear autoloads its own `src/` tree — so plain `php`
detection (composer.json, no artisan) builds and boots it as-is.

## What the bare deploy gets wrong (why the row was Unsupported)

A no-recipe control deploy returns **HTTP 200**, but the page is
`<title>Dotclear installation wizard</title>` and `/admin/install/index.php` is
open: the deploy is green while the app is an uninstalled, unconfigured, open
installer with no database. "200 on / is not evidence the app works."

The recipe fixes three things, none of them a code change to upstream:

1. **Document root.** Dotclear serves from its root. Older releases shipped an
   empty `public/` that the base image's `-d /app/public` probe serves as a 403
   (PhpDocroot.php documents this by name). This HEAD ships no `public/`, so
   detection already resolves the root; `docroot: "."` pins it against the media
   symlink creating a `public/` at runtime. Measured on stock mariusz2
   (`LATE_CANDIDATES=['src']`; `src/` has no `index.php`, so it never wins).

2. **The installer is first-visitor-wins.** `files/panelalpha/dotclear-setup.sh`
   drives Dotclear's own CLI installer (`admin/install/index.php`) before Apache
   binds, seeding `config.php` (DB DSN + a per-account `DC_MASTER_KEY` that
   `md5(uniqid())` generates) and the super-admin, password generated per
   account into `~/.panelalpha/dotclear/admin-credentials.txt`. Afterwards the
   wizard reports "already installed" and creates nobody.

   The installer's own `-n` non-interactive mode is broken at HEAD:
   `Install\Utility::init()` forces `argv[1]` as `DC_RC_PATH`, and PHP `getopt`
   then stops at that leading positional and parses none of the `--db*` flags.
   So the script feeds the interactive prompts over stdin and steers the config
   path with the `DC_RC_PATH` *environment* variable (read when Config is
   constructed; `argv[1]` is consumed too late). Upstream is left untouched.

3. **`~/project` is emptied every deploy** (engine#173, confirmed:
   `GitRepository::cloneConfiguredRepository()` calls `clearContents()` before
   the clone). Dotclear keeps `config.php`, the master key and uploaded media
   inside the tree, so `overrides/docker-compose.override.yml` bind-mounts the
   account home's `~/.panelalpha` (which survives) into the app container at
   `/pa-data`, and the setup script keeps `config.php`, `public/` (media),
   `cache/` and `var/` there, symlinking them back in on every boot. The posts
   live in the account's own MySQL (`database: mysql`) and survive on their own.

## Notes

- `PHP_INI_SCAN_DIR: ":/app/.pa-php"` — the **leading colon is load-bearing**.
  The base image loads its `mysqli`/`pdo_mysql` from the compiled-in scan dir;
  a bare `/app/.pa-php` replaces that dir and silently drops every DB extension.
- `blog_url`/`admin_url` are seeded from `APP_URL` (the public https origin), so
  Dotclear's stored absolute URLs are what a visitor uses, not `http://…:8000`.
- **The source URL is unreachable to the engine.** `git.dotclear.org` returns a
  bare nginx 403 to every non-browser client (git and curl, any User-Agent), so
  the engine cannot clone it. The recipe was written and verified against the
  byte-identical GitHub mirror `github.com/dotclear/dotclear` at the same HEAD.
  No recipe can grant host-level access; if the engine's network cannot reach
  git.dotclear.org, this row depends on that mirror.

## Verified (mariusz2, Dotclear HEAD b8df5e6, 2026-09-21)

Deploy 52 s, `serving: ok`, HTTP 200. Installed over the public HTTPS domain;
web installer closed; logged into the admin, created and published a post, saw
it render for an anonymous visitor; uploaded media (multipart, from inside the
container per engine#170) and served it. Config, master key, admin password,
post and media all survived a real `POST rebuild`. App container ~33 MiB idle,
account container ~235 MiB, no MySQL sidecar.
