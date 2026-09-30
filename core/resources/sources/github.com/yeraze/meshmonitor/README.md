# MeshMonitor (github.com/yeraze/meshmonitor)

Web dashboard for Meshtastic / MeshCore mesh radio networks. One Node.js
server on `:3001` (plus an internal Apprise API), SQLite in `/data`.

## What the recipe does

- `overrides/docker-compose.yml` replaces the repo's compose (`latest`,
  `NODE_ENV=development`, MQTT 1883 published) with
  `ghcr.io/yeraze/meshmonitor:4.16.1` in production mode: `TRUST_PROXY=1`,
  `COOKIE_SECURE=true`, `ALLOWED_ORIGINS=${PA_PUBLIC_URL}`, and a
  `SESSION_SECRET` generated once into `~/.panelalpha/meshmonitor/app.env`.
  `/data` is a named volume.
- **The default admin never goes public.** MeshMonitor creates its first admin
  as `admin` / `changeme` (hard-coded, no env to seed it). `files/panelalpha-seed.sh`
  runs as the `seed` service before the app publishes its port: it starts the
  server privately, logs in with `changeme` and calls
  `POST /api/auth/change-password` with the password the engine generates
  (`credentials:` in `panelalpha.yaml`), then fails unless `changeme` returns 401.
  On a redeploy it checks the admin hash read-only first and exits without
  starting a second server if `changeme` no longer matches.
- Only the web UI is published. The embedded MQTT broker stays inside the
  account.

## Runtime notes

- The radio node (TCP/serial/BLE) is configured in the UI. Without one the
  dashboard shows the node as disconnected; login, users and settings work.
- Anonymous visitors get the app's default read-only view (dashboard, nodes,
  info). The admin can remove those permissions in the UI. There is no
  self-registration.

## Login

`GET /projects/{name}/app-credentials` (MCP `app_credentials_get`) returns the
login the seed set; a password changed later in the UI is not reflected there.
