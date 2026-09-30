# HTMLy

Flat-file PHP blog on the engine's `php` platform (committed `system/vendor`).

- Before install, `GET /` is a 500; `files/panelalpha-htmly.conf` redirects only `/`
  to upstream's `/install.php` while `config/config.ini` is missing.
- `config/` (settings, users) and `content/` (posts, uploads) live in
  `~/.panelalpha/htmly` and are bind-mounted back, so they survive a redeploy.
- The installer (admin account) is left as upstream ships it.
