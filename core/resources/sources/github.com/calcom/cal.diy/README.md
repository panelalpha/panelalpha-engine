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

## PanelAlpha Engine snippets

- `panelalpha-before-clone-validation.sh` — [`hooks/precheck.sh`](hooks/precheck.sh)
- `panelalpha-after-clone.sh` — [`hooks/prepare.sh`](hooks/prepare.sh)
- `docker-compose.yml` — [`overrides/docker-compose.yml`](overrides/docker-compose.yml)
- `docker/panelalpha-cli.mjs` — [`files/docker/panelalpha-cli.mjs`](files/docker/panelalpha-cli.mjs)
- `panelalpha-app.sh` — [`overrides/app.sh`](overrides/app.sh)