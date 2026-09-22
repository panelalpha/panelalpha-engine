# b1gMail for PanelAlpha Engine

b1gMail 7.4 is a webmail application: plain PHP, MariaDB, served from `src/`,
with a browser setup wizard and no CLI installer. The repository ships
`serverlib/config.default.inc.php` (a two-line redirect to `./setup/`) and no
`config.inc.php`, so without a recipe every request ends in

```
Warning: require(/app/src/serverlib/config.inc.php): Failed to open stream ...
Fatal error: Uncaught Error: Failed opening required '/app/src/serverlib/config.inc.php'
  in /app/src/serverlib/init.inc.php:285
```

served as **HTTP 200** — which is why the unassisted verdict is
`serving: php_error` rather than a 5xx.

## Why a mail application is here at all

This platform does not host applications that need to *receive* SMTP: a tenant
application never owns port 25 on its account's domain. b1gMail is an exception
because its default receive mode is a **POP3 client pull**, not an inbound MTA:

- `src/setup/index.php:339` — the `receive_method` radio for the POP3 gateway
  carries `checked="checked"`; `pipe` (the app as delivery target) is the
  alternative, and this recipe never selects it.
- `src/serverlib/pop3gateway.class.php:36` — `BMPOP3Gateway`, a POP3 *client*.
- `src/cron.php:133` — `if ($bm_prefs['receive_method'] == 'pop3')` runs it.
- `src/admin/prefs.email.php:96` — the ACP writes `receive_method`, `pop3_host`,
  `pop3_port`, `pop3_user`, `pop3_pass`.

The operator points b1gMail at a catchall mailbox they already have, and
`cron.php` fetches from it. Nothing in this recipe listens on 25, configures a
transport map, or makes the account the destination MTA for its domain.

## What the recipe does

| File | Why |
|---|---|
| `panelalpha.yaml` | `extends: php-plain`, `docroot: src`, `database: mysql`, the install/upgrade command and the cron loop |
| `files/src/serverlib/config.inc.php` | b1gMail's DB config, reading `DB_*` out of the container environment instead of holding a copied password. Also `mysqli_report(MYSQLI_REPORT_OFF)` — see below |
| `files/panelalpha-b1gmail-setup.php` | Drives b1gMail's own `setup/index.php` at `STEP_INSTALL` from the CLI, or syncs the schema if the database is already installed; then deletes `src/setup/` |
| `files/panelalpha-b1gmail-cron.sh` | The `cron.php` loop |
| `files/src/.htaccess` | `display_errors off`, `log_errors on` |
| `files/src/plugins/.htaccess` | Denies `*.php` under `plugins/`, which are includes, not entry points |
| `files/src/clientlib/ckeditor/samples/.htaccess` | Denies CKEditor's bundled demo pages |
| `hooks/prepare.sh` | Creates `~/.panelalpha/b1gmail/`, generates the signing key and admin password, creates the mail-store directory, copies `version.default.inc.php` into place |
| `overrides/docker-compose.override.yml` | Second `env_file` for the secrets, bind mount for the mail store |

### The setup wizard is never reachable

`setup/index.php` is first-visitor-wins: whoever loads it against an empty
database chooses the admin password. The install command runs in the app
container's entrypoint, *before* `panelalpha-serve` execs Apache, so the wizard
has already run and `src/setup/` is already gone by the time the port opens.

It jumps straight to `STEP_INSTALL` (8). The earlier steps are the form and its
validation, and `STEP_CHECK_EMAIL` is the one that insists on a POP3 box that
answers — there is no mailbox to name at deploy time. `receive_method` is still
written as `pop3`; the host, user and password are blank until the operator
fills them in under ACP → Preferences → Email → Receive.

Two departures from the wizard's own defaults:

- `setup_mode=private`, not `public`. Public leaves open self-registration on a
  webmail server nobody has configured yet.
- The admin password is generated per account into
  `~/.panelalpha/b1gmail/b1gmail.env`, mode 0600 in a 0700 directory, rather
  than shown on a page anyone could have loaded.

### Secrets and state live outside `~/project`

`ProjectTree::clearContents()` empties `~/project` before every git re-clone, so
nothing there survives a redeploy. Two things must:

- **The signing key and the admin password** — `~/.panelalpha/b1gmail/b1gmail.env`,
  reaching the container as a second `env_file:`. `env_file` is read when the
  container is *created*, and the prepare hook runs before `compose up`.
- **The mail store** — every message body, attachment and webdisk file is a
  file under `prefs.datafolder` (`serverlib/init.inc.php:393` →
  `DataFilename()`, `serverlib/common.inc.php:2405`). The wizard sets that to
  `<docroot>/data/`, inside the checkout. The install command repoints it at
  `/var/lib/b1gmail/data/`, bind-mounted from `~/.panelalpha/b1gmail/data`.
  Leave that mount out and a redeploy deletes every message the account holds.

The database itself is the account's own MySQL server, provisioned by
`database: mysql`; its credentials are stable across rebuilds.

### PHP 8 compatibility shim

b1gMail tests mysqli return values and never calls `mysqli_report()` — its DB
layer treats a failed query as `false` (`serverlib/db.class.php:154`). Since PHP
8.1 the default is `MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT`, which turns each
of those into an uncaught `mysqli_sql_exception`. The image is PHP 8.3, so
`config.inc.php` restores `MYSQLI_REPORT_OFF` before anything connects.

### Cron

`cron.php` is b1gMail's only scheduler and the only thing that runs the POP3
gateway. The engine has no cron stage a recipe can declare, and the per-project
crontab feature is not wired into the dind template, so the `start` stage
launches a background loop in the app container that wakes every 60 s.
`cron.php` gates itself on `prefs.cron_interval`, so the effective interval is
whatever the ACP says.

## What the operator still has to do

- Fill in the catchall POP3 host, user and password (ACP → Preferences → Email
  → Receive). Until then the gateway has nothing to pull from.
- Configure outbound mail. The install writes `send_method=php`, and the
  container has no MTA, so sending needs an SMTP host set in the ACP.
- Add the mail domains they actually own. The install seeds exactly one: the
  project's own domain.

## Not covered

No `overrides/app.sh`: b1gMail has no CLI for user management, so panel-side
user listing/creation and SSO would mean a bespoke PHP helper. Users are
managed in the ACP.
