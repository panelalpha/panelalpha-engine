# OpenEMR — github.com/openemr/openemr

OpenEMR is an electronic health record and practice-management system: about
9,000 files of PHP served straight out of the checkout, MySQL or MariaDB
underneath, and an installer that builds a 700-table schema and a language
table with a quarter of a million rows in it. Tracker issue
[#633](https://git.modulesgarden.tech/panelalpha/playground/supported-apps/-/work_items/633).

Detection reads the repository correctly on its own — composer.json and no
artisan, so the `php` strategy, PHP 8.3, the shared Apache base image with
`~/project` bind-mounted, `composer install` on the host. `composer install`
resolved 187 packages and the webpack theme build ran; the deploy finished
successfully without this recipe and every request answered **HTTP 500**, which
is the `serving-error_page` verdict it exists to fix.

## The 500, exactly

Reproduced on the deployed account:

```
PHP Fatal error:  Uncaught UnexpectedValueException: Could not detect
environment name. Searched envvars: ENVIRONMENT, ENV
  in /app/vendor/firehed/container/src/AutoDetect.php:51
#0 AutoDetect::getBuilder()  #1 AutoDetect::from('config')
#2 /app/bootstrap.php(48): Firehed\Container\AutoDetect::instance('config')
#3 /app/public/index.php(20): require_once('/app/bootstrap....')
```

Two facts compose into it. `PhpDocroot` pointed Apache at `/app/public`, so
**every** request in the account was served by `public/index.php` — OpenEMR's
opt-in, experimental front controller. That file's first act is to require
`bootstrap.php`, which calls `Firehed\Container\AutoDetect::instance('config')`,
and firehed/container refuses to build a container without `ENVIRONMENT` or
`ENV` in the environment. Neither is in `.env.example`, nothing in the checkout
sets them, and the exception is uncaught. Every path, HTTP 500.

`bootstrap.php` also sets `display_errors=0`, so the body was **empty** — which
is why the original health report's entire `php` group passed on a site that was
completely dead: `no-fatal-error`, `no-database-error` and
`no-diagnostics-in-output` all match against the response body, and there was
nothing in it to match. The missing `sites/default/sqlconf.php` configuration
was never even reached.

Fixing the document root fixes the 500. `files/.htaccess` then 404s
`/public/index.php` as well, because with `/app` served it is no longer the
entry point but is still a permanent 500 on a URL anyone can reach.

## What the engine could not infer

**The document root is the repository root, and no manifest key can say so.**
`PhpDocroot::detect()` tries `public`, `web`, `public_html`, `webroot` and only
then the root — and OpenEMR master ships a `public/index.php`, so the probe
stops at the first candidate and Apache is pointed at `/app/public`. That
directory is not a document root. It holds `assets/`, `images/`, `certs/`,
`smart-styles/` and an *experimental* front controller, and `.htaccess.example`
says so itself: "EXAMPLE ONLY — if you move this file from `.htaccess.example`
to `.htaccess` AND delete the other `.htaccess` files throughout, you'll use
the front controller. This is still an experimental/opt-in process." Nothing in
a clone opts in. With `/app/public` served, `/interface/login/login.php`,
`/library/js/...` and `/apis/...` all resolve under `public/`, where they do
not exist, and `interface/globals.php` computes `$web_root` by subtracting
`DOCUMENT_ROOT` from the application directory (line 197) — which with the two
one directory apart prefixes every generated URL with `/public`.

**`docroot: .` does not fix this**, and the recipe was written with it before
the first test deploy proved otherwise. `PlatformManifest::readDocroot()`
normalises both `''` and `'.'` to `''`, and `PhpDocroot::environment()` reads
`''` as "not declared" and runs `detect()`. Measured: with `docroot: .` in
`panelalpha.yaml`, the generated compose file still said `PA_DOCROOT:
/app/public`. Leaving the key out is no better — the base image's
`panelalpha-serve.sh` falls back to `/app/public` whenever that directory
exists, so both paths converge on the same wrong answer.

The only lever a recipe has is the environment variable itself, so
`overrides/docker-compose.override.yml` sets `PA_DOCROOT: /app` on the `app`
service. Compose merges `environment` maps with the override winning, and
`panelalpha-serve.sh` takes a non-empty `PA_DOCROOT` as given, so it reaches
both Apache's `DocumentRoot` and the vhost's `<Directory>` block. That matches
upstream's own Apache config: `docker/release/openemr.conf` sets `DocumentRoot
/var/www/localhost/htdocs/openemr`.

**This is an engine gap, not just an OpenEMR quirk.** No `docroot:` value can
currently mean "the repository root, and do not probe" — which is precisely the
case a recipe exists to settle, and the case in which the probe is most likely
to be wrong. Either `readDocroot()` should keep `'.'` distinct from absent, or
`PhpDocroot::detect()` should decline a candidate directory that holds no
`.htaccess` and no front-controller-shaped index. Until then, every PHP
repository with a `public/index.php` it does not serve from needs this
three-line override.

**No database, and no way to ask for one.** OpenEMR is MySQL or nothing:
composer.json requires `ext-mysqli` and `ext-pdo_mysql`, and
`Installer::connect_to_database()` is raw mysqli. The repository ships no
`.env`, no compose file and nothing naming a server. It *does* ship
`sites/default/sqlconf.php`, committed, pointing at
`openemr:openemr@localhost` with `$config = 0` — the flag meaning "not
installed". `database: mysql` in `panelalpha.yaml` is the manifest key that
answers this (Matomo's and Omeka S's, for the same reason): `AppDatabase`
provisions a database and user on the account's own MySQL server — visible in
the panel, openable in phpMyAdmin, inside the account's backup, and costing a
3.7 GB host neither a container nor a volume — and the generated compose file
passes `DB_HOST`/`DB_PORT`/`DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD` into the
container.

**The first administrator.** OpenEMR seeds no user. Until the installer has
run, `index.php` reads `sqlconf.php`, sees `$config = 0` and redirects every
visitor to `setup.php` — an unauthenticated web wizard on which the first
stranger to arrive becomes the superuser of an electronic health record. So the
install runs from the install stage instead, in the container, before Apache
accepts a request. `files/panelalpha-install.php` calls
`Installer::quick_install()` — the same method
`contrib/util/installScripts/InstallerAuto.php` and upstream's
`docker/release/auto_configure.php` both call, with the same settings array —
and passes `no_root_db_access=1`, which is what makes it fit here: the account
has no MySQL root, and that flag makes `quick_install()` skip the root
connection, `CREATE DATABASE`, `CREATE USER` and `GRANT` and go straight to the
user connection it was handed. The password is generated per account into
`~/project/.panelalpha-admin-password` (0600) by `hooks/prepare.sh`. Upstream's
own automation ships `iuserpass = 'pass'`, which is the default this exists to
avoid.

**A redeploy re-clones `~/project`, and `sqlconf.php` is a committed file.**
So on every deploy after the first, the file on disk says
`openemr:openemr@localhost`, `$config = 0` while the database still holds the
practice's records — and `index.php` would send the operator back to the web
installer over a live EHR. `panelalpha-install.php` therefore asks the
*database* what state it is in, not the disk: a `users_secure` table means
installed, and it then only rewrites `sqlconf.php` (through the Installer's own
`write_configuration_file()`, so the file is byte-for-byte what OpenEMR
expects) and touches nothing else. The command runs on the `install` and
`upgrade` stages both, which is every deploy.

**Two unauthenticated maintenance scripts sit in the document root.**
`sql_upgrade.php` and `acl_upgrade.php` each open with `$ignoreAuth = true; //
no login required` before including `interface/globals.php`: on a health-records
site, anyone who can reach the domain can run schema migrations.
`files/.htaccess` returns 404 for those two, for `ippf_upgrade.php` and for
`setup.php`. `admin.php` needs no rule — it 403s itself unless
`OPENEMR_ADMIN_PHP_ENABLED=1` — and `bin/` already ships its own deny.
`RedirectMatch` rather than `<Files>`, because the pattern has to be anchored to
the URL path: a `<Files "setup.php">` in the root `.htaccess` would also deny
`interface/modules/custom_modules/oe-module-faxsms/setup.php`, a module's own
page.

**`sites/` is inside the document root.** It holds `sqlconf.php` (the account's
MySQL password), `config.php`, and `documents/` — where OpenEMR files every
uploaded patient document, generated PDF, EDI batch and remittance. The `.php`
files execute rather than disclose themselves, and `documents/` is empty on a
fresh deploy, which is exactly why this is easy to miss: nothing stops `GET
/sites/default/documents/<pid>/<file>` once the practice is in use.
`files/sites/.htaccess` denies the tree; `files/sites/default/images/.htaccess`
re-opens the one part of it the application links to over HTTP —
`interface/globals.php` builds the login and practice logos as
`OE_SITE_WEBROOT . "/images/..."`, and `library/options.inc.php` does the same
for image-typed layout fields — and takes the PHP interpreter off that
directory, because the practice logo is an upload. Upstream makes the same two
cuts from the server config (`<Directory .../sites>` plus a denied
`<Directory .../sites/*/documents>`); a bind-mounted checkout cannot edit the
vhost, so they are made in `.htaccess` and made wider.

**One dependency lands in the wrong directory.**
`claimrevolution/oe-module-claimrev-connect` is `"type": "openemr-module"`, and
what knows where those go is `openemr/oe-module-installer-plugin`, a
composer-plugin whose `CustomModuleInstaller::getInstallPath()` returns
`interface/modules/custom_modules/<name>`. The php manifest installs with
`--no-plugins` (a plugin is arbitrary PHP out of a customer repository and the
install runs on the host daemon), and `PhpHostBuild::mayRunPlugins()` lifts that
only for the five installer plugins it names, so Composer used its default
`LibraryInstaller` and the module is in `vendor/`, where OpenEMR's module
scanner never looks. `panelalpha-install.php` copies it across. Cosmetic — it is
optional billing integration, not a boot requirement — but a module in
`vendor/` is a module the practice cannot see.

**Nothing may reach standard output before `quick_install()` runs.**
`Installer::install_gacl()` constructs `OpenEMR\Gacl\Gacl`, whose constructor
calls `DatabaseConnectionFactory::detectConnectionPersistenceFromGlobalState()`,
which asks for the active session. Symfony's `NativeSessionStorage::start()` —
reached through OpenEMR's `ReadAndCloseNativeSessionStorage` — throws `Failed to
start the session because headers have already been sent` whenever
`headers_sent()` is true, and under the CLI SAPI `headers_sent()` becomes true
on the first byte PHP writes to stdout.

Measured, on the first test deploy of this recipe: a single `echo "[openemr]
installing into …"` before the call killed the install with an uncaught
`RuntimeException` out of `Gacl->__construct()` — *after* the 700-table schema,
the language pack, the globals and the version row had all been written. The
account was left with a half-built database, no ACLs and no administrator, and
the deploy rolled back. Every message in `panelalpha-install.php` therefore goes
through `fwrite(STDERR, …)`, which bypasses PHP's output layer; `error_log()` is
safe for the same reason, which is why the Installer's own Monolog handler can
log throughout. Upstream's two CLI entry points print nothing before the call,
which is the same rule arrived at by having nothing to say.

## The install looks like it should be slow, and is not

`Installer::load_file()` reads each dump file a line at a time and issues **one
`mysqli_query` per statement**. `sql/database.sql` is 6,408 statements. The
language pack, `contrib/util/language_translations/currentLanguage_utf8.sql`, is
23.6 MB and **237,511 single-row INSERTs**, and `initialize_dumpfile_list()`
loads it unconditionally — there is no setting that skips it, and deleting the
file fails the install outright (`load_file()` returns false on a dumpfile it
cannot open).

Measured on `mariusz2` (2 cores, 3.7 GB, `--memory-limit=1800`, with another
agent's webpack build running): **all of that is about 20 seconds**, and
`quick_install()` returns in about 30. `database-users.shared-hosting.palocal`
is one bridge away rather than a real network hop, and the whole load runs
inside a single transaction with autocommit off. The intuition that a
quarter-million round trips must cost minutes is simply wrong here, and it is
worth writing down so the next reader does not size a timeout around it.

`start_period` is 600s all the same — about twenty times the measured figure. A
check that passes inside the start period marks the container healthy
immediately, so the margin is free, and this host is usually building something
else at the same time. `AppLauncher::COMPOSE_TIMEOUT_SECONDS` is 3600, which is
the budget that actually applies to `docker compose up -d`; no `timeout:` is set
on the staged command, so nothing shorter cuts it off.

## Files

| File | Why |
|---|---|
| `panelalpha.yaml` | `extends: php`, `database: mysql`, `OPENEMR__ENVIRONMENT=prod`, and the `openemr-setup` command on the install and upgrade stages. No `docroot:` — see above |
| `hooks/prepare.sh` | Generates `.panelalpha-admin-password` (0600); ensures `sites/default/documents`. Deliberately does **not** install `.htaccess.example` |
| `files/.htaccess` | 404s `setup.php`, `sql_upgrade.php`, `acl_upgrade.php`, `ippf_upgrade.php` and `public/index.php` at the root |
| `files/sites/.htaccess` | Denies the site tree: `sqlconf.php`, `config.php`, `documents/` |
| `files/sites/default/images/.htaccess` | Re-opens the logo directory and takes PHP off it |
| `files/panelalpha-setup.sh` | Checks `DB_*` and runs the installer script with a 512M limit |
| `files/panelalpha-install.php` | Installs, or repairs the config of an install that already exists; moves the misplaced module |
| `overrides/docker-compose.override.yml` | `PA_DOCROOT: /app`; healthcheck (`/` then the login form's password input); the no-op `ready` gate |

Every filename in the document root begins `panelalpha-` on purpose: the
generated vhost denies `^(?:docker-compose\.ya?ml|panelalpha[-.])`, so a
`panelalpha/` directory one level down would be served and these are not.

## Operating it

**First login.** `https://<domain>/` redirects to
`interface/login/login.php`. Username `admin`; password in
`~/project/.panelalpha-admin-password`. Change it from
Administration → Users and delete the file.

**Upgrading OpenEMR across versions is not automatic.** A redeploy pulls new
code and rewrites `sqlconf.php`, but nothing runs the schema migrations: OpenEMR
does that from `sql_upgrade.php`, which needs to be told which version it is
coming from, and guessing wrong on an EHR is not a recoverable mistake. Over
SSH, from `~/project`:

```
php sql_upgrade.php --from=<previous version>
php acl_upgrade.php
```

`files/.htaccess` blocks the web versions of both. To use the browser instead,
comment the `RedirectMatch` line out, do the upgrade, and put it back.

**Where the logs are.** `bootstrap.php` sets `error_log` to `/dev/stdout` and
the generated vhost sends Apache's own logs to the container's streams, so
`docker compose logs app` from `~/project` is the whole picture, install
included.

**Not configured here, and worth knowing:** OpenEMR's background tasks run by
being piggybacked onto browser requests, not by cron
(`OPENEMR__NO_BACKGROUND_TASKS` turns that off). Nothing in this recipe sets up
mail, and the practice's own SMTP settings live in Administration → Globals.
`ext-redis` is in composer.json's `require` but OpenEMR only uses Redis when
`REDIS_SERVER` is set; unset, sessions are files and that is correct for one
container.

## Verified on mariusz2

Deployed from this recipe on 2026-09-20, account `openemrtestqpn1`, 2 cores /
3.7 GB, `--memory-limit=1800`, with another agent building on the same host.

- **Verdict `deploy-ok`, `serving: ok`, healthy, all 12 health checks pass.**
  Deploy 242–288s wall; `quick_install()` 32s of that.
- `GET /` → 302 → `/interface/login/login.php?site=default` → **200**,
  external HTTP 200, title `OpenEMR Login`.
- **A real login, not a 200.** `POST
  /interface/main/main_screen.php?auth=login&site=default` with `authUser=admin`
  and the generated password → 302 to
  `/interface/main/tabs/main.php?token_main=…`, which renders **68 KB** of the
  authenticated application (Administrator, Calendar, Messages, Patient,
  Logout). Control: the same POST with a wrong password answers 200 — the login
  page again — and never redirects.
- Every asset the login page references resolves: `/library/*.js`,
  `/public/assets/jquery/…`, and the webpack-built `/public/themes/style_light.css`,
  all 200. `$web_root` is empty, so no URL carries a `/public` prefix.
- **The redeploy path was exercised directly.** `sites/default/sqlconf.php` was
  reset to its committed placeholder (what a re-clone does), the upgrade-stage
  command re-run: it reported `already installed; rewrote
  sites/default/sqlconf.php`, restored `$config = 1` and the real credentials,
  reinstalled nothing (1 row in `users_secure`, 237,509 in `lang_definitions`,
  unchanged), and login still worked.

Exposure probes against the public domain:

| Path | Result |
|---|---|
| `sites/`, `sites/default/sqlconf.php`, `sites/default/config.php`, `sites/default/documents/` | **403** |
| `sites/default/images/login_logo.gif`, `…/logo_1.png` | 200 — intended |
| `setup.php`, `sql_upgrade.php`, `acl_upgrade.php`, `ippf_upgrade.php`, `public/index.php` | **404** |
| `admin.php` | **403** (OpenEMR's own guard) |
| `.git/config`, `.env`, `.panelalpha-admin-password`, `docker-compose.yml`, `panelalpha-install.php`, `panelalpha-setup.sh`, `bin/` | **403** |
| `composer.json`, `composer.lock`, `contrib/util/installScripts/InstallerAuto.php` | 200 — public repository content; `InstallerAuto.php` answers only its 78-byte "set `OPENEMR_ENABLE_INSTALLER_AUTO=1`" refusal |

The one rule added after that run — the `RedirectMatch` for
`/public/index.php` — was applied to the live account and re-probed rather than
proved by a fresh deploy: `/public/index.php` 404, `/public/assets/…` and
`/public/themes/…` still 200, `/` still 302, login still 200.
