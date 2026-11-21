# Keycloak (github.com/keycloak/keycloak)

Identity and access management: SSO, OIDC, SAML, OAuth2 (Java/Quarkus).

## Deploying

Deploy as is, open `/admin/` and sign in with the login the engine generates
(`KC_BOOTSTRAP_ADMIN_USERNAME`, default `admin`, and
`KC_BOOTSTRAP_ADMIN_PASSWORD`): read it with `GET /projects/{name}/app-credentials`
(MCP `app_credentials_get`). Project environment variables of the same names
take its place. Keycloak reads them only while the master realm has no admin,
so a password changed in Keycloak is kept. Keycloak marks this admin as temporary and suggests
creating a permanent one; that is upstream's own flow.

## What the recipe does

- The repository is Keycloak's Maven source tree; a plain deploy built it and
  failed in `keycloak-js` (`'pnpm build' failed`).
- `overrides/docker-compose.yml` runs `quay.io/keycloak/keycloak:26.7.4` in
  production mode (`start`) on port 8080 with HTTP enabled behind the engine's
  TLS proxy (`KC_PROXY_HEADERS=xforwarded`, `KC_HOSTNAME=${PA_PUBLIC_URL}`),
  and `postgres:17-alpine` on the `db` volume.
- `hooks/prepare.sh` generates the database password once into
  `~/.panelalpha/keycloak/` (0600).
- The engine's login reaches Keycloak as `env_file`
  (`../.panelalpha/app-credentials.env`). Before it, the recipe required both
  variables as project env vars and failed the deploy without them, so a deploy
  that sets none (PanelAlpha Go's previews) never started.
- `ready` makes `compose up -d` wait for `/health/ready` on the management
  port.

## Memory

`app` 1.5 GB limit (about 510 MB used idle), `db` 256 MB. `start` without
`--optimized` re-augments at every boot, so a start takes about a minute.
