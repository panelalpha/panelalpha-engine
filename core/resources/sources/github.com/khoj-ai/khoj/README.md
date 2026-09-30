# Khoj (github.com/khoj-ai/khoj)

AI second brain: chat, semantic search over your documents, agents and
automations. Server with pgvector, the Terrarium code sandbox and SearXNG.

## Deploying

No project environment variables are needed; 2500 MB is enough (about 1.6 GB
in use: server 1.0 GB, sandbox 0.5 GB). The server runs in anonymous mode, as
upstream's compose ships it. Chat needs an LLM provider: add a chat model in
the admin panel at `/server/admin` (user `username@example.com`, password in
`KHOJ_ADMIN_PASSWORD`, which the engine generates for the account). Until one
is configured the home page stays on "Loading"; document search works without it.

## What the recipe does

- `overrides/docker-compose.yml` is upstream's `docker-compose.yml` without
  the optional `computer` desktop (off by default and needs the Docker socket;
  its VNC port 5900 outranked the app's 42110, so the site answered 502).
- Images pinned: `ghcr.io/khoj-ai/khoj:2.0.0-beta.28` (upstream's `latest` is
  a July 2025 build), Terrarium by digest (only `latest` exists), SearXNG by
  dated tag.
- `KHOJ_DOMAIN=${PA_PUBLIC_HOST}` so Django trusts the site's origin (CSRF)
  and scopes its cookies to it.
- `ready` makes `compose up -d` wait until `/api/health` answers (the server
  loads its embedding models first). Data stays on upstream's named volumes.
