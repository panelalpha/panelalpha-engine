# Phorge on PanelAlpha

Phorge (https://we.phorge.it/source/phorge) is the community fork of
Phabricator: code review, task tracking, a wiki, repository hosting and around
twenty more applications, in about 8,000 files of plain PHP with no Composer
and no build step.

**Read this first: this recipe runs a MySQL container of its own.** Every other
PHP application here uses `database: mysql` and gets a database on the
account's own MySQL server — one the panel lists, phpMyAdmin opens and the
account's backup includes. Phorge cannot use that, for a reason that is in its
source rather than in its configuration, and the consequence is that **the
account's Phorge data lives in a Docker volume the panel cannot see and the
backup does not cover.** If that is not acceptable for a given customer, this
application is not deployable for them at all — not with a different recipe,
and not by hand.

---

## The two things that decide whether this is possible

### 1. It needs 54 MySQL schemas; the engine provisions one

`PhabricatorLiskDAO::establishLiveConnection()` picks the schema per
application:

    $namespace = self::getStorageNamespace();
    $database = $namespace.'_'.$this->getApplicationName();

and `resources/sql/quickstart.sql` opens with 54 `CREATE DATABASE
{$NAMESPACE}_<app>` statements — `phabricator_maniphest`,
`phabricator_differential`, `phabricator_user`, `phabricator_file`, and 50
more. `storage.default-namespace` renames the prefix; it does not fold the
schemas together. There is no single-schema mode and no table-prefix mode.

`database: mysql` gives an application one database and one grant:

    // app/System/Project/Dind/AppDatabase.php, lines 60-62
    self::ensureDatabase($mysql, $user, $name);
    self::ensureUser($mysql, $user, $name, $password);
    $mysql->privileges()->updatePrivileges($name, $name, 'ALL PRIVILEGES');

`ALL PRIVILEGES ON \`alice_app\`.*` cannot `CREATE DATABASE alice_app_maniphest`.
So the account's own MySQL server is not a server Phorge can be installed on —
not by this recipe, not by the account owner over SSH, not in phpMyAdmin. A
sidecar is not a preference here; it is the only shape in which the application
runs.

### 2. It needs `arcanist` as a sibling checkout

libphutil was merged into arcanist in 2019, so arcanist is most of the
framework Phorge is written in, not a developer tool. Both entry points load it
the same way — one directory above the Phorge root:

    // support/startup/PhabricatorStartup.php, lines 195-212  (the web)
    $libraries_root = dirname($phabricator_root);
    ini_set('include_path', $libraries_root.PATH_SEPARATOR.ini_get('include_path'));
    $ok = @include_once $root.'arcanist/src/init/init-library.php';
    if (!$ok) { self::didFatal('... Put "arcanist/" next to "phorge/" on disk.'); }

`scripts/init/lib.php` does the same for every `bin/*` command. A
single-repository deploy has no writable directory above the checkout: `/app`'s
parent inside the container is the image's own `/`.

The answer is two lines. `hooks/prepare.sh` clones arcanist into the checkout,
and the compose override bind-mounts it at `/arcanist` — which *is* one
directory above `/app`. The sibling upstream asks for is then real, for the web
SAPI and the CLI alike, with no environment variable and no `include_path` of
this recipe's own. (`PHUTIL_LIBRARY_ROOT` is the other candidate and it does not
work: both call sites read it from `$_SERVER`, which under mod_php carries
Apache's subprocess environment rather than the container's — it would satisfy
`bin/storage` and leave every web request fataling.)

---

## What the deploy does

| Stage | What happens |
| --- | --- |
| `hooks/prepare.sh` | clones arcanist beside the checkout; generates the database password and the administrator password into `~/.panelalpha/phorge/` (0600 files in a 0700 directory); writes an empty `.env`; creates `conf/local/`, `.phorge-files/`, `.phorge-repos/` |
| engine | detects `php-plain`, `PA_DOCROOT=/app/webroot`, starts `db` and `app` |
| `install` / `upgrade` | `panelalpha/phorge-setup.sh`: replace the database's engine-default credentials, write `conf/local/local.json`, `bin/storage upgrade --force` (54 schemas, ~900 patches), create the first administrator and the auth provider, purge caches |
| healthcheck | `/` redirects **and** `/auth/start/` renders a password field; the `ready` service gates `docker compose up -d` on it |

Measured on `mariusz.panelalpha.tools` (15 GB, shared with other work), with the
shared PHP 8.3 base image and `mysql:8.0` already in the host cache:
**about 135 seconds** from API call to a healthy site, of which the storage
upgrade is roughly 35.

## Logging in

    ssh <account>@<host>            # or the panel's file manager
    cat ~/.panelalpha/phorge/admin-password

The user is `admin`. The password is generated per account and is never a
default. It is created before the site is reachable, for the reason in the next
section.

## Security

**The first registered user becomes an administrator.**
`PhabricatorAuthController::isFirstTimeSetup()` (line 27) is true whenever an
install has no enabled auth providers and no accounts, and in that state
`/auth/start/` is an open registration form whose handler applies
`setIsApproved(1)` and an empower transaction unconditionally
(`PhabricatorAuthRegisterController`, lines 420-470). No token, no invite, no
rate limit — the first stranger to load the page owns the install, from the
moment Apache binds. Upstream's answer is a human at a terminal within seconds
of first boot; there is no unattended equivalent in the tree (`bin/user` has
approve, empower and enable but no create, and `bin/auth` has no provider
commands at all).

