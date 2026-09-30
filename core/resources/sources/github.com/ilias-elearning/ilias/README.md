# ILIAS (github.com/ilias-elearning/ilias)

Learning management system, PHP 8.3/8.4 on MySQL/MariaDB. Installed from the
command line only (`cli/setup.php`); there is no web installer.

## Deploying

Nothing to set. The first deploy installs ILIAS into the account's database;
log in as `root` / `homer` (upstream's default) and ILIAS asks for a new
password.

## What the recipe does

- Engine php platform, `docroot: public`, the account's MySQL (`database: mysql`).
- `ilias-npm` (one-shot, node) runs upstream's `npm clean-install --omit=dev
  --ignore-scripts`; the composer post-autoload-dump on the host cannot build
  `public/` without it, so its "Cannot copy /app/node_modules/..." line in the
  deploy log is expected.
- The `ilias-setup` command, every boot: symlinks `/app/ilias.ini.php` onto the
  `ilias-config` volume, runs `setup.php build`, then `setup.php install` once
  (marker `.installed`) or `setup.php update`. The config JSON is generated
  from DB_* and APP_URL by `files/panelalpha-ilias/setup-config.php` into the
  container's /tmp and removed after. Before the install,
  `files/panelalpha-ilias/db-charset.php` sets the database to utf8mb3, the
  only charset ILIAS supports (utf8mb4 fails with "Row size too large").
- `files/panelalpha-ilias/php.ini` is install.md's php.ini (512M memory, 256M
  uploads, max_input_vars 10000), mounted into conf.d.
- External data (`/var/lib/ilias`), logs and `public/data` are named volumes,
  chowned to the account user by the one-shot `ilias-volumes`.
