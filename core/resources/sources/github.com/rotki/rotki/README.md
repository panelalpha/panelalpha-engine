# rotki (github.com/rotki/rotki)

Self-hosted portfolio tracking and accounting. The first page is rotki's own
"create account" / login screen; each rotki user is an encrypted SQLite
database under `/data`.

## What the recipe does

- `overrides/docker-compose.yml` runs `rotki/rotki:v1.44.0` (the current
  release) on port 80 with `/data` and `/logs` on the named volumes
  `rotki-data` and `rotki-logs`, kept across redeploys.
- A one-shot `ready` service waits for the image's own health check, so the
  deploy finishes only once starling reports the backends up.

The repository's `Dockerfile` is not used: it needs the `ROTKI_VERSION` build
argument (`packaging.version.InvalidVersion: Invalid version: ''` without it)
and builds Rust, the frontend and a PyInstaller bundle from source.
