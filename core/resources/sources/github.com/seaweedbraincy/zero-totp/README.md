# Zero-TOTP

Zero-knowledge encrypted TOTP (2FA) vault. Angular SPA + Flask/Connexion API +
MariaDB. Vault contents are encrypted client-side from the user's passphrase;
the server only ever stores ciphertext.

## Strategy

Full-replacement `overrides/docker-compose.yml` on the project's **official
prebuilt images** (`ghcr.io/seaweedbraincy/zero-totp-{api,frontend}:2.1.0`) —
the repository ships no runnable root compose file, only `api/Dockerfile`,
`frontend/Dockerfile` and an `e2e-tests/` stack. Single source: both images are
built from this one repository upstream; nothing is grafted from a second repo.

Four services:

- **web** (`nginx:alpine`) — the only published port (80). The frontend image's
  own nginx serves the SPA but does not proxy `/api` (its CSP is
  `connect-src 'self'`) and the SPA calls its API same-origin, so this nginx
  joins them on one origin: `/` to the SPA, `/api/` to the API.
- **frontend** — the SPA image, internal only.
- **api** — the Flask API. `command` renders `/api/config/config.yml` from the
  environment (the config dir is not baked into the image), then hands off to
  the image's entrypoint with `auto-upgrade` so alembic migrates on boot.
- **database** — `mariadb:11` on a named volume. The API's `mysqlclient` driver
  requires MySQL/MariaDB; the account's own MySQL is not reachable under the
  compose strategy, so a sidecar is used.

## Secrets and persistence

`hooks/prepare.sh` generates the flask session key, the server-side encryption
key and the DB password **once** into `~/.panelalpha/zero-totp/secrets.env`
(0600) and reuses them on every redeploy — regenerating the encryption key makes
server-encrypted columns unreadable, a new flask key logs everyone out, a new DB
password locks the app out of its volume. It copies them to `~/project/.env`
each deploy for compose interpolation.

The `domain` in config.yml comes from `PUBLIC_URL`, left at `http://localhost`
in the compose so the engine rewrites it to the account's real https origin.

The RSA vault-signing keypair the API generates on first boot lives on its own
named volume (`zt_secret`) so vault signatures survive a rebuild; the DB is on
`zt_db`.

## Onboarding

Self-contained email/password signup (`signup_enabled: true`). No operator OAuth
or SMTP is required: Google Drive backup and email confirmation are optional and
stay off. Zero-knowledge, so the account owner self-registers through the web UI
— no server-side admin can produce a usable vault, so no user is seeded and no
`app.sh` is shipped.
