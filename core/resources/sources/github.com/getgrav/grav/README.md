# Grav (github.com/getgrav/grav)

Flat-file CMS: PHP, Twig and Markdown, no database of any kind. This branch is
Grav 2.1.8.

Detection: `php` — `composer.json`, no `artisan`. That was already right. The
shared `panelalpha/php:8.3-apache-bookworm` image serves `~/project` over a bind
mount, `composer install` runs on the host and resolves 61 packages from the
lock file, and nothing is built. The deploy reported success and every request
answered HTTP 500 — the `serving-error_page` verdict this recipe turns into a
served page.

The body of that 500 is one sentence:

    Theme 'quark2' does not exist, unable to display page.

A Grav checkout is not a runnable Grav. `.gitignore` excludes
`user/plugins/*` and `user/themes/*`, so the clone ships
`user/config/system.yaml` naming the quark2 theme, two pages under
`user/pages` and nothing to render them with — including the `error` and
`problems` plugins whose whole job is to make this readable, which is why the
response was a bare 500 rather than Grav's own diagnostic page.

The list of what belongs there is in the repository: `.dependencies`, four
plugins (`problems`, `error`, `github-markdown-alerts`, `shortcode-core`) and
one theme (`quark2`), each with a git URL, a target path and a branch.
Upstream installs them with `bin/grav install`, which cannot run in a prepare
hook: it is a Grav console command and needs `vendor/`, and the prepare stage
runs after the clone and before the build.

What the recipe adds:

- `hooks/prepare.sh` parses `.dependencies` (awk — the account shell has no
  YAML tool) and shallow-clones the five repositories to the paths it names,
  on the branches it names, dropping each `.git` afterwards. ~4.5 MB. Paths
  are checked against `user/plugins/` and `user/themes/` before anything is
  written through them, and a path that already has content is left alone.
- The same hook writes `.env` with three proxy settings (below). Only when
  absent: the engine keeps a `.env` it finds after the clone, so an operator's
  edits survive a redeploy.
- `panelalpha.yaml` is `extends: php` plus `docroot: .` — `index.php` is at the
  repository root and there is no `public/` or `web/`. The root `.htaccess` is
  Grav's front controller and its directory protection; the engine's vhost
  already grants `AllowOverride All` over the document root, so clean URLs work
  with nothing added.

## The proxy settings, and why they are not optional

Grav 2.0 turns every `http_x_forwarded` header off by default — a client can
forge them where nothing overwrites them — and appends a non-standard port to
its own base URL.

The engine's proxy does send what Grav needs. The app block in
`templates/virtualHost-nginx-proxy.blade.php` sets `X-Forwarded-Proto`,
`X-Forwarded-Host` and `X-Forwarded-Port` and passes the original `Host`; only
the `/phpmyadmin` and `/panelalpha-sso` locations omit the port, and neither
carries app traffic. The problem is entirely Grav's defaults: it ignores all of
it until told otherwise, falls back to `SERVER_PORT` — the 8000 its Apache
actually listens on — and builds its base URL as `http://<domain>:8000/`. That
is what goes into canonical links, feeds, sitemaps, the redirect after a form
post and every `base_url_absolute` a theme or plugin emits: an unencrypted URL,
on a port nothing publishes, inside an https page.

`HTTPS=on` is in the container environment (`PublicUrlEnvironment` sets it) and
does not help — mod_php does not expose the process environment as
`$_SERVER['HTTPS']`, which is where `Uri::createFromEnvironment()` reads it.

So `.env`, which is Grav's own override channel (symfony/dotenv, read before
the configuration is compiled, documented in the repository's `.env.example`),
and `user/config/system.yaml` is left exactly as upstream shipped it:

    GRAV_CONFIG=true
    GRAV_CONFIG__system__reverse_proxy_setup=true
    GRAV_CONFIG__system__http_x_forwarded__protocol=true
    GRAV_CONFIG__system__http_x_forwarded__ip=true

`reverse_proxy_setup` is the switch that stops the port being appended.
Measured against a reproduction of the engine's shape (Apache on 8000 behind a
TLS proxy): `http://host:8000/` without these, `https://host/` with them.

## No admin panel

Upstream's line, not an omission here. The classic `admin` plugin declares
`compatibility: grav: ['1.7']`, and its blueprint says Grav 2.0's admin is the
separate `admin2` plugin talking to an `api` plugin. `.dependencies` — the
repository's own statement of what it needs to run — lists neither. The site
serves its pages and is edited over SFTP, which for a flat-file CMS is a
working answer; `bin/gpm install` adds an admin once the 2.0-compatible one is
the published default. There is no `overrides/app.sh` for the same reason:
Grav has no user accounts until a login plugin creates them.

## No readiness gate

Unlike the compose-based recipes. There is no compose file of this recipe's to
put one in — the `php` strategy generates it — and nothing to wait for: no
database, no migration, no build at boot. Apache is bound before
`docker compose up -d` returns, and the first request compiles the Twig cache
in well under a second.

## Redeploys

A redeploy re-clones the repository, which wipes `~/project` — including pages
written through SFTP and any plugin installed with `bin/gpm`. That is the
engine's git-source model rather than anything specific to Grav, but it matters
more here than for an app that keeps its content in a database volume: for
Grav, `user/` **is** the database.
