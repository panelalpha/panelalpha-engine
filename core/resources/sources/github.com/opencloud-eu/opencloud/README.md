# OpenCloud (github.com/opencloud-eu/opencloud)

Self-hosted file sync and sharing (Go server with a built-in identity provider
and web UI).

## Deploying

Deploy as is, open the site (it sends you to the identity provider's sign-in)
and sign in with the login the engine generates: `OPENCLOUD_ADMIN_USERNAME`
(always `admin`) and `IDM_ADMIN_PASSWORD`, read with
`GET /projects/{name}/app-credentials` (MCP `app_credentials_get`).

## What the recipe does

- `overrides/docker-compose.yml` runs upstream's release image,
  `opencloudeu/opencloud-rolling:8.0.1`, as upstream's opencloud-compose does
  behind an external proxy: `opencloud init` writes `/etc/opencloud` once,
  then `opencloud server` listens on plain HTTP 8080 with `OC_URL` set to the
  account's https address. Config and data are on named volumes.
- Upstream's first run gives `admin` a random password from `opencloud init`,
  printed only in the container's first log, and there is no sign-up.
- The service reads `~/.panelalpha/app-credentials.env` as `env_file`.
  `IDM_ADMIN_PASSWORD` is OpenCloud's own setting for the admin's password:
  IDM uses it only when it creates its database (`idm.boltdb` missing), on
  the first start, so a password changed in OpenCloud is never overwritten.
  `init` gets it as `--admin-password` too, so `opencloud.yaml` and the
  admin agree and no other password is printed.
- `OPENCLOUD_ADMIN_USERNAME` is only for the API's answer: IDM always names
  the account `admin`.

## Caveats

- An account deployed before this recipe already has its IDM database, whose
  admin keeps the random password `init` printed then (also in
  `opencloud.yaml`, `idm.service_user_passwords.admin_password`); the login
  the engine reports is only the real one on a new install.
