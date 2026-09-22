# CouchCMS

Tracker: [supported-apps#1061](https://git.modulesgarden.tech/panelalpha/playground/supported-apps/-/work_items/1061)
· Upstream: <https://github.com/CouchCMS/CouchCMS> (v2.4, `60484485`)

An ordinary PHP + MySQL content management system, and the whole of what was
wrong with it here is that the repository has no front page: `README.md`,
`CHANGELOG.md`, `INSTALL.md`, `UPGRADE.md` and `couch/`. The batch verdict was
`serving-missing_entry` — a successful deploy in which every request answered
403.

## What this recipe decides

**The document root stays the repository root, and the missing page is
written.** `files/index.php` is a working CouchCMS template with two editable
regions, laid down beside `couch/`. Serving `couch/` instead would have been one
line and it would have been wrong: CouchCMS builds `K_ADMIN_URL` as
`K_SITE_URL . basename(K_COUCH_DIR) . '/'` (`couch/header.php:283`) and
`K_SITE_DIR` as the parent of `couch/` (`header.php:197`), so an installation
served from `couch/` advertises an admin URL one level above what Apache serves
and addresses its uploads as `/couch/uploads/` inside a document root that is
already `couch/`. Upstream's model is the other one — your pages at the root,
`couch/` as the engine behind them — and it is the one a customer can keep
building on.

**The installer is run from the deploy, not by the first visitor.**
`couch/install.php` creates every table and the super-admin from whatever it is
posted, and `header.php` hands any request to it whenever the `k_couch_version`
row is missing — no token, no lock file. `files/panelalpha-install.php` runs
upstream's own installer in a CLI process from the install stage, with a
password generated per account into `~/.panelalpha/couchcms-admin-password`
(0600). Once it has run, the window is shut: the version row is the only thing
`header.php` checks.

**The starter template is registered by the deploy too.** CouchCMS inserts a
template's row and creates its master page only for a logged-in super-admin
(`couch/page.php:181`); a visitor who arrives first is bounced to the admin
login. The same install stage makes that first super-admin visit itself. It is
also where `couch/upgrade.php` runs on a redeploy.

**The attribution stays.** `couch/LICENSE.txt` is the Common Public Attribution
License 1.0: hosting for third parties is permitted, and Exhibit B requires the
Original Developer's attribution to be displayed whenever the software runs.
Sections 99 and 100 of `config.example.php` are that mechanism —
`K_PAID_LICENSE` and `K_REMOVE_FOOTER_LINK`, both `0` — and this recipe writes
`config.php` from upstream's example with both untouched. Turning either on is
what CouchCMS sells a commercial licence for. Verified on the deployed site:
every front-end page carries the "Powered by CouchCMS" link, and the admin panel
keeps its own logo, name and version.

## Files

| Path | What it is |
| --- | --- |
| `panelalpha.yaml` | `extends: php-plain`, `database: mysql`, the `couchcms-setup` command on the install and upgrade stages |
| `hooks/prepare.sh` | Generates the super-admin password into `~/.panelalpha/` and copies it into the checkout as a dotfile |
| `files/index.php` | The starter template — the page the repository does not have |
| `files/.htaccess` | Denies `docker-compose.override.yml`, which the generated vhost's own rule does not cover |
| `files/panelalpha-setup.sh` | Install/upgrade stage: config, install, register |
| `files/panelalpha-install.php` | The CLI installer driver (`config`, `check`, `install`, `render`) |
| `overrides/docker-compose.override.yml` | `PA_DOCROOT=/app`, a healthcheck on `GET /`, and a `ready` service so `up -d` returns only once CouchCMS answers |

Both engine files are named `panelalpha-*` on purpose: the document root is the
project root, and the generated vhost denies exactly
`^(?:docker-compose\.ya?ml|panelalpha[-.])` plus dotfiles. A `panelalpha/`
directory, which recipes serving from `public/` can use, would be served here as
plain text.

## Verified

Deployed on `mariusz.panelalpha.tools`, 2026-09-20: `deploy-ok`, `serving: ok`,
every baseline and `php` health check passing, HTTP 200 on the public domain.

- Logged in at `/couch/` over HTTPS with the generated password; the admin panel
  renders (`<title>Admin Panel</title>`, CouchCMS Version 2.4).
- Edited the heading and the rich-text region in the admin and saw both on the
  public page — the whole round trip, including CouchCMS's CSRF nonce, which is
  keyed on a secret stored in the account's own database.
- `/couch/config.php`, `/couch/db.php` and `/couch/install.php` answer 200 with
  an empty body (they `die()` without `K_COUCH_DIR`); `/.git/config`, `/.env`,
  `/.panelalpha-admin-password`, `/docker-compose.yml`,
  `/docker-compose.override.yml`, `/panelalpha-setup.sh` and
  `/panelalpha-install.php` all answer 403.
- A rebuild re-ran the upgrade stage in 32s and the edited content came back
  unchanged.

## Known caveats

- **Uploads do not survive a redeploy.** CouchCMS writes them to
  `couch/uploads/` inside the checkout, and every deploy re-clones over
  `~/project` while the database rows that point at them stay. The same is true
  of a customer's own template files: edit them over SFTP and keep a copy.
- **Per-installation secrets are generated with `rand()`**, not a CSPRNG
  (`couch/functions.php:2133`). They are per-account and stored in the account's
  own database — nothing is shared between tenants — but they are weaker than
  they look.
- **`couch/restore_dump.php` emits a PHP deprecation notice** before its own
  argument check. Upstream's, not the recipe's, and it leaks only a path.
