# Omeka S — github.com/omeka/omeka-s

Omeka S publishes digital collections: a Laminas MVC application over Doctrine
ORM and MySQL, an administrative interface at `/admin`, and public sites at
`/s/<slug>` rendered by a theme.

Detection reads the repository correctly on its own — composer.json and no
artisan, so the `php` strategy, PHP 8.1, the shared Apache base image with
`~/project` bind-mounted, `composer install` on the host. Without this recipe
the deploy finishes and every request answers **HTTP 500**.

## What the engine could not infer

**No database, and that alone is the 500.**
`application/config/application.config.php` is not a static array: its first
statement reads `config/database.ini` through `Laminas\Config\Reader\Ini`, and
it rethrows the RuntimeException when the file is missing and
`OMEKA_DB_CONNECTION_URL` is unset. The repository ships
`config/database.ini.dist` with four empty values and `.gitignore`s the real
name, so a clone has none, and `index.php`'s outer catch turns the throw into
`http_response_code(500)` plus `application/view/error/fallback.phtml` on every
path. That rendered page is why the health report's whole `php` group passes
on it — php-executes, entry-served, no-fatal-error and no-database-error all
see PHP produce HTML. Nothing in the checkout says MySQL to a probe: no `.env`, no
compose file, no `DATABASE_URL`. `database: mysql` in `panelalpha.yaml` is the
manifest key that says it (Matomo's, for the same reason), and the engine then
provisions a database and user on the account's own MySQL server and passes
`DB_HOST`/`DB_PORT`/`DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD` into the
container. `files/panelalpha-setup.sh` writes them into `config/database.ini`
(0600) on the install and upgrade stages, before Apache binds — the earliest
point at which they exist, since `hooks/prepare.sh` runs before the database
does.

**The document root is the repository root**, which `PhpDocroot` works out
anyway (it probes `public web public_html webroot`, then the root, and
`index.php` is at the top). It is declared because the consequence is unusual:
with the root served, `config/database.ini` and `logs/` are one request away
unless something denies them, and the thing that denies `.ini` is `.htaccess` —
which the repository ships as `.htaccess.dist` and `.gitignore`s. The hook
installs it. It is not optional: it also carries the front-controller rewrite,
without which every route but `/` is a 404 from Apache.

**The first administrator.** Omeka S seeds no user. `InstallController` puts a
form at `/install`, `MvcListeners::redirectToInstallation()` sends every other
route to it, and whoever arrives first becomes the global administrator of the
account. So the install runs from the install stage instead, in the container,
before the server accepts a request: `files/panelalpha-install.php` boots the
application the way `index.php` does and drives `Omeka\Installer` — the same
service, the same eight tasks from `InstallerFactory`, the same two
`registerVars()` calls `InstallController` makes out of the validated form —
with a password generated per account into `~/project/.panelalpha-admin-password`
(0600). Omeka supports this deliberately: `MvcListeners::bootstrapSession()`
returns early on `PHP_SAPI === 'cli'`, and `AuthenticationServiceFactory` swaps
in a `NonPersistent` storage while `Status` says the application is not
installed. On a redeploy the script takes the other branch —
`Omeka\MigrationManager::upgrade()` and the version setting, which is what
`/migrate` does in the browser — so a code change reaches the schema instead of
waiting on a form nobody is logged in to find.

**The default theme is installed by a Composer plugin that never runs.**
`omeka-s-themes/default` is `"type": "omeka-s-theme"`, and what knows to put it
in `themes/` is `omeka/composer-addon-installer`, a composer-plugin the
repository ships in-tree as a `path` repository. The php manifest installs with
`--no-plugins`, and `PhpHostBuild::mayRunPlugins()` lifts that only for the five
installer plugins it names — so Composer falls back to its own LibraryInstaller
and the theme lands in `vendor/omeka-s-themes/default`, where Omeka never
looks. The deploy log says so in as many words: *"The
`omeka/composer-addon-installer` plugin was not loaded as plugins are
disabled."* Without the theme the account has none at all and no public site
can be created. The setup script copies it across; this cannot be fixed in the
build, because an app config's `commands` never reach the build stage.

**ImageMagick is a binary, and the base image has the extension.** Omeka's
default thumbnailer shells out to `convert`, and in this container
`Omeka\File\Thumbnailer\ImageMagick::setOptions()` throws
`InvalidThumbnailerException: ImageMagick error: cannot determine path to
ImageMagick command`, so every media upload would fail at upload time, long
after the deploy called itself healthy. `files/config/local.config.php` aliases
`Omeka\File\Thumbnailer` to the `Imagick` implementation, which uses the PHP
extension the image does ship.

**`logs/` is inside the document root.** The repository's `.htaccess` denies
`.ini` and nothing else, so `logs/` is served as-is. Omeka's logger is off by default; the moment an operator turns
it on in `config/local.config.php`, the log is public. `files/logs/.htaccess`
denies the directory. `files/` — the upload store — stays served, because that
is what it is for.

**Readiness.** The engine runs `docker compose up -d` without `--wait` and
probes as soon as it returns, which would be while the install stage is still
building the schema and importing vocabularies. `overrides/docker-compose.override.yml`
gives the app a healthcheck and adds a no-op `ready` service gated on
`depends_on: app: condition: service_healthy`, which is what makes `up -d`
block. The healthcheck greps `/login` for `type="password"` rather than trusting
a status code: with nothing installed, `/install` also answers 200, and a status
check would call the open wizard healthy.

## Files

| File | What it does |
|---|---|
| `panelalpha.yaml` | `extends: php`, `docroot: .`, `database: mysql`, and the `omeka-setup` command on the install and upgrade stages |
| `hooks/prepare.sh` | `.htaccess` from `.htaccess.dist`, the generated administrator password, the directories Omeka writes into |
| `files/panelalpha-setup.sh` | `config/database.ini` from `DB_*`, the theme out of `vendor/`, then the installer |
| `files/panelalpha-install.php` | Drives `Omeka\Installer` on a fresh database, `Omeka\MigrationManager` on one that is already there |
| `files/config/local.config.php` | The Imagick thumbnailer |
| `files/logs/.htaccess` | Denies `logs/` to the web |
| `overrides/docker-compose.override.yml` | The app healthcheck and the `ready` gate |

The two scripts are named `panelalpha-*` at the top of the checkout rather than
put in a `panelalpha/` directory, because the document root is the checkout: the
generated vhost denies `^(?:docker-compose\.ya?ml|panelalpha[-.])` by filename,
and a directory of that name would have been served.

## Credentials

`~/project/.panelalpha-admin-password`, mode 0600, generated per account by
`hooks/prepare.sh` and never a default. The administrator is
`admin@example.com` — a hook is told neither the account's address nor its
domain, and the password is the secret, not the address. Override either with
`OMEKA_ADMIN_EMAIL` / `OMEKA_INSTALLATION_TITLE` in the project's env vars
before the first deploy.

## Known limits

- No `overrides/app.sh`: no `info`, `install`, `users:*` or SSO. Omeka S has a
  REST API with per-user key pairs and an ACL, so the strategy is there to be
  written; this recipe only gets the application deployed and safe.
- Nothing creates a site, so `/` is Omeka's (empty) list of public sites until
  an administrator makes one. That is Omeka's own empty state, not a failure.
- `composer.json` answers 200. That is upstream's own posture for a
  root-document-root PHP application (Matomo's is the same) and it holds no
  secret, so it is left as it is.
