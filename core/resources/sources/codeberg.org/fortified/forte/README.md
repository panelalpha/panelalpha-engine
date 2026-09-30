# Forte

ActivityPub server in plain PHP. The generic `php` strategy serves `public/`,
a leftover Symfony stub (`App\Kernel` not found, HTTP 500); the application is
the repository root's `index.php`.

- `PA_DOCROOT=/app` and upstream's `htaccess.dist` copied to `.htaccess`.
- `database: mysql`: the setup page takes the `DB_*` credentials the engine shows.
- `.htconfig.php` (written by Forte's setup) and `store/` live in
  `~/.panelalpha/forte`, so a redeploy does not send the site back to setup.
- Forte's background queue wants a cron (`php util/...`); not needed to serve.
