# Wakapi (github.com/muety/wakapi)

WakaTime-compatible coding statistics backend. One Go binary (UI + API) on
`:3000`, SQLite in `/data`.

- `overrides/docker-compose.yml` runs `n1try/wakapi:2.18.0` (upstream's image,
  also published as `ghcr.io/muety/wakapi`) instead of the repo's source-build
  PostgreSQL compose. `/data` is a named volume, so stats survive redeploys.
- `hooks/prepare.sh` writes `~/.panelalpha/wakapi/wakapi.env`
  (`WAKAPI_PASSWORD_SALT`) once. The owner's login (`admin` + a generated
  password) is declared under `credentials:` in `panelalpha.yaml`; the engine
  generates it and writes `~/.panelalpha/app-credentials.env`.
- Signup is off. `files/panelalpha-seed.sh` (the `seed` service) creates the
  owner once through a one-time invite code, then exits; later deploys see a
  user and do nothing.

Owner login: `GET /projects/{name}/app-credentials` (MCP `app_credentials_get`)
returns it. Point
a WakaTime plugin at `https://<domain>/api` with the API key from Settings.

Not configured: mail (password reset needs SMTP), OIDC.
