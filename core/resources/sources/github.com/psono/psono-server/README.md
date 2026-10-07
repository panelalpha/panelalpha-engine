# Psono (github.com/psono/psono-server)

Team password manager. `psono-server` is the Django REST API only; the web
client and admin portal are separate projects.

Plain deploy: the `django` recipe builds the checkout and the container never
answers (no `settings.yaml`, no client).

## What the recipe does

- `overrides/docker-compose.yml`: upstream's single-image deployment,
  `psono/psono-combo:7.4.4-4.8.2-1.10.0` (server 7.4.4 = the checkout's
  release, web client 4.8.2 at `/`, admin portal 1.10.0 at `/portal`, API at
  `/server`, nginx + daphne, `migrate` on every start), and `postgres:17-alpine`.
- Configuration through the image's `PSONO_*` environment variables:
  `HOST_URL`/`WEB_CLIENT_URL` from `${PA_PUBLIC_URL}`, `ALLOWED_DOMAINS` =
  the site host (usernames are `<name>@<host>`), and the client/portal
  `config.json` pointing at `${PA_PUBLIC_URL}/server`.
- `PSONO_NUM_PROXIES=2`: the per-IP throttles (login 48/day, registration
  20/day) otherwise see every visitor as 127.0.0.1 (the image's own nginx).
  The engine vhost appends the visitor and the image's nginx appends the
  vhost, so the visitor is the 2nd entry from the right; a client-supplied
  `X-Forwarded-For` stays in front of it and is ignored.
- `hooks/prepare.sh` writes what `manage.py generateserverkeys` would print
  (`SECRET_KEY`, `ACTIVATION_LINK_SECRET`, `DB_SECRET`, `EMAIL_SECRET_SALT`,
  the NaCl `PRIVATE_KEY`/`PUBLIC_KEY` as raw X25519 hex) plus the database
  password, once, to `~/.panelalpha/psono/psono.env`. The account has no
  python, so openssl does it.
- Healthcheck on the static client page: the image's own
  `/server/healthcheck/` is throttled to 61/hour per IP. A no-op `ready`
  service waits for it so the deploy ends when the site answers.

## First run

Upstream's: registration is open in the client. E-mail is not configured, so
account activation mails are not sent; users can be created with
`docker compose -p project exec app python3 /root/psono/manage.py createuser <name>@<host> <password> <email>`.
