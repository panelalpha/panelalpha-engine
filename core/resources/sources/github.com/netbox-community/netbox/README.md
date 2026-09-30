# NetBox (github.com/netbox-community/netbox)

Network source of truth (IPAM, DCIM, circuits, virtualization), Django.

Plain deploy: the `django` recipe builds the checkout and the container
restart-loops with `ImproperlyConfigured: Specified configuration module
(netbox.configuration) not found`. The repository ships no configuration; the
supported container is the netbox-docker image, whose baked-in
`configuration.py` reads everything from the environment.

## What the recipe does

- `overrides/docker-compose.yml`: netbox-docker's stack on
  `netboxcommunity/netbox:v4.7.1-5.1.1` (NetBox 4.7.1, the current release):
  `netbox` (granian on :8080, the only published port), `netbox-worker`
  (rqworker), `postgres:18-alpine`, and one `valkey:9.1-alpine` serving the
  task queue (db 0, AOF on) and the cache (db 1) instead of upstream's two.
- `CSRF_TRUSTED_ORIGINS=${PA_PUBLIC_URL}`: without it Django refuses the
  login POST from the https origin.
- `GRANIAN_WORKERS=2` (image default 4) to fit a 2.5 GB account.
- `hooks/prepare.sh` writes `SECRET_KEY`, `API_TOKEN_PEPPER_1` and the
  Postgres/Valkey passwords once to `~/.panelalpha/netbox/netbox.env`.
- Data on named volumes: database, Valkey, media, reports, scripts.
- The worker `depends_on` netbox being healthy (upstream's own wiring), so
  `compose up -d` returns only after the first-boot migrations and the site
  answers `/login/`.

## First run

Upstream's: `SKIP_SUPERUSER=true`, so no account exists. Create the admin
with `docker compose -p project exec netbox /opt/netbox/netbox/manage.py createsuperuser`.
