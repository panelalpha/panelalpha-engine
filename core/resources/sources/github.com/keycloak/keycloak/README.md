# Keycloak (github.com/keycloak/keycloak)

Identity and access management: SSO, OIDC, SAML, OAuth2 (Java/Quarkus).

## Deploying

Set two project environment variables, the first admin of the `master` realm:

- `KC_BOOTSTRAP_ADMIN_USERNAME`
- `KC_BOOTSTRAP_ADMIN_PASSWORD` (write any `$` as `$$`)

Keycloak reads them only while the master realm has no admin. Without them the
deploy fails with:

```
keycloak: missing project environment variable(s): KC_BOOTSTRAP_ADMIN_USERNAME KC_BOOTSTRAP_ADMIN_PASSWORD. ...
```

Sign in at `/admin/`. Keycloak marks this admin as temporary and suggests
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
- `env-check` (one-shot) fails the deploy naming a missing variable; `ready`
  makes `compose up -d` wait for `/health/ready` on the management port.

## Memory

`app` 1.5 GB limit (about 510 MB used idle), `db` 256 MB. `start` without
`--optimized` re-augments at every boot, so a start takes about a minute.
