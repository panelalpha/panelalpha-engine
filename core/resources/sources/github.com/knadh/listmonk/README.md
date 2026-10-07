# listmonk for PanelAlpha Engine

listmonk ships its own `docker-compose.yml`, and the recipe uses it with one
change: the Super Admin is created before listmonk listens on its port.

Upstream creates the Super Admin only when `LISTMONK_ADMIN_USER` and
`LISTMONK_ADMIN_PASSWORD` are set on the first start. Its compose file leaves
them empty, so a fresh install serves "This is a fresh install. Pick a username
and password for the Super Admin account." at `/admin/login` to whoever opens it
first.

- The engine generates the login (`credentials:` in `panelalpha.yaml`), keeps it
  and returns it from `GET /projects/{name}/app-credentials` (MCP
  `app_credentials_get`); every deploy writes it to
  `~/.panelalpha/app-credentials.env`. An account deployed before this keeps its
  password from `~/.panelalpha/listmonk/admin.env` (`adopt_from`).
- `overrides/docker-compose.override.yml` hands that file to the `app` service
  as `PA_ADMIN_*` (upstream's `environment:` sets the `LISTMONK_*` names to
  empty, and `environment:` beats `env_file:`) and runs
  `files/panelalpha/listmonk/start.sh` instead of upstream's command.
- `start.sh` runs upstream's own `--install --idempotent` with those credentials
  and `--upgrade`, then starts listmonk on `127.0.0.1:9099`. If that still shows
  the first-time setup form (a database installed before this recipe, with no
  user), it submits it there, where nothing outside the container can reach it.
  Only then does it `exec` the real server on `:9000`. If listmonk does not
  answer, or the form is still there afterwards, it exits instead of serving.
- A database that already has a user is not touched, and the returned login then
  describes an account that was never created.

App management queries go directly to the PostgreSQL container using the
credentials already present in the `db` container's environment
(`$POSTGRES_USER`, `$POSTGRES_PASSWORD`, `$POSTGRES_DB`). `install` gives the
panel's account the Super Admin role directly in the database (created, or its
email and password set), because the setup form it used to post to no longer
exists by the time it runs.

SSO is handled by the PanelAlpha Engine itself: `users:sso` inserts a session
row into listmonk's database and returns the cookie name/value to the engine.
The engine stores a short-lived single-use token, then redirects the browser to
`/panelalpha-sso?token=<engine-token>` which is intercepted by the nginx-proxy,
sets the cookie on the app's domain, and forwards the browser to `/admin`.  No
extra containers or sidecars are needed.

## PanelAlpha Engine snippets

- `panelalpha-app.sh` — [`overrides/app.sh`](overrides/app.sh)