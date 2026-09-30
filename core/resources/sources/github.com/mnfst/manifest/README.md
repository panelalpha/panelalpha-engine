# Manifest (github.com/mnfst/manifest)

Open-source LLM gateway/router: dashboard and OpenAI-compatible API on port
2099, PostgreSQL for state.

## Deploying

The first visit opens Manifest's own `/setup` wizard, where the first account
becomes the admin, as upstream ships it. Optional features (email, OAuth
sign-in, S3 recordings) take the variables listed in upstream's
`docker/docker-compose.yml`; add them to the compose `environment:` as needed.

## What the recipe does

- `hooks/prepare.sh` generates `BETTER_AUTH_SECRET` and the Postgres password
  once into `~/.panelalpha/manifest/{app,db}.env` (0600). The secret also
  encrypts stored provider keys, so it must survive redeploys.
- `overrides/docker-compose.yml` runs `manifestdotbuild/manifest:6.27.0` with
  `postgres:16-alpine`, database and request recordings on named volumes,
  `BETTER_AUTH_URL` set to the site's address. `ready` makes `compose up -d`
  wait for `/api/v1/health`.
- Without it the engine builds the root `Dockerfile.heroku` (the image alone,
  no database) and the app never answers.
