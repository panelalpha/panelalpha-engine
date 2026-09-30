# OpenSlides (github.com/openslides/openslides)

Presentation and assembly system. The repository is a meta-repo of submodules
with no compose file; upstream deploys with the `osmanage` tool and its compose
template.

## Deploying

Nothing to set. Open the site and log in with the login the engine generated,
returned by `GET /projects/{name}/app-credentials` (MCP `app_credentials_get`):
`superadmin` and its password.

## What the recipe does

- `overrides/docker-compose.yml` is upstream's `docker-compose.yml.tmpl`
  (openslides-cli `contrib/`) rendered for the `4.3.4` images, local HTTPS off,
  the backendManage port not forwarded, one default network.
- `hooks/prepare.sh` generates the boot secrets once into
  `~/.panelalpha/openslides/` (0600 in 0700), the engine the superadmin
  password (`credentials:`); the one-shot `secrets` service copies them into a volume
  mounted at `/run/secrets` in every service.
- `backendManage` runs with `OPENSLIDES_BACKEND_CREATE_INITIAL_DATA=1`, as in
  upstream's `example-config.yml`.
- `ready` (no-op) holds the deploy until backendManage, backendAction,
  autoupdate and the proxy report healthy.
- PostgreSQL is on the `postgres-data` volume; Redis keeps nothing (`--save ""`,
  upstream's setting).
