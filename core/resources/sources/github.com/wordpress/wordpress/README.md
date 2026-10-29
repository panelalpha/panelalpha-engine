# WordPress for PanelAlpha Engine

The wordpress/wordpress repository contains WordPress source files but no `docker-compose.yml`. The deployment uses the official `wordpress` Docker image backed by a MySQL 8 database. Secure credentials and secret keys are generated once into `~/.panelalpha/wordpress/` (0600 files in a 0700 directory) and passed to the containers with `env_file:`. They are not written to `~/project`, which is emptied on every deploy and is also the document root. `wp-content` (uploads, plugins and themes installed from the admin) is kept on the `wp_content` volume, seeded from the checkout by the one-shot `wp-content` service. A `wp-cli` service on the `wordpress:cli` image, with the same tree and environment, carries the MySQL client that WP-CLI's database commands need and the `wordpress` image lacks. That client cannot log in with MySQL 8's default `caching_sha2_password`, so the one-shot `db-auth` service switches the `wordpress` database user to `mysql_native_password` on every start. After that, the installation wizard is the only manual step left.

## PanelAlpha Engine snippets

- `panelalpha-after-clone.sh` — [`hooks/prepare.sh`](hooks/prepare.sh)
- `docker-compose.yml` — [`overrides/docker-compose.yml`](overrides/docker-compose.yml)
- `panelalpha/apache-deny-dotfiles.conf` — [`files/panelalpha/apache-deny-dotfiles.conf`](files/panelalpha/apache-deny-dotfiles.conf)
- `panelalpha/mysql-client.sh` — [`files/panelalpha/mysql-client.sh`](files/panelalpha/mysql-client.sh)
- `wp-content/mu-plugins/panelalpha-app.php` — [`files/wp-content/mu-plugins/panelalpha-app.php`](files/wp-content/mu-plugins/panelalpha-app.php)
- `panelalpha-app.sh` — [`overrides/app.sh`](overrides/app.sh)