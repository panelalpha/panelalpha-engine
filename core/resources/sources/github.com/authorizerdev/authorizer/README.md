# Authorizer (github.com/authorizerdev/authorizer)

Authentication and authorization server: login page at `/app/`, admin
dashboard at `/dashboard/`, OIDC discovery at `/.well-known/openid-configuration`.

## Deploying

Deploy as is. The engine generates `AUTHORIZER_ADMIN_SECRET`, the admin
dashboard password, and returns it from `GET /projects/{name}/app-credentials`
(MCP `app_credentials_get`). A project env var with that name, set before the
first deploy, is used instead.

## What the recipe does

- `overrides/docker-compose.yml` runs `quay.io/authorizer/authorizer:2.4.1`
  on port 8080. Authorizer v2 takes CLI flags only, so the entrypoint builds
  them from env; SQLite is on the named volume `data`.
- `hooks/prepare.sh` generates the client ID, client secret, JWT secret and
  encryption key once into `~/.panelalpha/authorizer/secrets.env`.
- The admin secret reaches the app through
  `env_file: ../.panelalpha/app-credentials.env`; `ready` makes
  `compose up -d` wait for `/healthz`.
