# Ollama (github.com/ollama/ollama)

Local LLM server: REST API on :11434. `/` answers `Ollama is running`; models
are pulled and run through the API (`/api/pull`, `/api/tags`, `/api/generate`,
and the OpenAI-compatible `/v1/`). There is no web UI and no authentication of
its own.

## Deploying

Nothing is required to start. Optional project environment variables:

- `OLLAMA_ORIGINS` - extra browser origins allowed to call the API (CORS).
- `OLLAMA_KEEP_ALIVE` - how long a model stays loaded after a request (default `5m`).

Pull a model after the deploy, for example
`curl https://<domain>/api/pull -d '{"model":"smollm:135m"}'`. Inference runs
on the CPU; the model has to fit in the account's memory limit.

## What the recipe does

- `overrides/docker-compose.yml` runs `ollama/ollama:0.35.0` on port 11434 with
  `/root/.ollama` on the named volume `ollama-models` (kept across redeploys).
- The repository's own Dockerfile is not used: it builds every GPU backend on
  a 7.65 GB ROCm base image and does not finish within a deploy.
