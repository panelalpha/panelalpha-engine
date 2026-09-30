# SparkyFitness (github.com/codewithcj/sparkyfitness)

Food, exercise, water and health tracker with family sharing (React frontend
served by nginx, Node/Express server, PostgreSQL).

## What the recipe does

- The repository root is a pnpm workspace (frontend, server, Garmin service,
  mobile) with no start script: a plain deploy goes to Railpack, which warns
  "No start command detected" and the site serves the PanelAlpha placeholder.
  Upstream's deployment is `docker/docker-compose.prod.yml`.
- `overrides/docker-compose.yml` runs that topology on the published images of
  the current release: `codewithcj/sparkyfitness:v1.7.3` (nginx, port 80,
  proxies `/api` to the server) and `codewithcj/sparkyfitness_server:v1.7.3`
  (port 3010, applies migrations at startup) with `postgres:18.3-alpine`. The
  optional Garmin microservice stays out, as upstream leaves it commented.
- `hooks/prepare.sh` generates once into
  `~/.panelalpha/sparkyfitness/secrets.env` (0600): the owner and app-role
  database passwords, `SPARKY_FITNESS_API_ENCRYPTION_KEY` (64 hex; encrypts
  stored API keys, must never change) and `BETTER_AUTH_SECRET` (base64).
- `SPARKY_FITNESS_FRONTEND_URL` is the site address (auth trusted origin).
- Data: `db_data` (/var/lib/postgresql, the PostgreSQL 18 layout),
  `server_uploads` and `server_backup` are named volumes kept across
  redeploys.
- The frontend starts only once the server's `/api/health` answers, so
  `compose up -d` returns with the app ready.

Sign-up is open and the first account becomes admin, as upstream ships it.
The frontend's nginx rate-limits `/api/auth/` per client address; behind the
engine every visitor shares one address, so heavy concurrent logins can see
429s.
