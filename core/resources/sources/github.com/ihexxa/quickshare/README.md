# QuickShare (github.com/ihexxa/quickshare)

File sharing between devices: a Go server with an embedded web UI and SQLite,
on port 8686.

## Deploying

Nothing is required. On first start QuickShare creates its admin account:

- `DEFAULTADMIN` (project env var) is the admin's name, default `admin`.
- `DEFAULTADMINPWD` is its password. When unset, the app generates one and
  prints `password is generated: ...` to the app container's log.

Both are read only when the database does not exist yet; changing them later
has no effect. The login form has a captcha (upstream default).

## What the recipe does

- `overrides/docker-compose.yml` runs the release image
  `hexxa/quickshare:v0.11.4` instead of building the repo's Dockerfile (yarn
  plus webpack plus Go from source, which failed in the plain deploy).
- Uploaded files and `quickshare.sqlite` live on the `quickshare-root` volume
  and survive a rebuild.
- A `ready` gate holds `compose up -d` until the server listens.
