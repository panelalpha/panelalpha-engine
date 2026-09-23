# Homepage (github.com/tomershvueli/homepage)

A self-hosted browser start page. One `src/index.php`, one `src/config.json`,
a jQuery / Bootstrap / Font-Awesome-4.7 asset bundle and one AJAX endpoint
that fetches a background image. 25 files in the repository, no framework, no
Composer manifest, no database, no accounts.

Detection: `php-plain` on the `php` strategy — no `composer.json` anywhere, so
`PhpSourcesProbe` answers and `php.yaml` does not. Document root `src/`, which
`PhpDocroot::detect()` reaches on its own: the repository root has no index, so
every ordinary candidate misses and `src` is picked up from `LATE_CANDIDATES`
(`PhpDocroot.php:45`). Both were already right. The deploy succeeded on the
first attempt in 45s and every request answered HTTP 200 with a PHP stack trace
in it — the `serving-php_error` verdict this recipe fixes.

## The error

    Warning: file_get_contents(config.json): Failed to open stream:
      No such file or directory in /app/src/index.php on line 12
    Fatal error: Uncaught TypeError: array_merge(): Argument #2 ($array)
      must be of type array, null given in /app/src/index.php:13

`src/.gitignore` lists `config.json`. Upstream's install instruction is "copy
`config.sample.json` and rename it", so a clone never has one, and `index.php`
reads it with no guard: `file_get_contents()` returns `false`,
`json_decode(false, true)` returns `null`, `array_merge($defaults, null)` is
fatal on PHP 8.

It answers **200** rather than 500 because of engine#185. The base image loads
no `php.ini` at all (`php -i` → `Loaded Configuration File => (none)`), so
`display_errors` is on and `error_reporting` is `E_ALL`; the warning is written
into the response body, and writing a body sends the headers, with the 200 that
was already on them. Measured on the control deploy: with
`php_flag display_errors off` added and nothing else changed, the same checkout
answers a clean, empty 500.

That is the same mechanism as Atheos, one step less severe — there the notice
broke `session_start()` and took the whole application with it; here it only
publishes the stack trace of a failure that was going to happen anyway.

## What the recipe adds

`panelalpha.yaml` is four lines of manifest: `extends: php-plain` and
`docroot: src`. No `id:` and no stage commands at all — everything is host-side
work on the checkout, which is what the after-clone hook is for, so engine#169
has nothing to drop. (`docroot: .` would not have worked even if the docroot
were the repository root: `PlatformManifest::readDocroot()` folds `.` to `""`,
which means "undeclared" — engine#172. A real relative path like `src` is
honoured.)

`hooks/prepare.sh`:

- Writes `~/.panelalpha/homepage/config.json`, once per account, and symlinks
  `src/config.json` at `/data/config.json`. Not a copy of
  `config.sample.json`: that file's links are someone else's, and it omits
  `new_tab` on three of its six items — `index.php` reads `$item['new_tab']`
  with no `isset()`, so on this image that is three `Undefined array key`
  warnings printed inside `<main>`. The generated config spells `alt`, `icon`,
  `link` and `new_tab` on all six items and keeps the `protected` block, which
  `hp_assets/lib/ajax_get_image.php` reads the same unguarded way.
- Copies the repository's `sayagata-400px.png` into
  `~/.panelalpha/homepage/img/` before the compose override mounts that
  directory over `src/hp_assets/img`. It is the tile `main.css:2` uses as the
  page background and the one tracked member of that directory.
- Appends three things to `src/.htaccess`, keeping upstream's Cache-Control and
  mod_deflate rules: `php_flag display_errors off` under
  `<IfModule mod_php.c>`; `Require all denied` for `config.json` **and**
  `config.sample.json`; and `SetEnvIf X-Forwarded-Proto "^https$" HTTPS=on`.

`overrides/docker-compose.override.yml` mounts `~/.panelalpha/homepage` at
`/data` and `~/.panelalpha/homepage/img` over `/app/src/hp_assets/img`. An
override, not a replacement: an `overrides/docker-compose.yml` is written into
the checkout before detection and would make Homepage look like a compose
project, losing the php strategy, the bind mount and `PA_DOCROOT` with it.

## Data, and what a redeploy does to it

