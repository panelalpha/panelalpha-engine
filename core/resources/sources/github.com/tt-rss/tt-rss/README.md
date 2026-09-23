# Tiny Tiny RSS

Upstream: <https://github.com/tt-rss/tt-rss> · tracker: panelalpha/playground/supported-apps#940

A self-hosted feed reader. Flat PHP served from the repository root, a Dojo
front end, PostgreSQL underneath, and an update daemon that does all the
fetching.

## What the engine got right on its own

Detection is correct and this recipe does not change it: `composer.json` with
no `artisan` puts the checkout on the `php` strategy, which serves it from the
shared `php:*-apache` base image with `~/project` bind-mounted at `/app` and
runs `composer install` on the host. `vendor/` is committed and
`composer.lock` is present, so the Composer pass is a no-op and engine #168
(`--no-plugins` on a lockless project) does not apply. Every extension tt-rss
checks for in `Config::sanity_check()` — `pdo_pgsql`, `intl`, `mbstring`,
`fileinfo`, `dom`, `curl`, `hash`, `flock` — is in `PhpBaseImage::EXTENSIONS`.

`PhpDocroot` also reaches the right answer by itself: it probes `public`,
`web`, `public_html` and `webroot`, tt-rss has none of them, and it falls back
to the repository root, which is where `index.php` is.

## What it could not infer, and why

**That a database was wanted at all.** `classes/Config.php`'s defaults are
`DB_HOST=db`, `DB_USER=""`, `DB_NAME=""`, `DB_PASS=""`, and `index.php`,
`public.php`, `backend.php` and `prefs.php` all reach `Db::pdo()` before they
render anything. There is nothing in a clone to read this off:

* `config.php` is in `.gitignore`; the repository ships `config.php-dist`,
  which is nothing but a comment block.
* `.env` is in `.gitignore`; the repository ships `.env-dist`.
* `docker-compose.yml` exists but its own header says "simplified compose FOR
  LOCAL DEVELOPMENT … please don't use this in production", and the `php`
  strategy does not run it.

So the deploy succeeded, Apache served, and every page was a database error —
`serving-database_error`.

**That the feed updater needs a different PHP path.** This one only shows up
after the site is already healthy, which is what makes it worth writing down.
`update.php --daemon` does not fetch feeds in-process: it forks one child per
feed through `Config::PHP_EXECUTABLE`, whose default is `/usr/bin/php`. The
shared base image is built `FROM php:{version}-apache-bookworm`
(`config/core/images.yaml`), where the binary is `/usr/local/bin/php` and
`/usr/bin/php` does not exist. Measured on a deploy without the fix:

    ttrss_feeds.last_error = Update process failed with exit code: 127
    ttrss_entries          = 0 rows

with the web interface answering 200 throughout, `serving: ok`, and all twelve
health checks passing. `TTRSS_PHP_EXECUTABLE` in the compose override is what
makes a subscription actually produce articles.

## The database decision

**A postgres sidecar, not an account-provisioned database.** This is a real
downgrade and it is not avoidable.

`database: mysql` in a manifest is what provisions a database on the account's
own MySQL server: it appears in the panel, opens in phpMyAdmin, is inside the
account's backup, and costs no container. The engine has no other value —
`PlatformManifest::DATABASES` is `['mysql']` and `optionalEnum` rejects
anything else.

tt-rss has no MySQL to point it at. `sql/` contains `pgsql/` and nothing else
(one `schema.sql`, ~150 numbered migrations); `classes/Config.php` marks
`DB_TYPE` `@deprecated` with *"default (and only) value: `pgsql`"*;
`update.php --gen-search-idx` is documented as generating a PostgreSQL
full-text index. MySQL support was removed upstream.

What the account gets instead: a `postgres:17-alpine` service on its own
compose network, no published port, a named volume, and credentials generated
per account. What it does not get: the database in the panel, in phpMyAdmin, or
in the account's backup. Anyone relying on those has to back up the volume
themselves.

## What this recipe does

