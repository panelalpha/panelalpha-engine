# Websurfx (github.com/neon-mmd/websurfx)

A privacy-respecting metasearch engine (Rust, actix-web) on port 8080. It
queries the upstream engines enabled in `websurfx/config.lua` (DuckDuckGo and
Wikipedia by default) and keeps no data.

## Deploying

Nothing is required. The first deploy compiles the Rust release binary from
the repository's Dockerfile: about 8.5 minutes on a 4-CPU host with a 2000 MB
account. A redeploy of the same commit reuses the account's build cache
(about 20 seconds).

Settings are upstream's `websurfx/config.lua` from the repository; to change
engines, themes or the rate limiter, change that file in the repository.

## What the recipe does

- `overrides/docker-compose.yml` builds the repository's own Dockerfile (its
  default in-memory cache). The image is `FROM scratch` and reads its config
  from `/etc/xdg/websurfx/`.
- A one-shot busybox service copies `websurfx/` (`config.lua`,
  `allowlist.txt`, `blocklist.txt`) into a named volume on every deploy,
  changing only `binding_ip` from `127.0.0.1` to `0.0.0.0`; the app mounts
  that volume read-only.
