# Hoppscotch (github.com/hoppscotch/hoppscotch)

Open-source API development platform, Community Edition: the web app, the
self-host admin dashboard and the NestJS backend in one AIO image, PostgreSQL.

## What the recipe does

- `overrides/docker-compose.yml` runs `hoppscotch/hoppscotch:2026.8.2` with
  `ENABLE_SUBPATH_BASED_ACCESS=true` on port 8000: `/` app, `/admin` dashboard,
  `/backend` API. The `VITE_*` URLs and `WHITELISTED_ORIGINS` are the site's
  address. `postgres:15` keeps its data on the `pgdata` volume.
- `migrate` (same image, one-shot) runs `prisma migrate deploy` before the app
  starts. `init` (one-shot, `files/hoppscotch-init.sh`) runs the backend's
  first boot, which fills `infra_config` and stops itself by design; on later
  boots it sees `/ping` answer and exits at once. `ready` makes `compose up -d` wait for `/backend/ping`.
- `hooks/prepare.sh` writes `DATA_ENCRYPTION_KEY` and the database password
  once to `~/.panelalpha/hoppscotch/secrets.env`.

After the first deploy open `/admin`: the onboarding screen configures the
sign-in providers (email needs SMTP, or GitHub/Google/Microsoft OAuth apps).
The repository's own compose file is not used: every service is behind a
profile and builds from source.
