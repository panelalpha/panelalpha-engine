# Pad (github.com/perpetualsoftware/pad)

Project management / issue tracking for humans and AI agents. One Go server
with the web UI embedded (:7777), PostgreSQL and Redis.

## Why a recipe

The repository's `docker-compose.yml` builds Pad from source and requires
`PAD_ENCRYPTION_KEY` to be 64 hex characters. The engine fills required
secrets with 48 characters, so the plain deploy crash-loops with
`encryption key must be a 64-character hex string ... len=48`.

## What the recipe does

- `overrides/docker-compose.yml`: upstream's Pad + PostgreSQL + Redis, on the
  published `ghcr.io/perpetualsoftware/pad:0.16.1` image instead of a
  from-source build. `PAD_URL` is the site's address.
- `hooks/prepare.sh`: writes `PAD_ENCRYPTION_KEY` (`openssl rand -hex 32`)
  once to `~/.panelalpha/pad/encryption.env` (0600) and reuses it on every
  redeploy - it encrypts stored data, so it must never change.
- `PAD_DB_PASSWORD` is generated per account by the engine and stays stable.
- Data on the `pad-data`, `pg-data` and `redis-data` volumes.
- A no-op `ready` service waits for Pad's `/api/v1/health`.

First visit opens Pad's own first-admin setup, as upstream ships it.
`PAD_TRUSTED_PROXIES` is deliberately not set: the account's proxy keeps a
client-supplied `X-Forwarded-For`.
