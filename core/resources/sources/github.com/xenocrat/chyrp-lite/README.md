# Chyrp Lite

<https://github.com/xenocrat/chyrp-lite> — tracker issue
[#1024](https://git.modulesgarden.tech/panelalpha/playground/supported-apps/-/work_items/1024).

A blogging engine: plain PHP 8.1+, no framework, no Composer, no build step,
SQLite/MySQL/PostgreSQL, ~28 MB of checkout. Posts are written in "feathers"
(text, photo, quote, link, video, audio, uploader) and rendered through Twig
themes. Actively maintained; this recipe was written against
`2026.02.02 "Caspian"` (commit `3f5e0b1e`).

## What was wrong

Detection chose `compose` and the deploy succeeded — and every request answered
**HTTP 503** with Chyrp's own error page:

> ERROR: This resource is temporarily unable to serve your request.

That verdict is `serving-error_page`, and the cause is one tracked file.
`includes/upgrading.lock` is **committed to the repository**, nine bytes
containing the word `UPGRADING`, and `includes/common.php:373` answers 503 to
every request while it is there:

```php
# Exit if an upgrade is in progress.
if (file_exists(INCLUDES_DIR.DIR."upgrading.lock"))
    error(__("Service Unavailable"), …, code:503);
```

Only `install.php` and `upgrade.php` remove it (`install.php:1229`,
`upgrade.php:1204`), so *any* deployment made by cloning this repository —
including upstream's own `docker compose up` — serves 503 until somebody
completes the web installer by hand. The engine could not have inferred that,
and nothing short of running the installer fixes it.

The config file is the second 503 behind the first: `common.php:381` answers
"This resource cannot respond because it is not configured" while
`config.json.php` is missing. Both doors are closed by running upstream's
installer from the deploy.

## Why `php-plain` and not `compose`

The repository ships a root `docker-compose.yaml`, which is what
`compose-usable` (priority 980) claimed against `php-plain`'s 200. Unlike
s-cart, Shopware and Koillection, it is **not** a workstation stack: one
service, upstream's own `Dockerfile`, two named volumes, `127.0.0.1:8080:80`.
It is a real deployment — just not this one.

What it is not is a *hosting account*. Taking `php-plain` instead gets the
shared `php:8.x-apache` image with `~/project` bind-mounted (so the themes and
feathers are editable over SFTP, which is most of what someone hosting a blog
wants to do), the account's own MySQL server visible in the panel and
phpMyAdmin, the engine's vhost with its deny rules and `AllowOverride All`, and
a staged entrypoint that can run the installer before Apache binds. Taking
`compose` instead builds a ~1 GB PHP image per account — `apt-get` plus
compiling `pdo_pgsql` and `pdo_mysql` from source, measured at 38s of the 60s
deploy on a two-core host — to get a server with `AllowOverride None`, no
document-root protection and the installer still open on a public URL.

`panelalpha.yaml` pins the platform, which `PlatformSelector::fromSource()`
resolves ahead of the detection walk. `hooks/prepare.sh` additionally moves
`docker-compose.yaml`, `Dockerfile`, `entrypoint.sh` and `.dockerignore` into
`.panelalpha/`, because `Dockerfile` and `entrypoint.sh` would otherwise be
served as plain text from the document root and none of the four means anything
here. Upstream's own installer deletes all four when it finishes.

## Security: the installer, and the thing behind it

`install.php` is first-visitor-wins — it creates every table and the first
administrator from whatever it is posted, with no token and no lock, and the
only thing that ever closes it is the config file it writes at the end. On a
public HTTPS domain that is the hole found in Koillection, Outline, NocoDB,
Mattermost, Shopware, XBackBone, CouchCMS and Atheos.

`files/panelalpha-install.php` runs **upstream's own installer** from the
install stage, in a CLI process, before Apache binds: it sets `$_SERVER` and
`$_POST` and includes `install.php`, so the schema, the password hashing and
every future change upstream makes to it are upstream's. The password is 24
characters generated per account into
`~/.panelalpha/chyrp-lite/admin-credentials` (0600, in a 0700 directory —
account homes are root-owned 0755, so the directory has to be created before
Docker mounts it).

`upgrade.php` is the one that is easy to miss. It has **no authentication at
all**: an anonymous `POST upgrade=yes` runs the migrations, rewrites the config
and re-renames `cacert.pem`. Upstream means it to be deleted after use —
`install.php`'s closing screen says so, and the Dockerfile's `entrypoint.sh`
tries to (it looks for `includes/install.php` and `includes/upgrade.php`, which
are at the *root*, so it never does; upstream bug, harmless there only because
the image is rebuilt). This recipe runs it from the deploy on every redeploy
and then removes both scripts from the document root. They come back with every
clone, so that happens every deploy.

Measured on a deployed account:

| path | before | after |
| --- | --- | --- |
| `/install.php` | 200, the wizard | 404 |
| `/upgrade.php` | 200, "Upgrade me!" | 404 |
| `/includes/class/SQL.php` | 200 + `Fatal error … /app/includes/class/SQL.php:11` | 403 |
| `/tools/docgen.php` | 200 + PHP fatal | 403 |
| `/error_log.txt` | 200, the site's own errors | 403 |
| `/docker-compose.override.yml` | 200 | 403 |
| `/includes/caches/…` | 200 | 403 |
| `/.git/config`, `/.env` | 403 (the vhost) | 403 |

The 200s in the first column are what the `compose` deployment still serves,
and most of them are engine#185: the shared base image loads no `php.ini`, so
`display_errors=1` platform-wide, and asking Apache for a library file directly
prints a fatal error with its path. Chyrp turns `display_errors` off itself in
`includes/error.php:7`, but only for a request that reached a file which
includes it. `files/.htaccess` sets `php_flag display_errors Off` for the whole
account, and `files/includes/.htaccess` denies every `.php` in `includes/`
except the two that really are endpoints (`download.php`, `thumbnail.php` —
`grep -r "includes/" themes/ admin/` finds those and nothing else).

Registration is off: `install.php` writes `can_register: false`, and
`/?action=register` answers 302. Sessions are named by a literal
(`session_name("ChyrpSession")`, `includes/helpers.php:63`), not derived from
`__DIR__` — so the uniform `/app` mount (engine#175) collides nothing here,
unlike Atheos. The session cookie's `secure` flag is taken from the scheme of
the configured site URL (`helpers.php:43`), which is why the install is handed
the account's `https://` address rather than anything guessed from the request.

## Where the data lives

A redeploy clears and re-clones `~/project` (engine#173), so anything Chyrp
keeps inside the checkout is destroyed by every deploy. Two things are:

**The configuration.** `config.json.php` holds the database password, the site
URL, the theme, the enabled feathers and the `secure_hashkey` that signs
sessions. `STORAGE_DIR` is `$_SERVER['CHYRP_STORAGE_DIR']` falling back to
`includes/` (`common.php:101`), and `files/.htaccess` sets it to `/data` —
`~/.panelalpha/chyrp-lite`, bind-mounted by the compose override. It has to be
said in `.htaccess` rather than in the compose `environment:`, for the same
reason upstream's Dockerfile needs `PassEnv CHYRP_STORAGE_DIR`: mod_php does
not publish the container environment as `$_SERVER`. (Verified in the base
image: `SetEnv` does reach `$_SERVER`, and `php_value`/`php_flag` work, because
`mod_env` is enabled and the image is mod_php.)

**The uploads.** `uploads_path` is `MAIN_DIR . "/uploads/"` and `MAIN_DIR` is
`dirname(index.php)`; there is no setting that moves it outside the checkout.
XBackBone's answer — symlink the directory out — does not work here, because
Chyrp's uploads are served by Apache as static files and the generated vhost's
`<Directory /> Require all denied` matches the **resolved** path, so every
image on the blog would answer 403. (Measured.) So the compose override
bind-mounts `~/.panelalpha/chyrp-lite/uploads` *over* `/app/uploads` instead:
the files stay at the path Chyrp expects, inside the document root, and on the
account's own disk where the re-clone cannot reach them.

**The posts** are in the account's own MySQL database (`database: mysql`),
which survives a redeploy, appears in the panel and opens in phpMyAdmin. Chyrp
supports SQLite, and it was not chosen: a SQLite file would have to live on the
same bind mount and be invisible to every tool the account has.

Verified by redeploying an account that had a published post and two files in
`uploads/`: the clone ran, `~/project` was replaced, and the post, the uploads,
the administrator and the config were all still there afterwards.

One wrinkle worth knowing: the generated vhost denies any file whose name
begins `panelalpha-` or `docker-compose.`, anywhere. An upload called
`panelalpha-logo.png` will answer 403.

## The rest of the file

`includes/caches/{twig,thumbs}` stay in the checkout. They are caches, they are
regenerated on demand, and `install.php` refuses to run if they are not
writable — which is why `prepare.sh` creates them rather than trusting the
`.gitignore` stubs.

`files/.htaccess` also carries upstream's own rewrite rules — the ones
Controls → Settings → Routes offers as `URL_rewrite_files.zip`, rendered for a
site at the root of its domain. They are shipped enabled so that turning Clean
URLs on in the admin works instead of turning every page into a 404, and they
are inert while it is off (`index.php` and every real file match the `-f`/`-d`
conditions). Verified both ways: `/?action=view&url=…` with Clean URLs off,
`/2026/09/20/…/` with it on.

`php_value upload_max_filesize 100M` is there because the base image loads no
`php.ini` (engine#185) and PHP's built-in default is 2M, against Chyrp's own
10M default and upstream's Dockerfile setting 100M.

## The readiness gate

Measured rather than assumed (engine#90). The engine runs `docker compose up -d`
without `--wait` and probes as soon as it returns, and on a first deploy that
return happens while the ~20 `CREATE TABLE`s are still running — nothing is
listening and the probe reads a connection refusal. The override adds a
`GET /` healthcheck and a `ready` service that `depends_on` it, which is what
makes `up -d` block. On a redeploy the deploy log shows
`Container project-app-1 Waiting` … `Healthy` five seconds later, so the gate
is doing work even on the short path.

The healthcheck is a real check, not a port knock: `/` answers 200 only once
`upgrading.lock` is gone, `config.json.php` exists and the database is
reachable. Each of those is a 503 from `includes/common.php` on its own.

## Not done

- **No PanelAlpha SSO / user management** (`overrides/app.sh`). Chyrp hashes
  passwords with `User::hash_password` and has no token login;
  adding one means an upstream module, not a recipe.
- **Only the `text` feather is enabled**, which is what upstream's installer
  writes. Photo, quote, link, video, audio and uploader are one checkbox away
  under Controls → Feathers.
- **No modules are enabled.** Upstream ships 20 of them (comments, likes,
  tags, read-more, cacher…) and enables none; that is the customer's choice
  and enabling any of them here would be this recipe's opinion rather than
  Chyrp's.
- **Email is unconfigured.** Chyrp sends through PHP `mail()`, which the base
  image has no MTA for, so password-reset mail does not leave the container.
  This is the same on every PHP account and is not specific to Chyrp.
