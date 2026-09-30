# Keygen CE (github.com/keygen-sh/keygen-api)

Software licensing and distribution API (Rails). There is no web UI: the site
is the JSON:API at `/v1/...` (`/` answers a JSON 404, `/v1/ping` and
`/v1/health` answer). Upstream images `keygen/api`, web on :3000 plus a Sidekiq
worker, PostgreSQL and Redis.

## Deploying

Required project env vars (the deploy fails naming whichever is missing):

- `KEYGEN_ADMIN_EMAIL` - the admin user of the single account
- `KEYGEN_ADMIN_PASSWORD` - at least 6 characters

They are read once, by upstream's `rails keygen:setup` on the first deploy;
changing them later does not change the admin. Get an admin token with
`curl -u EMAIL:PASSWORD -X POST https://<domain>/v1/tokens`. Singleplayer mode
needs no account ID in the URL; the generated ID is in the `setup` service's
log of the first deploy.

Optional: `SENDGRID_API_KEY` (mail), `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`,
`AWS_BUCKET`, `AWS_REGION` (release artifacts).

## What the recipe does

- `overrides/docker-compose.yml` is upstream's `compose.yaml` without Caddy
  (the engine terminates TLS), image pinned to `v1.7.2`, `KEYGEN_HOST` set to
  the site's host.
- `hooks/prepare.sh` writes `SECRET_KEY_BASE`, the three `ENCRYPTION_*` keys,
  the PostgreSQL password and `KEYGEN_ACCOUNT_ID` once into `~/.panelalpha/keygen/`.
- `files/keygen-env-check.sh` is the one-shot check for the two admin vars.
- `files/keygen-setup.sh` runs `keygen:setup` only while the account does not
  exist (setup starts with `db:schema:load`, which would wipe a live
  database), and `db:migrate` on every later deploy.
- A `ready` service waits for `/v1/health`.
- Data: named volumes `postgres`, `redis`, `keygen`.