Both mutable things are inside the checkout and both are gitignored —
`src/config.json` and `src/hp_assets/img/*` — and a redeploy clears and
re-clones `~/project` (engine#173). Without the mounts, the account's entire
configuration is deleted by the next deploy: there is nothing in a database,
because there is no database.

`index.php` reads `config.json` as a relative path and offers no way to
redirect it, so the file stays at the path upstream's README names and the path
is what moves — an absolute symlink to the mount point. It is dangling when
read from the host, which is why `~/.panelalpha/homepage/README.panelalpha.md`
tells the operator to edit the file there. `config.json` is 0600 in a 0700
directory: `protected.unsplash_client_id` and `protected.custom_url_headers`
are credentials.

Verified by reproducing a redeploy by hand (`rm -rf ~/project`, re-clone,
re-apply the recipe, `up -d --force-recreate` — the engine has no redeploy
endpoint, #2344): a hand-edited title, a hand-added link, a changed hover
colour and a PNG dropped into `img/` all came back unchanged, and the symlink
and the `.htaccess` block were re-created.

## Authentication: there is none

No login, no session, no user record, no admin page. Homepage is a public HTML
page that happens to be rendered by PHP; upstream's own screenshots are of a
LAN start page. On a public HTTPS name, every link on it is readable by anyone
who finds the name — an operator should be told that plainly rather than have
it described as "no admin panel". A recipe cannot fix it without inventing an
auth layer the application has no notion of; what it can do is keep the
configuration file itself unreadable, which it does.

The one endpoint reachable without a session is
`hp_assets/lib/ajax_get_image.php`. It takes no parameters and, with no
background source configured, answers 200 with an empty body. When an operator
sets `protected.custom_url` it becomes a server-side fetch of a URL only that
operator can choose.

Exposure sweep on the deployed account, bodies compared against a reference 404
and a reference 403 rather than status codes alone (there is no front
controller and no rewrite here, so a 404 really is "not there"):

| path | result |
| --- | --- |
| `/config.json` | 403, denied by both the 2.2 and the 2.4 block |
| `/config.sample.json` | 403 — upstream serves it; this recipe does not |
| `/.htaccess`, `/.gitignore`, `/.git/*` | 403 (vhost `FilesMatch`/`DirectoryMatch`) |
| `/docker-compose.yml`, `/.env`, `/.env.default`, `/panelalpha-after-clone.sh` | 403 (vhost) |
| `/docker-compose.override.yml` | 404 — see below |
| `/README.md`, `/CONTRIBUTING.md`, `/LICENSE.md`, `/example_img/*` | 404, outside the document root |
| `/hp_assets/`, `/hp_assets/img/`, `/hp_assets/lib/` | 403, `Options -Indexes` |
| `/hp_assets/lib/ajax_get_image.php` | 200, empty |

`config.json` was also probed as `hp_assets/img/../../config.json`,
`./config.json`, `%2econfig.json` and `config.json/` — all 403 — and as
`CONFIG.JSON` and `config.json.`, which are 404 because the filesystem is
case-sensitive and neither file exists.

**engine#181 does not apply to this application.** It is the one where
`docker-compose.override.yml` is web-readable because the vhost's rule reads
`^(?:docker-compose\.ya?ml|panelalpha[-.])` and does not match
`docker-compose.override.yml`. Here the document root is `src/` and the
engine's files are at the repository root, one level above it, so that request
is a plain 404 — verified, not assumed. A `files/.htaccess` of the kind CouchCMS
needs would be dead weight.

## Measurements

- Deploy: **45.2s** wall, 38s engine-side (preparing 9s, cloning 3s, running
  26s). Identical to the control deploy's 45.3s — the recipe costs nothing.
- Runtime footprint: **17 MiB** RSS for the whole container.
- engine#90 (`up -d` does not wait): measured over three down/up cycles on
  mariusz2, `docker compose up -d` returned in 0.89-0.99s and Apache answered
  HTTP 200 **68-84ms** later, once with no failed poll at all. **No readiness
  gate is added** — there is no database, no migration and no build at boot.
- engine#166 (sidecar mining): not applicable. The repository ships no compose
  file of any kind, so nothing is moved and nothing is globbed.

## `{{cur}}` and the scheme

An item's `link` may contain `{{cur}}`, replaced with this site's own URL.
`get_current_url()` (`index.php:16`) decides the scheme from `$_SERVER['HTTPS']`
or `SERVER_PORT == 443`. Apache here listens on plain 8000 behind the
TLS-terminating proxy, so on the face of it neither is true — and yet the links
come out `https://` with no recipe at all.

The reason is worth writing down, because it is not a reason to rely on. With
no `php.ini` loaded (engine#185), `variables_order` keeps its compiled default
of `EGPCS`, which merges the process environment into `$_SERVER` — and the
generated compose file sets `HTTPS: 'on'`. The correct scheme on every hosted
Homepage is currently a side effect of a missing configuration file.
`php.ini-production` spells `variables_order = "GPCS"`, so the day a `php.ini`
appears on this image, every `{{cur}}` link everywhere silently becomes
`http://`.

So the hook adds `SetEnvIf X-Forwarded-Proto "^https$" HTTPS=on`, which takes
the scheme from the header the proxy actually sends
(`templates/dind/virtualHost-nginx-proxy.blade.php:39`). Verified with a probe
script under the document root: `SET=on` through the proxy, `UNSET` on a direct
request to `:8000` with no header, `SET=on` on a direct request carrying it.
Requests that arrive without the header — the health probe, which sends
`Host: 127.0.0.1` (engine#190) — fall back to the environment variable, which
is what happens today.

## What is not done

- No background image source is configured. Both of upstream's options need
  something that belongs to the customer — an Unsplash client id, or a custom
  JSON endpoint and a PHP array selector — so `protected` is written with empty
  values and the page uses the tiled `sayagata-400px.png` the repository ships.
  With no source configured, `main.js` still polls `ajax_get_image.php` every
  `time_to_refresh_bg` ms for an empty answer; the generated config sets that
  to upstream's recommended 90000 rather than the sample's 20000.
- `index.php` is not patched to guard the missing `config.json`. The file not
  being missing is the actual fix, and a patched checkout diverges from
  upstream for nothing.
- `idle_timer` is deliberately absent from the generated config. Upstream's
  README says leaving it out disables the auto-hide; a menu that vanishes 60
  seconds after the page loads reads as a broken page. `show_menu_on_page_load`
  is `true` for the same reason — with the sample's `false`, the first thing an
  operator sees is a clock on an empty background.