| | |
|---|---|
| `hooks/prepare.sh` | moves the repository's compose file out of the checkout (engine #166); generates the database and admin passwords into `~/.panelalpha/`; installs `Require all denied` in `cache/` and `lock/` and a two-directive root `.htaccess`; creates the directories tt-rss writes to |
| `overrides/docker-compose.override.yml` | the `TTRSS_*` environment (this is the configuration file), the postgres sidecar, `PA_DOCROOT=/app`, a healthcheck and the `ready` gate (engine #90) |
| `files/panelalpha-ttrss-setup.sh` | install + upgrade: waits for postgres, `update.php --update-schema=force-yes`, rotates the seeded admin password |
| `files/panelalpha-ttrss-updater.sh` | start: launches `update.php --daemon` behind Apache |

## Security

**The admin password.** `sql/pgsql/schema.sql` seeds `admin` with the SHA1 of
`password`, and tt-rss puts a red banner on every page until it changes. The
install stage replaces it — before Apache binds — with 20 random alphanumerics
generated per account. The value lives in `~/.panelalpha/tt-rss-app.env` (0600)
and is written out for the owner at
`~/.panelalpha/tt-rss-admin-credentials.txt` (0600):

```
cat ~/.panelalpha/tt-rss-admin-credentials.txt
```

The rotation is guarded by `update.php --user-check-password admin:password`,
so once the owner changes it in Preferences a redeploy leaves it alone.

**`SINGLE_USER_MODE` is false**, explicitly. It is already the default; it is
stated in the compose override because turning it on skips authentication
altogether and logs every visitor in as the administrator, which on a public
HTTPS domain is the entire reader, open.

**There is no registration form to close.** `ENABLE_REGISTRATION` does not
exist in this version of tt-rss. `include/login_form.php` still contains
`window.location.href = "register.php"`, but there is no `register.php` in the
tree and no route that serves one, so the link 404s. Accounts are created by
the administrator under Preferences → Users.

**Web exposure.** tt-rss serves from its own repository root, so everything in
the checkout is under the document root. The generated vhost
(`apache-vhost.stub`) already denies, for the whole tree:

* every dotfile and dot-directory except `.well-known` — this covers
  `.git/config`, `.env` and the world-readable `.env.default` engine #173
  writes;
* `docker-compose.yml` / `.yaml`;
* anything starting `panelalpha-` or `panelalpha.` — which is why this
  recipe's two scripts are named that way.

Measured on a deployed account: a plain file dropped at the repository root
answers 200 with its contents, so the denials above are doing real work and not
being masked by something else.

What it does not know about is `cache/`, and that one matters: `cache/images`
holds media tt-rss downloads out of articles, `cache/upload` holds user
attachments, `cache/export` holds generated exports, `cache/feeds` holds raw
feed bodies. Upstream's own nginx marks the whole prefix `internal`
(`.docker/web-nginx/nginx.conf`) and the repository ships no `.htaccess` to
carry that onto Apache, so `hooks/prepare.sh` installs one in `cache/` and in
`lock/`. Nothing in the application links to those paths, so denying them costs
no functionality. Measured with those files in place: `/cache/images/probe.txt`
and `/lock/probe.txt` both 403, `/probe.txt` at the root 200.

The root `.htaccess` denies `update.php` and `update_daemon2.php`. Both refuse
to run under a web SAPI, but only after Apache has echoed the
`#!/usr/bin/env php` line that sits outside their `<?php`, and the refusal text
names the interpreter: `GET /update.php` answered 200 with "PHP_EXECUTABLE is
set to '/usr/local/bin/php'". Small, but it is a 200 that serves no purpose.
Nothing else is in that file — tt-rss needs no rewrite rules, every URL is a
real file, and a root `.htaccess` Apache cannot parse takes the site with it.

What stays readable, deliberately: `composer.json`, `sql/pgsql/schema.sql`,
`vendor/`, `classes/`, `include/`. All of it is public upstream source,
byte-identical to GitHub, and upstream's own nginx serves the same paths.

**No `config.php` is created.** It is optional (`include/functions.php`:
"config.php is optional") and everything it would hold is passed as
environment. A file in the document root carrying the database password is
worth not creating, even though Apache would execute rather than serve it.

**Where the two generated passwords live.** The master copies are in
`~/.panelalpha/` at 0600, outside the checkout, because `~/project` is emptied
and re-cloned on every deploy while the postgres volume is not. From there they
take different routes, and the difference is forced rather than chosen:

* The **admin password** reaches the app container through `env_file:` at
  `../.panelalpha/tt-rss-app.env`, a path that climbs out of the document root.
  Nothing else sets `TTRSS_ADMIN_PASS`, so nothing outranks the file, and the
  value never enters the checkout at all.
* The **database password** has to be a compose variable, so it is also written
  to `~/project/.env`, and `ProjectEnvironment::apply()` copies that to
  `.env.default` at mode 644 (engine #173). Both are dotfiles, which the vhost
  denies — measured 403 for each — but it is the reason the admin password, a
  login credential rather than an internal one, is kept out of `.env`. Why it
  cannot use `env_file:` is the defect below.

## An engine defect this recipe works around

`RuntimeSidecars` mines this recipe's own `overrides/docker-compose.override.yml`
as if it were a stack template the repository ships.
`RuntimeSidecars::exampleComposeFilenames()`
(`core/app/System/Project/Dind/RuntimeSidecars.php:141-152`) globs
`docker-compose.*.yml` in the project directory and skips only
`ComposeFileInspector::COMPOSE_FILE_CANDIDATES`, which does not contain
`docker-compose.override.yml` — the engine's own overlay name
(`Paths::COMPOSE_OVERRIDE_FILENAME`). Every service the override declares is
therefore copied into the generated compose, and two things happen to it:

1. `SidecarCredentials::pinSidecarCredentials()` fills in the engine's
   `init_vars` for the recognised engine, and `resolvedComposeValue()`
   (`core/app/Lib/Deploy/Sidecar/SidecarCredentials.php:361-373`) turns an
   absent or `${VAR}` value into the literal string `app`. So a postgres
   declared with `env_file:` came up as `POSTGRES_PASSWORD=app` — compose gives
   `environment:` precedence over `env_file:` — while the application had the
   generated one.
2. The generated `app` gains `depends_on:` on every kept service. The first
   version of this recipe gave the `ready` gate `image: postgres:17-alpine`
   (it was already being pulled), `ServiceRole::isKnownDatastore()` recognised
   it as a database, and the deploy died on

       Failed to start app: dependency cycle detected: app -> ready -> app

Both are worked around here rather than fixed: the credentials are restated in
the override's own `environment:` (the override is the last `-f`, so it wins
the merge) and the `ready` service uses `alpine:3`, which the miner drops. Any
recipe that puts a real datastore in an override will hit the same thing.

## Known limits

* **The database volume is outside the panel.** No phpMyAdmin, no panel
  backup. See above.
* **Feeds update from a daemon inside the app container.** The php strategy
  generates one service and a recipe cannot name the image compose gave it, so
  `update.php --daemon` runs beside Apache under a restart loop rather than in
  the dedicated `updater` container upstream uses. If Apache is restarted the
  daemon comes back with it; if the daemon alone dies the loop restarts it
  within 30s.
* **`SELF_URL_PATH` is left at its default.** `classes/Config.php` uses it only
  under the CLI SAPI or when `FORCE_SELF_URL_PATH_USAGE` is on, and browser
  requests build their URLs from the request, so the web interface is
  unaffected. It would matter for emailed digests, which need SMTP that this
  recipe does not configure. An owner who wants digests should set
  `TTRSS_SELF_URL_PATH` to their own `https://<domain>` in the panel's
  environment variables.
* **It resolves to PHP 8.2, which is past upstream security support.** That
  is the engine's documented policy working as designed, not a defect here:
  `config/core/images.yaml` walks the minors oldest-first and takes "the lowest
  minor satisfying every composer constraint", and although tt-rss's own
  `composer.json` pins no `php`, `chillerlan/php-qrcode ^6.0.1` in the lock
  requires `^8.2`. Upstream's own container is on PHP 8.5. Nothing in tt-rss
  needs 8.2 specifically, so raising the engine's floor would move this app
  with it; pinning an `image:` in this one recipe would only hide the
  question.
* **The plugin installer is left at its upstream default (on).** It is
  administrator-only and the administrator can already write to the account
  over SFTP, so it grants nothing new. Set `TTRSS_ENABLE_PLUGIN_INSTALLER` to
  `false` to turn it off.

## What was verified

On `mariusz.panelalpha.tools`, `--memory-limit=2000`, a fresh account per run.
`deploy-ok`, `serving: ok` and HTTP 200 were the starting point, not the
finish:

* **Deploy.** `deploy-ok` in 75s once the PHP base image is on the host (165s
  on the run that had to pull `postgres:17-alpine`, 273s on the first, which
  built `panelalpha/php:8.2-apache-bookworm-pa20260910` from source).
  `healthy: true`, `serving: ok`, domain `ok`, 12 of 12 health checks passing,
  `project-ready-1 Exited (0)` — the readiness gate held.
* **Login, over the account's own public HTTPS domain, from off-host.**
  `admin` plus the generated password: 302, then `GET /` returns
  `<title>Tiny Tiny RSS</title>` with no `type="password"` on the page. The
  seeded `admin` / `password` is rejected, and tt-rss logged both the failure
  and its own throttling (`Too many authentication attempts for admin`).
* **The authenticated page really renders**, checked in a browser and not only
  by status code: the Dojo feed tree, the headline list and a full article
  body, tab title `(50) Tiny Tiny RSS`. No "password is at its default value"
  banner. `ttrss_error_log` holds six rows, all informational — the loudest is
  `Upgrading password of user admin to SSHA-512`.
* **Subscribe and update.** `POST backend.php op=Feeds method=add` returned
  `{"result":{"code":1,"feed_id":1}}`, and 90 seconds later — with nothing
  driving it but the daemon — `ttrss_feeds.last_updated = 2026-09-20 12:34:08`,
  `last_error` empty, 50 rows in `ttrss_entries`, and 30 headlines rendered in
  the reader.
* **Exposure**, every path fetched over the public domain: `config.php` 404
  (none is created); `.git/config`, `.git/HEAD`, `.git/index`, `.env`,
  `.env.default`, `docker-compose.yml`, `panelalpha-*.sh`, `update.php`,
  `update_daemon2.php`, `cache/images/`, `cache/images/probe.txt`, `lock/`,
  `lock/probe.txt` all 403; `/index.php` 200. A control file written to the
  repository root answered 200 with its contents, so the 403s are the rules
  and not an accident of the proxy.
* **Credentials on disk.** `~/.panelalpha` is 0700; the three files in it are
  0600 and owned by the account. The app container and the database container
  hold the same 48-character password, and the literal `app` the engine
  substitutes appears nowhere.
