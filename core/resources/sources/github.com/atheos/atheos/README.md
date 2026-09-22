# Atheos (github.com/Atheos/Atheos)

A browser-based IDE — a maintained fork of Codiad. Plain PHP, no framework, no
Composer manifest, no database server: users, projects and settings are JSON
wrapped in PHP comments under `data/`, and the files it edits live under
`workspace/`. This recipe is commit `f90cd18` on `main` (`.version` 611).

Detection was already right — `runtime: php`, served from the repository root.
The `public/` directory Atheos ships holds one `README.md`; `PhpDocroot::detect`
requires an index file in a candidate directory, so it falls through to the
root, which is where `index.php` is. Nothing here names a `docroot`, and naming
`.` would change nothing: `PlatformManifest::readDocroot()` folds `.` to `''`
and the probe runs anyway (engine#172).

## The PHP error: it is a fatal, not noise

The verdict said `serving-php_error` and HTTP 200, which reads like a page with
warnings printed on it. It is not. This is the whole response body from a
deployed account before the recipe:

```
Notice: date_default_timezone_set(): Timezone ID '' is invalid in /app/common.php on line 129
Warning: session_name(): Session name cannot be changed after headers have already been sent in /app/common.php on line 170
Warning: session_start(): Session cannot be started after headers have already been sent in /app/common.php on line 171
Warning: Undefined global variable $_SESSION in /app/traits/exchange.php on line 75
Fatal error: Uncaught TypeError: array_key_exists(): Argument #2 ($array) must be
of type array, null given in /app/traits/exchange.php:75
Stack trace:
#0 /app/common.php(309): Common::data('SESSION', 'lang', NULL)
#1 /app/traits/i18n.php(185): SESSION('lang')
#2 /app/traits/i18n.php(84): i18n->getUserLangs()
#3 /app/common.php(176): i18n->init()
#4 /app/common.php(293): Common::startSession()
#5 /app/index.php(13): require_once('/app/common.php')
```

One notice takes the application down, and the chain is worth reading in full
because two of the links are the engine's, not Atheos's.

**The notice.** `Common::$configDefaults` gives `TIMEZONE` the default `false`.
The guard meant to catch that —

```php
foreach (self::$configDefaults as $key => $entry) {
    if (!defined($key)) define($key, $entry["default"]);   // defines TIMEZONE = false
}
if (!defined("TIMEZONE") && TIMEZONE !== false) { ... }    // never true
date_default_timezone_set(TIMEZONE);                        // false -> ""
```

— is dead code: the loop three lines earlier has already defined every key, so
`!defined("TIMEZONE")` cannot be true, and `false` arrives as `""`. Upstream
never sees it, because upstream's own installer writes a real timezone into
`config.php`, and `config.php` is exactly what a fresh checkout does not have.

**Printing it sends the headers.** The shared PHP base image runs with
`display_errors=1` and no `php.ini` at all, so the notice goes into the
response body rather than the log. That is an engine default, not Atheos's:
`php -i` in `panelalpha/php:8.3-apache-bookworm-pa20260910` reports
`Loaded Configuration File => (none)`, and both `php.ini-development` and
`php.ini-production` sit unused in `/usr/local/etc/php/`.

**Headers sent means no session.** `session_name()` and `session_start()` at
`common.php:170-171` both refuse, `$_SESSION` is never created, and
`Common::data()` calls `array_key_exists($key, null)` — a `TypeError` in PHP 8.

The recipe cuts the chain in two places, deliberately, because they fail
differently:

* `config.php` — written by the install stage — defines `TIMEZONE` as `"UTC"`,
  which is what upstream's installer would have done, and sets
  `display_errors=0` / `log_errors=1` so nothing else ever reaches a visitor.
* the root `.htaccess` — written by the after-clone hook, which runs earlier
  and is not a stage command — carries `php_flag display_errors off` inside an
  `<IfModule mod_php.c>`. This is the copy that holds on a deploy where the
  install stage did not run at all, which is a thing that happens
  (**engine#169**, below).

The same mechanism bites the install script itself, and in the same way: on the
CLI SAPI *any* output makes `headers_sent()` true, so the notice on stdout was
enough to make `session_start()` fail inside `php /app/panelalpha/atheos-install.php`
and kill it with the identical `TypeError`. `panelalpha/atheos-install.php`
therefore sets `display_errors=stderr` as its first statement, before anything
is included.

The second error source is real but secondary: the vendored
`matthiasmullie/minify` is not PHP 8.2-clean and emits
`Use of "parent" in callables is deprecated` twenty times from
`vendor/minify/JS.php:127` — inside the web request that first builds
`public/*.min.js`, because `SourceManager` minifies lazily. With
`display_errors` off it goes to the container log where it belongs.

## Where the data lives

`WORKSPACE` and `DATA` both default to `BASE_PATH . "/..."`, inside the
checkout, and a redeploy re-clones `~/project` after clearing it
(**engine#173**). For a CMS that loses a cache. For an IDE it deletes the
customer's source code and the account's own login. So:

```
~/.panelalpha/atheos/          bind-mounted at /data by the compose override
  ├── workspace/               WORKSPACE — every file written in the editor
  ├── data/                    DATA — users.json.php (password hashes), the
  │                            project list, per-user settings, drafts, the
  │                            access and project logs
  └── admin-credentials        the generated administrator, 0600
```

`~` is root-owned 0755 and nothing can be created in it; `~/.panelalpha` is
created with the account and belongs to it, which is why the data directory is
a child of that one, and the prepare hook creates it before the mount is made
so Docker never gets it as root.

`config.php` is *not* persisted — it is regenerated on every deploy by
`panelalpha/atheos-install.php`, because everything in it is the deploy's, not
the operator's.

## Security

### Who becomes the first user

**First-visitor-wins, twice over.** `index.php` serves
`components/install/view.php` whenever `DATA/users.json.php` and
`DATA/projects.db.php` are both absent, and `components/install/process.php` is
a POST endpoint that checks no session, creates the first user with
`["configure", "read", "write"]` and `userACL: "full"`, writes `config.php`, and
puts the caller straight into a logged-in session. On an account that has just
been given a public HTTPS name, that window belongs to whoever finds the address
first.

In a web IDE `configure` is not an ordinary admin flag. It means, concretely:

* `Common::checkPath()` returns true for anything under `BASE_PATH` — i.e. the
  application's own directory, which is also its document root;
* the project component has two undocumented magic paths, `@TH305` (open
  `BASE_PATH` as a project) and `W3BR00T` (open `WEBROOT`);
* `Macro::execute()` runs its stored command string through
  `Common::raw_execute()`, which is a bare `system($cmd)` — no escaping, no
  allowlist (`common.php:269`). Saving a macro needs `configure`; *running* one
  needs only a session.

So the installer is not "a setup page a stranger could fill in". It is remote
code execution on the container, unauthenticated, for as long as it stays open.

`panelalpha/atheos-install.php` closes it on the **install** stage, before
Apache binds, by calling upstream's own `process.php` with the same fields the
wizard posts — nothing is reimplemented. The password is generated **once per
account** into `~/.panelalpha/atheos/admin-credentials` (0600), outside the
checkout and outside the document root; once, not per deploy, because the user
file survives the redeploy in `/data` and a regenerated password would stop
matching it.

Three locks, not one, because they fail differently:

1. the install script runs the installer itself, so there is nothing left to do;
2. `data/users.json.php` is shipped as a stub (`{}` in Atheos's wrapped-JSON
   form). `process.php`'s own guard reads `BASE_PATH . "/data/..."` — hard-coded,
   *not* the `DATA` constant — so a file at that path closes the endpoint from
   inside PHP, on any webserver, even if the install command never ran
   (**engine#169** drops stage commands silently and intermittently);
3. `.htaccess` denies it at the server.

If all of that somehow failed, the failure is closed rather than open: with the
stub present and `config.php` missing, `index.php` finds a user file, shows the
login form rather than the installer, and every login fails against an empty
user table.

### What a signed-in user can reach

Plainly: **everything in the container, and nothing outside it.**

An authenticated administrator can read and write every file under `/app`
(the account's `~/project`), can create a project at any absolute path the
container's uid can write, and can run arbitrary shell commands through the
Macro component. That is the product — an IDE that could not do it would not be
an IDE — and it is equivalent to giving the account holder a shell on their own
account, which PanelAlpha does anyway over SFTP.

What matters is the boundary, and the boundary holds: the `php` strategy mounts
`~/project` at `/app` and this recipe mounts `~/.panelalpha/atheos` at `/data`,
and that is the whole of the filesystem an account's Atheos can see. There is no
path from a signed-in user of one account to another account's files, to the
host, or to the engine. `Common::cleanPath()` is weak — it strips `../`
repeatedly rather than resolving, and `getWorkspacePath()` returns an absolute
path verbatim — but there is nothing outside the mounts to traverse *to*.

The corollary is a product limitation, not a vulnerability: because only
`~/project` is mounted, **Atheos cannot be used to edit the account's other
websites.** It is a self-contained scratch IDE.

`WEBROOT` defaults to `/var/www/html/` — the stock Debian directory in the base
image, nothing to do with the account. `checkPath()` grants it to `configure`
users and `W3BR00T` opens it as a project. The generated `config.php` points it
at the workspace so the shortcut is a no-op rather than a tour of the image.

### What is reachable without a session

Atheos is served from its own repository root, so every trait, class, template
and component controller is a URL. Its shipped `.htaccess` tries to deal with
that and three of its rules do not do what they read as, because `RedirectMatch`
takes a regex and `/data/*` means "`/dat` followed by any number of `a`s":

```
RedirectMatch 403 ^/components/*.php$    matches /component.php
RedirectMatch 403 ^/data/*$              matches /data, never /data/users.json.php
RedirectMatch 403 ^/workspace/?$         matches the bare directory only
```

Only five `.php` files in the tree are entry points: `index.php`,
`controller.php`, `dialog.php` and `error.php` at the root, plus
`components/transfer/download.php`, which `transfer/init.js` fetches directly
and which checks the session itself. `hooks/prepare.sh` writes a per-directory
`.htaccess` denying the rest — `.php` only under `components/` and `plugins/`,
which also hold the editor's JavaScript and CSS, and everything under `traits/`,
`classes/`, `templates/`, `vendor/`, `data/` and `panelalpha/`.
`<DirectoryMatch>` is not valid in `.htaccess`, which is why it is one file per
directory rather than one block.

The data files are a second layer of the same idea and they are upstream's:
every file `Common::save()` writes is wrapped as `<?php/*| … |*/?>`, so fetching
one over HTTP executes it and prints nothing.

### The workspace is not under the document root

Upstream serves it, and `FileTree::loadURL()` builds a preview URL of the form
`<host>/workspace/<path>` on that assumption. Keeping it there would mean every
file the customer writes is anonymously fetchable — source, `.sql` dumps,
`.ini` files, `.bak` — and any `.php` among them executes on request. Moving
`WORKSPACE` to `/data/workspace` was required anyway to survive a redeploy, and
it takes the exposure with it. **Preview returns 404.** An operator who wants it
back can mount `~/.panelalpha/atheos/workspace` at `/app/workspace` and drop the
`WORKSPACE` define; the persistence survives either way, and so does the
exposure.

### Session handling

The generated `config.php` sets `session.cookie_httponly`,
`session.cookie_samesite=Lax` and `session.use_strict_mode` before
`Common::startSession()` runs — PHP's own defaults are all off, and this cookie
is the entirety of the application's authentication. `cookie_secure` is set when
the request arrived over HTTPS, which the base image's `auto_prepend_file` shim
has already established.

`use_strict_mode` earns its line for a specific reason. The session is named
`md5(BASE_PATH)`, and `BASE_PATH` is `/app` on every account on the engine
(**engine#175**), so every Atheos on the platform names its cookie the same
thing. Host-only cookies keep that harmless between accounts by themselves, but
under a shared parent domain — `*.panelalpha.online` on the test edge — a
neighbour can set a `Domain=`-scoped cookie of that name. `use_strict_mode`
makes PHP reject a session id it did not issue, which is what turns a fixation
into a no-op.

`HEADERS` keeps upstream's set minus `Access-Control-Allow-Origin: *`. A
wildcard ACAO cannot carry credentials, so it never exposed a signed-in session,
but there is no reason for an IDE to offer its responses to every origin.

### Things left as upstream has them, and worth knowing

* **Login is not rate-limited**, and `User::authenticate()` answers 404
  "Username not found" for an unknown user and 451 "Invalid password" for a
  known one — username enumeration, and nothing slows a guesser down. The
  generated password is 24 characters of `[A-Za-z0-9]`, which is what carries
  the weight.
* **`Common::checkSession()`'s binding is inert.** It writes
  `SERVER("HTTP_USER_AGENT") || md5(...)` — `||`, not `?:` — so the value stored
  and compared is the boolean `true` for every client. Only the /16 of the
  client address is actually checked.
* **The Market component installs code from the internet**: it fetches
  `<repo>/archive/master.zip` and extracts it into `BASE_PATH . "/" . $type`,
  where `$type` comes from the request. Administrator-only, and no worse than
  the editor it sits next to, but it is a supply-chain surface.
* **Telemetry is off.** The installer is given `analytics=false`, which is
  neither `true` (send) nor `"UNKNOWN"` (nag the administrator), so
  `Analytics::init()` answers 403 and nothing is posted to `atheos.io`. Whoever
  signs in can turn it on under Settings.

## No dependency pass, and no readiness gate

There is no `composer.json` anywhere in the tree. That is why this recipe
extends **`php-plain`** and not `php`: the `php` platform's `composer-install`
command carries no `when` guard, so an `extends: php` recipe fails every deploy
on `Composer could not find a composer.json file in /app` before the image is
built — measured, 45.2 s to a rollback. `php-plain` is the same strategy, the
same image and the same document root with no dependency pass. **engine#168**
never comes up either.

Nothing is worth a readiness gate. Measured on a first install, from the
container's own clock:

```
13:05:01.397  [panelalpha] upgrade: atheos-install
13:05:01.513  [panelalpha] start: serve
13:05:01.601  Apache ... resuming normal operations
```

The whole install — writing `config.php`, running upstream's installer over a
handful of small JSON files, creating the project — takes **115 ms**, and
Apache binds **204 ms** after the container starts. `AppLauncher` runs
`docker compose up -d` without `--wait` (**engine#90**) and a `ready` container
on a 3 GB host would be a cost with nothing to buy. The health probe answered
on the first attempt every time.

## engine#169 blocks this recipe on the queue path

The recipe is correct and the deploy pipeline does not run it. Measured twice,
reproducibly, on `mariusz2`:

```
Recipe named by github.com/atheos/atheos
Detected project type: Atheos
...
Deploy finished successfully                          <- 45.2 s, no install step
panelalpha: no /app/panelalpha-entrypoint.sh on the mount; serving directly
```

The same recipe, the same account, the same code, run from a fresh PHP process
(`pae project:rebuild`) instead of a queue worker:

```
[panelalpha] upgrade: atheos-install
[panelalpha] start: serve
```

That is **engine#169**, and the mechanism is exactly this:

* `SourceRecipes::$allCache` (`app/Lib/Deploy/Platform/SourceRecipes.php:30`,
  filled at line 260) memoises every recipe directory found on disk, in a
  process static. A queue worker is a long-lived process — the three on this
  host had been up since 11:57:48 and this recipe's directory was created at
  12:44 — so a worker that walked the tree before a recipe existed keeps
  answering from the list it took then.
* Detection is unaffected, because `SourceRecipes::for()` (line 46) reads **one
  directory by slug**. Hence "Recipe named by github.com/atheos/atheos" in a
  deploy that then ignores everything the recipe says.
* `PlatformRegistry::find($id)` (`PlatformRegistry.php:176-185`) falls through
  to `SourceRecipes::findById($id)`, which walks the stale `$allCache` and
  returns **null**.
* `EntrypointWriter::write()`
  (`app/System/Project/Dind/Strategy/EntrypointWriter.php:144`) returns false on
  a null manifest, so `panelalpha-entrypoint.sh` is never written, the base
  image shim falls through to "serving directly", and every install, upgrade
  and start command is dropped.

For Atheos the consequence is not a missing migration. Without the install
stage there is no `config.php`, and without `config.php` the fatal at the top of
this file is what the account serves — the app is not merely unconfigured, it
is down.

## What was verified

On `mariusz2`, `--memory-limit=1200`, commit `f90cd18`, after
`pae project:rebuild` supplied the fresh process **engine#169** denies the queue:

* `healthy: true`, `serving: ok`, **12 of 12** health checks pass,
  `https://<domain>/` → **200**, zero characters of PHP diagnostics in the body.
* **Login over the public HTTPS domain** with the generated credential:
  `POST /controller.php action=authenticate` → `Successfully authenticated`, and
  the logged-in page renders the IDE — `id="WORKSPACE"`, `id="EDITOR"`,
  `id="FILETABS"`, `components/editor/ace-editor/ace.js` and the built
  `public/components.min.js`.
* **The round trip.** Create a project (`/data/workspace/verify-project`), open
  it, create a file in it, write `Atheos round trip 12345 zażółć` to it, read it
  back: content identical, `loadHash` identical, and the file is on the account
  at `~/.panelalpha/atheos/workspace/verify-project/hello.txt` with the right
  bytes.
* **The installer is shut.** `POST /components/install/process.php` with a
  would-be administrator → **403**.
* **A redeploy keeps the data.** A marker file written into the checkout is gone
  afterwards, `config.php` is regenerated, and the workspace file, the user
  table, the project list, the macros, the logs and the generated password are
  all byte-identical. Logging in again with the same password and reading the
  same file back both work.
* **The exposure sweep**, unauthenticated, over the public domain:

  ```
  200  /                          200  /controller.php (JSON 415)
  200  /error.php                 200  /components/transfer/download.php (JSON 401)
  200  /components/editor/init.js 200  /theme/main.min.css  200  /public/modules.min.js
  403  /config.php     403  /common.php        403  /.htaccess     403  /.env
  403  /.git/config    403  /docker-compose.yml 403  /panelalpha.yaml
  403  /data/users.json.php   403  /data/projects.db.php
  403  /traits/checks.php     403  /classes/sourcemanager.php  403  /templates/favicons.php
  403  /vendor/minify/JS.php  403  /components/install/process.php
  403  /components/user/class.user.php  403  /components/macro/class.macro.php
  403  /panelalpha/atheos-install.php   403  /workspace/
  403  /docs/LICENSE.md  403  /languages/en.json
  404  /workspace/verify-project/hello.txt          (not under the document root)
  ```

* **Response headers**: `X-Frame-Options: SAMEORIGIN`,
  `X-Content-Type-Options: nosniff`, `Referrer-Policy: no-referrer`,
  `Feature-Policy: sync-xhr 'self'`, and **no**
  `Access-Control-Allow-Origin`. The session cookie comes back
  `path=/; secure; HttpOnly; SameSite=Lax`.
* **engine#175, measured.** The cookie is named
  `8c069df52082beee3c95ca17836fb8e2`, which is `md5("/app")` — the same name on
  every Atheos account on the engine, because `session_name(md5(BASE_PATH))` and
  `BASE_PATH` is the uniform mount point. `session.use_strict_mode` is what
  makes that harmless.
* **The reach of a signed-in administrator**, measured with the generated
  credential:

  ```
  403  read /etc/passwd          403  read /home     403  read /proc/self/environ
  200  read /app/config.php      200  open @TH305 -> the application itself as a project
  200  write /app/pwn-test.php, then GET /pwn-test.php -> 200 "RCE:42"
  200  macro execute            -> `id` ran: uid=1001 gid=1001
  506  create a project at /     506  create a project at /etc
  200  create a project at /data -> and /data/admin-credentials is then readable
  ```

  The first three lines are the boundary and it holds. The rest is the product:
  this is an IDE, it edits its own document root, and the file it writes there
  executes. `Macro::execute()` answers 501 on the way out — upstream reads
  `$result["text"]` from a `Common::raw_execute()` that returns `code` and
  `output` — but the command has already run by then.

  The one item that is neither boundary nor product is `/data`: it is writable
  by the account's uid, so an administrator can register it as a project and
  read `admin-credentials` out of it. That is their own password and no
  escalation, but it is why the file is the *only* thing in `/data` that is not
  application state, and why nothing else per-account is kept there.
