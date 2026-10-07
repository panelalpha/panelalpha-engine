# Anubis (github.com/techarohq/anubis)

A proof-of-work challenge in front of a website: browsers solve a short
challenge once and get a signed pass cookie, scrapers that do not run
JavaScript are kept out.

## Deploying

A fresh deploy works with no settings: Anubis protects a bundled placeholder
page, so the challenge can be seen working. To protect a real site, set these
project environment variables and redeploy:

- `TARGET`: the address Anubis forwards passing requests to, for example
  `https://www.example.com` or another project's site address.
- `TARGET_HOST`: the `Host` header to send there, when `TARGET` is a
  name-based virtual host that expects its own name (often the same host
  as in `TARGET`).
- `DIFFICULTY` (default `4`): the number of leading zeroes the challenge
  needs. Each step up makes it about sixteen times slower for visitors.

## What the recipe does

- `overrides/docker-compose.yml` runs `ghcr.io/techarohq/anubis:v1.27.0` on
  port 8923 with `SERVE_ROBOTS_TXT=true`, and an `nginx:alpine` service
  serving `files/panelalpha/anubis/index.html` as the default target.
- `hooks/prepare.sh` generates the ed25519 signing key once into
  `~/.panelalpha/anubis/key.env` (0600). A new key would invalidate every pass
  already issued, so it is kept across redeploys.
- The client address comes from the hosting proxy's `X-Real-IP` header.

The repository's Dockerfile and Go build are not used: the challenge assets
are produced by a Makefile that needs Go, Node, Rust (wasm), binaryen, zstd and
brotli.
