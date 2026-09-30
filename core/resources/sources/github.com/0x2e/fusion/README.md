# Fusion (github.com/0x2e/fusion)

Lightweight RSS aggregator and reader (Go, SQLite).

## Deploying

Set the project environment variable `FUSION_PASSWORD` (the login password;
write any `$` as `$$`, compose interpolates it). Without it the deploy fails with:

```
fusion: missing project environment variable FUSION_PASSWORD (the login password; ...). Set it, then redeploy.
```

## What the recipe does

- `overrides/docker-compose.yml` runs `ghcr.io/0x2e/fusion:1.2.1` on port 8080
  with `/data/fusion.db` on the named volume `fusion-data` (kept across redeploys).
  The repository's Dockerfile only copies a CI-built binary and cannot be built
  from a clone.
- `env-check` (same image, one-shot) runs `files/fusion-env-check.sh`; the app
  starts only once it passes. `ready` makes `compose up -d` wait for the health check.
- `FUSION_TRUSTED_PROXIES=172.16.0.0/12`: every visitor reaches the app from the
  account's Docker gateway, so without it the login rate limit (10 failures a
  minute) locked out all visitors at once. Gin now takes the last, proxy-added
  `X-Forwarded-For` hop; a spoofed first entry is ignored.
