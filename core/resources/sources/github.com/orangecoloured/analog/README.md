# ANALOG (github.com/orangecoloured/analog)

Minimal event analytics: `POST /api/events` with `{"event":"name"}` counts an
event, the dashboard at `/` charts them. No published image, so the recipe
builds the repository's own Dockerfile (it copies only `src`, `public` and the
build files, so the engine's `.env` is not baked in).

- `app`: the built image, `ANALOG_STATIC_SERVER=true`, port 8080, Redis store.
- `redis`: `redis:8.2-alpine` with `--appendonly yes`, data on `redis-data`.
  Redis rather than SQLite: the SQLite adapter uses `@libsql/client/web`,
  which accepts only remote libsql/http URLs, not a local `file:`.
- Optional project env: `ANALOG_TOKEN` protects the dashboard and GET API
  (`/?token=<value>`); with `ANALOG_PROTECT_POST=true` it also protects POST.
