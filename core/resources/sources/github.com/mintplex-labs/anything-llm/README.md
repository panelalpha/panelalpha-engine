# AnythingLLM (github.com/Mintplex-Labs/anything-llm)

Document chat and RAG workspace, served on :3001.

## Deploying

No variables are required. The first visitor gets AnythingLLM's own
onboarding: pick an LLM provider (a remote API key or an Ollama URL), then
optionally a password or multi-user mode. The built-in embedder and LanceDB
run inside the container.

## What the recipe does

- `overrides/docker-compose.yml` runs `mintplexlabs/anythingllm:1.16.2`, as
  the upstream Docker quickstart does. Upstream's compose builds from source
  and bind-mounts `./.env` and `../server/storage` out of the checkout, which a
  deploy wipes; without `STORAGE_DIR` the plain build restart-loops.
- `/app/server/storage` is the named volume `anythingllm-storage` (SQLite DB,
  documents, vector cache, downloaded models).
- The app saves every setting, and its generated `SIG_KEY`/`SIG_SALT`, to
  `/app/server/.env`. The entrypoint links that file to
  `/app/server/storage/.env` before running upstream's own entrypoint, so the
  configuration survives a redeploy (the quickstart mounts it from the host
  for the same reason).
- A no-op `ready` service holds `compose up` until `/api/ping` answers.
- Idle memory is about 340 MB; the app service is capped at 2 GB.
