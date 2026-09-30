# flohmarkt

Federated classifieds (Python/FastAPI) on CouchDB. The repository's Dockerfile
builds a multipurpose image whose default command prints usage and exits, and
there is no root compose file, so the plain deploy crash-looped.

- `overrides/docker-compose.yml`: CouchDB 3.5 + the repo's own image, running
  upstream's idempotent `initdb` before `web` on every start.
- `hooks/prepare.sh`: CouchDB admin password generated once into
  `~/.panelalpha/flohmarkt/db.env` (0600).
- First run: flohmarkt prints `<url>/setup/<key>` in the app log; that is
  upstream's own setup flow (or set `FLOHMARKT_SETUPCODE`). Mail (SMTP) is
  optional and not configured.
