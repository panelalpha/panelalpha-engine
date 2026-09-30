# Nutify (github.com/dartsteven/nutify)

UPS monitoring dashboard on top of NUT (Flask + Socket.IO, SQLite, :5050).

Plain deploy: the repository's `docker-compose.yaml` runs with
`cap_drop: ALL` plus a `cap_add` list. The engine keeps the drop and strips the
add (engine#349), so the entrypoint fails with `chown: changing ownership of
'/etc/nut': Operation not permitted` and `settings.txt: Permission denied`,
and the container loops on `Restarting (1)`.

## What the recipe does

- `overrides/docker-compose.yml`: runs `dartsteven/nutify:0.3.0-amd64` (the
  checkout's v0.3.0) with default capabilities. There is no `/dev/bus/usb` or
  `/run/udev` passthrough, only port 5050 is published, and nothing is
  published for 3493/443.
- Named volumes for `/app/nutify/instance` (SQLite DB), `/app/nutify/logs` and
  `/etc/nut` (the NUT config written by the wizard), so the configuration and
  users survive a redeploy.
- `hooks/prepare.sh` writes `SECRET_KEY` once to `~/.panelalpha/nutify/app.env`
  (it encrypts stored provider credentials).
- `SOCKETIO_ALLOWED_ORIGINS` is set to the site address.
- The healthcheck probes `/` for any answer below 500, because `/health` only
  exists once setup has finished. A no-op `ready` service waits for it.

## First run

This is upstream's setup wizard (`/nut_config/welcome`). A hosted account has
no UPS attached, so use the "network client" mode with a remote NUT server.
The wizard's "dashboard admin" fields create the first login.

Upstream quirks found while testing (not caused by the recipe):
- The fallback `/auth/setup` form posts without a CSRF token and is rejected
  (`flask_wtf.csrf - The CSRF token is missing`). Create the admin in the
  wizard instead.
- The wizard's `ups.conf` template adds `pollfreq`, which `dummy-ups` rejects.
- `/internal/ws_event` treats the first `X-Forwarded-For` entry as loopback
  proof (see engine#319).
