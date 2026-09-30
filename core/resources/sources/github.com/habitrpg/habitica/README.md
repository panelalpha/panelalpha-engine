# Habitica (github.com/HabitRPG/habitica)

Habit tracker that plays like an RPG. One Node process on :3000 serves the
Express API (`/api/v3`, `/api/v4`) and the built Vue client; MongoDB 7 runs as a
single-member replica set. Users register on the landing page.

## Deploying

Nothing is required to start. Upstream's `config.json.example` is the base
configuration (payments, OAuth, e-mail and analytics keys are placeholders);
any of its keys can be overridden with a project environment variable of the
same name.

## What the recipe does

- `overrides/docker-compose.yml` builds the repository's own `Dockerfile-Dev`
  (npm install, client build, `gulp build:prod`) and runs
  `node ./website/transpiled-babel/index.js` (the Procfile's command) with
  `NODE_ENV=production`, `BASE_URL` set to the site's address, and `mongo:7.0`
  with `/data/db` on the named volume `mongo-data`.
- `hooks/prepare.sh` copies `config.json.example` to `config.json` (upstream's
  setup step; the client build fails without it) and generates
  `SESSION_SECRET` / `SESSION_SECRET_KEY` once into
  `~/.panelalpha/habitica/secrets.env`.
- The build fits in a 2500 MB account; the running app uses about 370 MiB.
