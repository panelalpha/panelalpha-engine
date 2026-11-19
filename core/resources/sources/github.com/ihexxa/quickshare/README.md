# QuickShare (github.com/ihexxa/quickshare)

File sharing between devices: a Go server with an embedded web UI and SQLite,
on port 8686.

## Deploying

Deploy as is, open the site and sign in with the login the engine generates
(`DEFAULTADMIN`, default `admin`, and `DEFAULTADMINPWD`): read it with
`GET /projects/{name}/app-credentials` (MCP `app_credentials_get`). The login
form has a captcha (upstream default).

QuickShare reads both only on first start, while `quickshare.sqlite` does not
exist yet, so a password changed in the app is never overwritten by a restart
or a redeploy. Without a password it used to generate one and print
`password is generated: ...` only to the container's log, which nobody
deploying it was told.

## What the recipe does

- `overrides/docker-compose.yml` runs the release image
  `hexxa/quickshare:v0.11.4` instead of building the repo's Dockerfile (yarn
  plus webpack plus Go from source, which failed in the plain deploy).
- Uploaded files and `quickshare.sqlite` live on the `quickshare-root` volume
  and survive a rebuild.
- The app service reads `../.panelalpha/app-credentials.env` (`env_file:`),
  where the engine writes `DEFAULTADMIN` / `DEFAULTADMINPWD`.
- A `ready` gate holds `compose up -d` until the server listens.
