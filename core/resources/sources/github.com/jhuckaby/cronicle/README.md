# Cronicle (github.com/jhuckaby/cronicle)

Multi-server task scheduler and runner with a web UI (Node.js, JSON files on
local disk).

## Deploying

Nothing is required. The site opens Cronicle's login; the first-run
administrator is upstream's default `admin` / `admin`, created by the storage
setup; change it in the app. The server takes up to a minute after each start
to elect itself primary; until then the UI shows it waiting.

Jobs run inside the app container (`node:22-bookworm-slim`), so a Shell
Script event has what that image has. It has no `ps`, so per-job CPU and
memory graphs stay empty and the log periodically shows `Failed to exec ps`;
jobs themselves run normally.

## What the recipe does

- Express platform with upstream's install steps: `npm ci`, then
  `node bin/build.js dist` (writes `conf/`, bundles the web UI).
- Start: `node bin/storage-cli.js setup` (upstream's `control.sh setup`; it
  refuses to run a second time), then `node lib/main.js` in the foreground on
  port 3000 via Cronicle's own `CRONICLE_*` env overrides. The serve command
  has its own id because under `serve` the node platform replaces it with
  package.json's `bin`.
- `data/`, `logs/` and `queue/` on named volumes.
- Hostname pinned to `cronicle` (container and `$HOSTNAME`): setup writes it
  into the Primary Group regexp, so a changed name would leave no server
  eligible to become primary after a redeploy.
