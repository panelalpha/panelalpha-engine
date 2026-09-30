# SillyTavern (github.com/sillytavern/sillytavern)

Self-hosted chat frontend for LLM APIs (Node.js, data on disk).

## Deploying

Set two project environment variables, then deploy:

- `SILLYTAVERN_BASICAUTHUSER_USERNAME`
- `SILLYTAVERN_BASICAUTHUSER_PASSWORD`

Open the site and sign in with them (browser basic-auth prompt). Without them
the deploy fails with:

```
sillytavern: missing project environment variable(s): SILLYTAVERN_BASICAUTHUSER_USERNAME SILLYTAVERN_BASICAUTHUSER_PASSWORD. ...
```

## Why

SillyTavern's default `config.yaml` has `whitelistMode: true` (only localhost
and Docker gateways), so behind the engine's proxy every request got 403. With
the whitelist off it exits at start-up ("configuration is insecure (listening to
non-localhost)") unless basic auth, user accounts or the whitelist protect it.
The recipe picks the app's own basic-auth mode, with the login supplied by the
customer.

## What the recipe does

- `overrides/docker-compose.yml` runs `ghcr.io/sillytavern/sillytavern:1.19.0`
  on port 8000; settings come from `SILLYTAVERN_*` env vars, which override
  `config.yaml` (`keyToEnv()` in `src/util.js`).
- `rateLimiting.preferRealIpHeader` is on, so failed-login limits apply per
  visitor (`X-Real-IP` from the proxy) rather than to everyone at once.
- `config/`, `data/`, `plugins/` and third-party extensions are named volumes,
  kept across redeploys.
- `env-check` (same image, one-shot, `files/sillytavern-env-check.sh`) gates the app.