`files/panelalpha/phorge-bootstrap.php` is that equivalent. It runs on the
install stage, before the container is healthy, and it both creates the
administrator and configures the username/password provider **with registration
switched off** — either alone ends first-time setup, and the second is what
stops `/auth/register/` being a signup form afterwards. Verified on a live
deploy: logged out, `/auth/start/` offers a username and password and no
"Register New Account" button, and `/auth/register/` answers "There are no
configured default registration providers."

**Exposure, measured over the public HTTPS domain.** The document root is
`webroot/`, so nothing else in the checkout is a candidate for being served at
all; everything unmatched goes through `index.php` and comes back as Phorge's
login page.

| Path | Result |
| --- | --- |
| `/.git/config` | 403 (generated vhost `<FilesMatch "^\.">`) |
| `/docker-compose.yml`, `/.env`, `/.env.default` | 403 (generated vhost) |
| `/conf/local/local.json` (holds the MySQL password) | login page — outside the document root |
| `/panelalpha/php/zz-phorge.ini`, `/arcanist/` | login page — outside the document root |
| `/config/`, `/storage/`, `/people/` | login page (Phorge's own applications, authenticated) |
| `/auth/register/` | "There are no configured default registration providers." |

**What this recipe cannot fix: the sidecar's credentials, briefly.** The engine
harvests this recipe's own `overrides/docker-compose.override.yml` for backing
services and replaces the `environment` of anything it recognises as a datastore
with credentials of its own. For an application that is not Laravel those
credentials are all defaults, and the generated `docker-compose.yml` comes out
with `MYSQL_USER: app`, `MYSQL_PASSWORD: app`, `MYSQL_ROOT_PASSWORD: app` and
`MYSQL_ROOT_HOST: '%'`. Compose gives `environment:` precedence over
`env_file:`, and the only other channel — `.env` — is copied to a
world-readable `.env.default`. So `panelalpha/phorge-db-secure.php` fixes it
from the inside instead: on every deploy it connects as root with whichever of
the two passwords works, sets root's password to the one generated for this
account, and drops the `app`/`app` user. Between `docker compose up` and that
script running — a few seconds, once, on the first deploy — the database is
reachable on the account's private compose network with a published password.

## What is not running: the daemons

`bin/phd start` wants a supervised process per worker and this is a
one-container application, so there is no `PhabricatorTaskmasterDaemon`. The
consequences are bounded and worth knowing before you promise a customer
anything:

* no full-text search indexing of new objects (search finds what was indexed
  synchronously, not what the daemons would have indexed);
* no outbound mail — the install stage therefore points `cluster.mailers` at the
  `test` adapter, which discards messages, rather than leaving password-reset
  mail to pile up in a queue nothing drains. **Password reset by email does not
  work**; the administrator resets passwords from `/people/`;
* no repository fetching, so hosted and observed repositories do not update;
* a "daemons are not running" setup warning for administrators.

Tasks, code review, Phriction, Diffusion browsing of an already-cloned
repository and every synchronous action work.
`PhabricatorDaemonsSetupCheck` is a warning and never fatal, so none of this
blocks the site. A tracker that needs search or notifications needs a second
long-running process, which this platform has nowhere to put.

## Files

    panelalpha.yaml                          php-plain, docroot webroot, the install command
    hooks/prepare.sh                         arcanist, the two passwords, the writable directories
    files/webroot/.htaccess                  the front-controller rewrite Phorge ships no copy of
    files/panelalpha/php/zz-phorge.ini       the php.ini the base image does not load
    files/panelalpha/phorge-setup.sh         the install/upgrade stage
    files/panelalpha/phorge-db-secure.php    per-account database credentials
    files/panelalpha/phorge-bootstrap.php    the first administrator and the auth provider
    overrides/docker-compose.override.yml    /arcanist, /panelalpha, the database, the readiness gate

## Engine behaviour this recipe had to work around

Each of these was measured on this engine while writing the recipe; the file
that deals with each carries the detail.

1. **`extends: php` is refused for an application with no `composer.json`.**
   `DeployabilityCheck::REQUIRED_ROOT_FILE` maps the `php` strategy to
   `composer.json` and the only escape is `namedByItsOwnManifest()`. `php-plain`
   carries the same strategy and a different platform id, which is the intended
   answer.
2. **A 0700 directory in the checkout fails the deploy.** After the prepare hook
   the engine walks the project tree as www-data; `chmod 700 conf/local` ended
   the deploy with `scandir(/home/<acct>/project/conf/local): Failed to open
   directory: Permission denied`. The secret is protected on the file instead.
3. **`metamta.mail-adapter` no longer exists in Phorge** (it is `cluster.mailers`
   now) and `bin/config set` on an unknown key exits non-zero, which with
   `set -e` takes the deploy with it.
4. **The engine's own health probe is a different site.** Phorge answers a
   request whose Host matches no configured URI with a 500 "Site Not Found"
   page, and the probe uses `http://127.0.0.1:8000/`. A completely working
   install was scored `serving-error_page` until
   `phabricator.allowed-uris` learned about the loopback address.
5. **The harvested sidecar's credentials** — see Security, above.
