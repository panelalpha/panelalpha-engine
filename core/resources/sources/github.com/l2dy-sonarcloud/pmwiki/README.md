# PmWiki (git mirror `github.com/l2dy-sonarcloud/pmwiki`)

PmWiki is a flat-file PHP wiki: no database, every page a file under `wiki.d/`,
the whole app `pmwiki.php` plus `scripts/`. Maintained by Petko Yotov.

## Why this repository and not pmwiki.org

PmWiki's canonical source is **SVN only** — `svn://pmwiki.org/pmwiki` (plus a
tarball) — with **no official git remote**; the `github.com/pmwiki` org has zero
repositories. The engine clones over git, so the canonical source is
uncloneable. `github.com/l2dy-sonarcloud/pmwiki` is a git mirror of the 2.3.x
tree; its HEAD is **pmwiki-2.3.27** (`scripts/version.php`). This is the
**mirror-row** case (Dotclear #1522 / Roundup #1523 precedent):
supported-apps#1294 (canonical) is **Rejected — uncloneable canonical**, and the
mirror gets its own Supported row keyed to this path.

The tree lints clean on PHP 8.3 (what the `php` runtime hands a repo with no
`composer.json`); its only pre-8 functions (`create_function`) are guarded shims
for legacy add-ons and are never reached by core. So there is no PHP-8 gate
failure — no repair, only configuration.

## What the recipe does

- **`docroot: "."` + `files/index.php`** — PmWiki ships no front controller; the
  one-line `include_once('pmwiki.php')` shim (from PmWiki's own install docs)
  makes `/` serve and lets `PhpDocroot` resolve the root.
- **`files/local/config.php`** — pins `$ScriptUrl` / `$PubDirUrl` /
  `$UploadUrlFmt` to `APP_URL` (the public https origin) so links and assets
  are not the container's `http://…:8000`; sets the responsive skin and UTF-8;
  reads the admin hash; **closes anonymous editing** (`$DefaultPasswords['edit']`
  = the admin hash). Reading stays public, editing/uploading require the admin
  login. Contains no secret.
- **`files/panelalpha/pmwiki-setup.sh`** (start stage, `before: true`) — moves
  `wiki.d/` (pages) and `uploads/` (attachments) onto `~/.panelalpha/pmwiki/`
  and symlinks them back so they survive the redeploy that empties `~/project`
  (engine#173); on first boot generates a bcrypt admin password into
  `~/.panelalpha/pmwiki/admin.hash` (0600) with the plaintext in
  `admin-credentials.txt` (0600); drops a PHP-disabling `.htaccess` in uploads;
  removes the clone's `.git`.
- **`overrides/docker-compose.override.yml`** — bind-mounts `~/.panelalpha` to
  `/pa-data`. No `database:` — PmWiki needs none, so no sidecar; it fits the
  smallest host.
- **`.htaccess` files** — root denies `wiki.d/`, `.git/`, the setup dir and the
  ini dir over HTTP; `local/.htaccess` (shipped by PmWiki) denies `config.php`;
  `uploads/.htaccess` disables PHP execution.
- **`files/.pa-php/zz-pmwiki.ini`** — `display_errors Off`, upload/memory limits
  (the base image loads no `php.ini`, engine#185).

## Using it

Open `/`, then `?action=login` — leave the username blank and enter the password
from `~/.panelalpha/pmwiki/admin-credentials.txt`. Edit any page with `?n=Group.Page&action=edit`.
