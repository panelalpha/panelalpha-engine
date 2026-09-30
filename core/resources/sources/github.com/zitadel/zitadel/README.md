# ZITADEL (github.com/zitadel/zitadel)

Identity and access management (OIDC, OAuth2, SAML): console at
`/ui/console`, login v2 at `/ui/v2/login`, APIs on the same domain.

## Deploying

No variables are required. The first start creates the instance for the
project's domain and its admin with upstream's defaults: login name
`zitadel-admin@zitadel.<domain>`, password `Password1!`.

## What the recipe does

- `overrides/docker-compose.yml` is upstream's `deploy/compose` in external-TLS
  mode (TLS ends at the engine, `ExternalPort` 443) on `ghcr.io/zitadel/zitadel`
  and `zitadel-login` v4.19.3 with `postgres:17.10-alpine` on a named volume.
- `files/zitadel-caddy.Caddyfile` routes `/` and `/ui/v2/login` to the login UI,
  `/api/*` (prefix stripped) and everything else to the API over h2c, as
  upstream's Traefik labels do. Caddy instead of Traefik because the engine
  drops a traefik service from a compose file (engine#334).
- `hooks/prepare.sh` generates the masterkey, the login cookie secret and the
  Postgres password once into `~/.panelalpha/zitadel/` (0600 files, 0700 dir).
  Keep them: a new masterkey cannot decrypt the existing data.
