# Kokoro-FastAPI (github.com/remsky/Kokoro-FastAPI)

OpenAI-compatible text-to-speech for the Kokoro-82M model, CPU inference.
Web player at `/web/`, API docs at `/docs`.

## Deploying

Nothing is required. Point an OpenAI client at `https://<domain>/v1`, e.g.
`curl https://<domain>/v1/audio/speech -H 'Content-Type: application/json'
-d '{"model":"kokoro","input":"Hello","voice":"af_bella"}' -o hello.mp3`.
The API has no authentication of its own. Upstream's settings
(`docs/configuration.md`) are project env vars.

## What the recipe does

- `overrides/docker-compose.yml` runs the release image
  `ghcr.io/remsky/kokoro-fastapi-cpu:v0.9.0` (model included) on port 8880
  instead of the generic Python strategy, which finds no start command.
- 2 GB memory cap, 2 CPUs and `OMP_NUM_THREADS=2`: torch otherwise sizes its
  thread pool from the host's cores and thrashes the CPU quota (one sentence:
  28 s with the default pool, 2.4 s with 2 threads; 57 s at the engine's
  0.75 CPU default); a `ready` gate on `/health`. No data is kept.
