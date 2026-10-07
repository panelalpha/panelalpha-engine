# QloApps (github.com/Qloapps/QloApps)

Hotel booking engine and website (PrestaShop 1.6 based, PHP 8.1-8.4, MySQL).
Runs on the engine's php platform; the installer (`/install-dev/`) is left as
upstream ships it. The customer enters the account's MySQL credentials there
(`DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`).

## What the recipe does

- `database: mysql` provisions the account's database.
- `hooks/prepare.sh` creates `~/.panelalpha/qloapps` (0700), bind-mounted at
  `/var/lib/qloapps`; the `persist-links` start command symlinks
  `config/settings.inc.php` and `/.htaccess` into it, so the installer writes
  them there and a redeploy finds the shop installed. Until then the links
  dangle and `file_exists()` still sends visitors to the installer.
- `overrides/docker-compose.override.yml` puts `img/`, `upload/`, `download/`
  and the bundled hotel modules' image directories on named volumes; the
  one-shot `qlo-volumes` copies the checkout's files into them (never
  overwriting) and chowns them to the account user.

Not kept across a redeploy: modules or themes installed from the back office,
and language packs added after install (they live in the checkout).

## Known engine trap

composer.json requires `ext-mcrypt`. The engine builds a variant PHP base with
it in the background; on a host that does not have it yet the first deploy
fails with `This project needs the PHP extension mcrypt` and a redeploy a few
minutes later succeeds.
