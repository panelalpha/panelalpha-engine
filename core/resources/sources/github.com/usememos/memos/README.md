# github.com/usememos/memos

Memos — a self-hosted note-taking service. A Go server with the React UI
embedded in the binary, storing notes in SQLite by default.

## Strategy: compose-REPLACE, published image

Without a recipe the repository detects as Go and the engine builds it on the
host. That build does not give a working Memos:

- `server/frontend/dist` is embedded into the binary, and upstream commits it
  only as a placeholder page ("No embeddable frontend found"); the real UI comes
  from a separate `web/` build that upstream's Dockerfile runs first.
- The binary refuses to start without build metadata
  (`missing build version: use go run -buildvcs=true ./cmd/memos or inject
  internal/version.Version with -ldflags`), which upstream's Dockerfile injects.
- The Go compile peaked at 4137 MiB on a 15 GB host, above the build
  container's limit on any host with less than ~12.5 GB RAM.

`overrides/docker-compose.yml` runs `neosmemo/memos:0.31.0` instead, on port
5230. Notes, attachments and the SQLite database live in the named volume
`memos-data` on `/var/opt/memos`, which survives a redeploy. The image's
entrypoint fixes the volume's ownership and drops to its own non-root user.

`MEMOS_INSTANCE_URL` is set to `http://localhost` and rewritten to the
account's public https URL by `ComposePlaceholders`.

## First run

The first account registered in the web UI becomes the instance's host (admin).
