# Feather Wiki (codeberg.org/Alamantus/FeatherWiki)

A single-file wiki (a quine) that saves itself back to the server.

## What the recipe does

- `overrides/docker-compose.yml` runs upstream's PHP nest, `nests/index.php`,
  on `php:8.3-apache-bookworm` (port 80 in the container). The repo's
  `npm start` is a development watcher and is not used.
- The nest writes `featherwiki.html` (the wiki) and `.featherwikiadmin` (a
  password hash) next to itself; `/var/www/html` is the named volume
  `featherwiki`, with `index.php` mounted read-only from the checkout.
- `files/featherwiki-nest.conf` denies dotfiles, as `nests/README.md` asks
  for `.featherwikiadmin`.

First run is upstream's: the page asks for a username and password, then
downloads the current Warbler build from https://feather.wiki. Saving uses
"Save Wiki to Server" (an HTTP PUT with those credentials).
