# Dotclear — git.dotclear.org/dev/dotclear

Dotclear 2.40-dev, a self-contained PHP blogging platform (GPL-2.0). The repository
is the runnable distribution, not a component: `index.php` at the
repository root is the public blog's front controller and `admin/index.php` is
the backend. There is no `vendor/` to install — `composer.json` requires only
`"php": ">=8.2"` and Dotclear autoloads its own `src/` tree — so plain `php`
detection (composer.json, no artisan) builds and boots it as-is.

## What the bare deploy gets wrong

Without the recipe the deploy returns **HTTP 200**, but the page is
`<title>Dotclear installation wizard</title>` and `/admin/install/index.php` is
open: the deploy is green while the app is an uninstalled, unconfigured, open
installer with no database. "200 on / is not evidence the app works."

The recipe fixes three things, none of them a code change to upstream:

1. **Document root.** Dotclear serves from its root. Older releases shipped an
   empty `public/` that the base image's `-d /app/public` probe serves as a 403
   (PhpDocroot.php documents this by name). This HEAD ships no `public/`, so
   detection already resolves the root; `docroot: "."` pins it against the media
   symlink creating a `public/` at runtime. `src/` has no `index.php`, so the
   `src` late candidate never wins.

2. **The installer is first-visitor-wins.** `files/panelalpha/dotclear-setup.sh`
   drives Dotclear's own CLI installer (`admin/install/index.php`) before Apache
   binds, seeding `config.php` (DB DSN + a per-account `DC_MASTER_KEY` that
   `md5(uniqid())` generates) and the super-admin, with the login the engine
   generates (`credentials:` in `panelalpha.yaml`), returned by
   `GET /projects/{name}/app-credentials` (MCP `app_credentials_get`). Afterwards the
   wizard reports "already installed" and creates nobody.

   The installer's own `-n` non-interactive mode is broken at HEAD:
   `Install\Utility::init()` forces `argv[1]` as `DC_RC_PATH`, and PHP `getopt`
   then stops at that leading positional and parses none of the `--db*` flags.
   So the script feeds the interactive prompts over stdin and steers the config
   path with the `DC_RC_PATH` *environment* variable (read when Config is
   constructed; `argv[1]` is consumed too late). Upstream is left untouched.

3. **`~/project` is emptied every deploy**
   (`GitRepository::cloneConfiguredRepository()` calls `clearContents()` before
   the clone). Dotclear keeps `config.php`, the master key and uploaded media
   inside the tree, so `overrides/docker-compose.override.yml` bind-mounts the
   account home's `~/.panelalpha/dotclear` (which survives; `hooks/prepare.sh`
   creates it) into the app container at `/pa-data/dotclear`, plus
   `app-credentials.env` read-only, and the setup script keeps `config.php`, `public/` (media),
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
  the engine cannot clone it. The byte-identical GitHub mirror
  `github.com/dotclear/dotclear` is what this recipe is keyed to. No recipe can
  grant host-level access; if the engine's network cannot reach
  git.dotclear.org, this recipe depends on that mirror.
