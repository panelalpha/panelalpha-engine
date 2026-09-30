# Vane (github.com/itzcrazykns/vane)

AI answer engine (formerly Perplexica): Next.js app plus a bundled SearXNG
metasearch instance in the same container.

## Deploying

Deploy the Git URL and open the site. Vane shows its own setup screen, where
you pick a model provider (OpenAI, Anthropic, Ollama, ...) and enter its API key.

## What the recipe does

- `overrides/docker-compose.yml` runs the published `itzcrazykns1337/vane:v1.12.2`
  (latest release; the "full" image with SearXNG) on port 3000 instead of
  building the repo's Dockerfile, which took ~17 minutes per deploy.
- `/home/vane/data` (settings, chat database, uploads) is the named volume
  `vane-data`, kept across redeploys.
- The image is ~3.9 GB, so the first deploy on an account spends most of its
  time pulling it.
