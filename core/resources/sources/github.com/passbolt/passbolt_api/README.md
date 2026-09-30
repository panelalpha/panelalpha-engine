# Passbolt CE (github.com/passbolt/passbolt_api)

Team password manager (CakePHP), run from upstream's release image.

## What the recipe does

- `overrides/docker-compose.yml` runs `passbolt/passbolt:5.16.0-1-ce-non-root`
  on port 8080 with MariaDB 11.4, as passbolt_docker's `docker-compose-ce.yaml`.
  `APP_FULL_BASE_URL` is the account's public URL; TLS ends at the engine proxy
  (`PASSBOLT_SSL_FORCE=false`).
- The image generates the server GPG key and JWT keypair on first boot into the
  named volumes `gpg` and `jwt`, and installs (first boot) or migrates the
  schema. The server key fingerprint survives redeploys.
- `hooks/prepare.sh` writes the database password once to
  `~/.panelalpha/passbolt/db.env`.
- `ready` makes `compose up -d` wait for `/healthcheck/status.json`.

The plain php-platform deploy of this checkout crash-loops (engine#423): the
account uid has no passwd entry and Passbolt calls `posix_getpwuid()`.

## First run

Upstream's own: register the first administrator from the container, e.g.
`docker compose -p project exec app /usr/share/php/passbolt/bin/cake passbolt register_user -u you@example.com -f First -l Last -r admin`,
then open the link it prints with the Passbolt browser extension installed.
