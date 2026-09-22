# grocy (github.com/grocy/grocy)

Household ERP — stock, shopping list, chores, recipes, equipment. A Slim 4 PHP
application on SQLite: no database server, no queue, no cache, no build step
that produces anything. It is about as small as a hosted app gets.

Detection is already right: `composer.json`, no `artisan`, so the `php`
platform (priority 930), the shared PHP base image with `~/project`
bind-mounted at `/app`, and `public/` as the document root. The recipe does not
change any of that — it says `extends: php` so nothing has to be re-derived,
and adds the four things a clone is missing.

## Why it answered 500

`public/index.php` runs `helpers/PrerequisiteChecker.php` before it loads
anything else. Two of its checks fail on a fresh clone:

- **`data/config.php` does not exist.** grocy ships `config-dist.php` at the
  root and a `data/` directory containing only `.gitignore`; the install
  instructions are "copy one to the other". `app.php` then `require_once`s the
  same path, so this is fatal twice over. `files/data/config.php` supplies it.
- **`packages/autoload.php`** — Composer's autoloader, under the vendor
  directory grocy renames to `packages/` in `composer.json`. The engine's own
  Composer pass writes it; nothing extra is needed.

The other required boxes are already ticked by the base image: PHP 8.5.10
against `"php": "8.5.*"`, `fileinfo pdo_sqlite gd ctype intl zlib mbstring`
all present, argon2id available for the password hashing in migration 0027,
and SQLite 3.40.1 against a `REQUIRED_SQLITE_VERSION` of 3.40.0 — the one
requirement that is only just met.

## Why a 200 would not have been enough

grocy's stylesheets and JavaScript are **yarn** dependencies. `.yarnrc` pins
`--modules-folder public/packages`, and `views/layout/default.blade.php` loads
`packages/bootstrap`, `packages/jquery`, `packages/datatables.net-bs4`,
`packages/@fortawesome/...` from exactly there. Upstream's whole install is
`composer install` then `yarn install` (`.devtools/install_dependencies.bat`).

The engine compiles a PHP project's frontend only when `package.json` declares
a `build` script (`HostCompile::runForPhp`), and grocy's declares no scripts at
all — so yarn never ran and the pages came back unstyled and inert.
`hooks/prepare.sh` gives `package.json` a build script, which is the switch
that turns the Node pass on; `yarn install` is the entire build. The script
body is `test -d public/packages/bootstrap || yarn install`, because the
engine's node_modules cache can report a lockfile hit on a redeploy whose
`~/project` was re-cloned without `public/packages` in it.

The hook writes a **second** script, `pa-needs-git`, that nothing ever runs.
`@danielfarrell/bootstrap-combobox` is a repository rather than a registry
release (`yarn.lock` resolves it to
`github.com/berrnd/bootstrap-combobox.git#master-fork`), so yarn 1 shells out
to `git` — and the host compile runs a stock `node:*-bookworm-slim` image,
which has none. The deploy died on `error Couldn't find the binary git` after a
clean `composer install`. `NodeRuntime::needsGitBinary()` swaps in the full
`bookworm` image when it finds a literal git invocation among `package.json`'s
scripts, and that script is how this project says so. It inspects scripts and a
list of build plugins, never dependency URLs; if the engine learns to read
those, delete the script.

## Readiness, and the 500 that survived the first gate

grocy builds its schema **on the first HTTP request**, not at boot:
`SystemController::Root` runs `DatabaseMigrationService` over the 258 files in
`migrations/` (about 1.5s against an empty file). Apache is listening long
before that, and the engine runs `docker compose up -d` without `--wait`, so
the health probe would otherwise be the request that runs the migration.
`overrides/docker-compose.override.yml` adds a healthcheck and a no-op `ready`
service gated on it.

A one-request healthcheck is not enough, and the failure is worth knowing. The
**first** request after a deploy never reaches the root route: `app.php` sees
the version hash change, empties `data/viewcache` and answers 302. A check
satisfied by that 302 went healthy in two seconds, `ready` exited, the deploy
was declared finished — and `AppHealth`'s probe then arrived *during* the
migration the next request had just started. The result was one HTTP 500 on a
site that answered 302 a second later and served its login page to the public
domain: `deploy=completed`, `http=200`, verdict `serving-error_page`.

So the check is a sequence: `GET /` (absorbs the viewcache redirect), `GET /`
(the migration, synchronous), `GET /login` (a page that only renders from a
migrated schema). It is idempotent, so a retry replays it harmlessly.

## Security: the default login is `admin` / `admin`

`migrations/0027.php` creates it, migrations are the only way a grocy gets a
user, and grocy ships no CLI to change one — so **every fresh deploy is open to
anyone who has read the repository** until someone logs in and changes the
password (top-right user menu → *Edit this item* / *Change password*). This
recipe does not rotate it: the only place to do so is the SQLite file, which
does not exist until the first request, and a redeploy must never reset a
password an operator has since chosen. Change it first; an `app.sh` that owns
users properly is the real fix and is not written yet.

## Files

| File | Why |
|---|---|
| `panelalpha.yaml` | `extends: php`, `docroot: public`, and the account of what was wrong |
| `files/data/config.php` | the config file grocy refuses to boot without; deliberately holds no settings so `config-dist.php` stays the source of defaults |
| `hooks/prepare.sh` | adds a `build` script to `package.json` so the engine runs `yarn install` into `public/packages`, and a `pa-needs-git` script so that pass gets an image with git |
| `overrides/docker-compose.override.yml` | a three-request healthcheck plus a `ready` gate, so the deploy waits for the first-request migration |

## Verified

On a 2-core / 3.7 GB engine, account capped at 1200 MB: `deploy-ok`, deploy
75s warm (about 195s cold, most of it the `node:20-bookworm` pull), port probe
`HTTP 302`, domain `200 — Login | Grocy`, every baseline check passing. Beyond
the status code: `packages/jquery`, `packages/@fortawesome/…` and
`packages/@danielfarrell/bootstrap-combobox/…` (the git-sourced one) all serve
200; `POST /login` with `admin`/`admin` redirects to `/` rather than
`/login?invalid=true`; `/stockoverview` renders 45 KB of authenticated page;
and `POST /api/objects/shopping_list` followed by a `GET` returns the row it
wrote, so the SQLite file is real and writable.

Not needed, and deliberately absent: a database sidecar (SQLite), generated
secrets (grocy has none), and `BASE_URL` (left at `/`, so `UrlManager` derives
every link from the request's own `Host` and `X-Forwarded-Proto`, which works
for both the public domain and the loopback probe).
