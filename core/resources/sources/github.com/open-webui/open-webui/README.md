# Open WebUI (github.com/open-webui/open-webui)

Chat interface for local and remote LLMs, served on :8080.

## Deploying

No variables are required. The first visitor gets Open WebUI's own onboarding
screen and creates the admin account. Models are added afterwards in
Admin Panel > Settings > Connections (an Ollama URL or an OpenAI-compatible
API); no model server runs in the account.

## What the recipe does

- `overrides/docker-compose.yml` runs `ghcr.io/open-webui/open-webui:v0.11.4`.
  Upstream's compose builds the image from source, which ran out of memory in
  a 2500 MB account, and starts an Ollama sidecar that cannot hold a useful
  model within the account's memory.
- `/app/backend/data` is the named volume `open-webui`: the SQLite database,
  uploads, the vector store, the cached embedding model and the generated
  `WEBUI_SECRET_KEY` (via `WEBUI_SECRET_KEY_FILE`), so sessions survive a
  redeploy.
- A no-op `ready` service holds `compose up` until `/health` answers; the
  first boot runs migrations and downloads the embedding model.
- Idle memory is about 1.1 GB; the app service is capped at 2 GB.
