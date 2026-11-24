# Cal.diy for PanelAlpha Engine

Cal.diy (formerly Cal.com) ships a `docker-compose.yml` that builds the app from source — too slow and resource-heavy for a standard deployment. The after-clone script replaces it with a minimal compose using the official prebuilt image, generates required secrets, and sets up the database connection.

The Hub image is compiled with `NEXT_PUBLIC_WEBAPP_URL=http://localhost:3000`. At container start, Cal's `start.sh` rewrites that string in `.next` when the runtime value differs — ComposeHarden injects the public vhost as `NEXT_PUBLIC_WEBAPP_URL` / `NEXTAUTH_URL`. Do not pin those keys to localhost in `.env` or compose `environment:` (that skips the rewrite).

## Secrets

`hooks/prepare.sh` generates `POSTGRES_PASSWORD`, `NEXTAUTH_SECRET` and
`CALENDSO_ENCRYPTION_KEY` once into `~/.panelalpha/caldiy/secrets.env` (0600 in
a 0700 dir) and writes `.env` from `.env.example` plus those values on every
deploy; the compose file reads `.env` as `env_file` and interpolates
`POSTGRES_PASSWORD` from it. They cannot live only in `.env`: the engine empties
`~/project` on every deploy. An earlier version generated them into `.env` on
each run, and a rebuild left Cal.diy on `P1000: Authentication failed against
database server` against the Postgres volume created with the old password,
with every page a 500. `CALENDSO_ENCRYPTION_KEY` also decrypts the stored
integration credentials, and `NEXTAUTH_SECRET` signs the session cookies.

## Postgres major version

The database is pinned to `postgres:18`. It followed `postgres` (latest) until
Postgres 19 became latest on 2026-11-24: the volume of every install made
before then holds an 18 cluster in `/var/lib/postgresql/18/docker`, and on the
next deploy 19 refused it (`Error: in 18+, these Docker images are configured
to store database data in a format which is compatible with "pg_ctlcluster"`,
then `service ... did not start (unhealthy)`). Moving to a newer major needs a
`pg_upgrade` of the existing volumes, not only a new tag.

## PanelAlpha Engine snippets

- `panelalpha-before-clone-validation.sh` — [`hooks/precheck.sh`](hooks/precheck.sh)
- `panelalpha-after-clone.sh` — [`hooks/prepare.sh`](hooks/prepare.sh)
- `docker-compose.yml` — [`overrides/docker-compose.yml`](overrides/docker-compose.yml)
- `docker/panelalpha-cli.mjs` — [`files/docker/panelalpha-cli.mjs`](files/docker/panelalpha-cli.mjs)
- `panelalpha-app.sh` — [`overrides/app.sh`](overrides/app.sh)