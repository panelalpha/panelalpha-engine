# Authorizer (github.com/authorizerdev/authorizer)

Authentication and authorization server: login page at `/app/`, admin
dashboard at `/dashboard/`, OIDC discovery at `/.well-known/openid-configuration`.

## Deploying

Set one project environment variable:

- `AUTHORIZER_ADMIN_SECRET`: the admin dashboard password.

Without it the deploy fails with
`authorizer: missing project environment variable AUTHORIZER_ADMIN_SECRET`.

## What the recipe does

- `overrides/docker-compose.yml` runs `quay.io/authorizer/authorizer:2.4.1`
  on port 8080. Authorizer v2 takes CLI flags only, so the entrypoint builds
  them from env; SQLite is on the named volume `data`.
- `hooks/prepare.sh` generates the client ID, client secret, JWT secret and
  encryption key once into `~/.panelalpha/authorizer/secrets.env`.
- `env-check` fails the deploy while the admin secret is unset; `ready` makes
  `compose up -d` wait for `/healthz`.
