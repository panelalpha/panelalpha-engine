# RecipeSage (github.com/julianpoy/recipesage)

Recipe keeper, meal planner and shopping lists. Runs upstream's self-host stack
(github.com/julianpoy/recipesage-selfhost): nginx proxy on :80 in front of the
static frontend, the Node API (`/api`) and Pushpin (websockets), with
PostgreSQL and Valkey.

## Deploying

Nothing is required: open the site and register the first account
(registration is open, as upstream ships it).

Optional project environment variables: `AI_API_KEY` (plus `AI_PROVIDER`,
`AI_API_BASE_URL`, `AI_MODEL_*`) for the AI import features, `SCRAPFLY_API_KEY`,
`GROCERY_CATEGORIZER_URL`.

## What the recipe does

- `overrides/docker-compose.yml` replaces the repo's development compose with
  the self-host compose on the current release images (api/static v4.0.16,
  proxy 2026-08-16). `API_PUBLIC_BASE_URL` is `${PA_PUBLIC_URL}/api`.
- The database password and `GRIP_KEY` are required compose variables the
  engine generates once per account.
- `postgresdata` and `apimedia` (uploaded images) are named volumes.
- `ready` waits for the API, which runs migrations and reindexes on every start.
