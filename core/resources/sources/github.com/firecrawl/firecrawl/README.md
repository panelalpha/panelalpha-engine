# Firecrawl (github.com/firecrawl/firecrawl)

Web scraping and crawling API. `GET /` answers
`{"message":"Firecrawl API",...}`; use it with `POST /v2/scrape`,
`POST /v2/crawl` and the SDKs/CLI pointed at the site's URL. There is no web UI.

## Deploying

Create the project with `memory_limit` 4096. Measured idle: API ~2.1-2.3 GB,
Playwright ~0.4 GB, RabbitMQ ~0.17 GB, Postgres ~0.13 GB (about 2.9 GB total).

The API is unauthenticated (`USE_DB_AUTHENTICATION=false`), which is upstream's
self-host default. Optional project environment variables are passed through:
`OPENAI_API_KEY`, `OPENAI_BASE_URL`, `MODEL_NAME`, `OLLAMA_BASE_URL`,
`PROXY_SERVER`/`PROXY_USERNAME`/`PROXY_PASSWORD`, `SEARXNG_ENDPOINT`,
`BULL_AUTH_KEY` (enables the queue admin UI at `/admin/<key>/queues`), and the
concurrency knobs `NUQ_WORKER_COUNT`, `NUM_WORKERS_PER_QUEUE`,
`CRAWL_CONCURRENT_REQUESTS`, `MAX_CONCURRENT_JOBS`, `BROWSER_POOL_SIZE`.

## What the recipe does

`overrides/docker-compose.yml` replaces the repository's `docker-compose.yaml`:

- `ghcr.io/firecrawl/firecrawl:2.11.426` (identical to `:latest`, built from
  HEAD b5d187b on 2026-09-29) instead of building `apps/api`;
  `playwright-service` and `nuq-postgres` pinned by digest, since upstream
  publishes only `:latest` for them.
- Memory limits sized for an account (API 3 GB, Playwright 1 GB, RabbitMQ
  512 MB, Postgres 256 MB, Redis 256 MB) instead of upstream's 8 GB + 4 GB,
  and `NUQ_WORKER_COUNT=2` (upstream 5): with five NuQ workers the API process
  was OOM-killed (exit 137) inside 3 GB.
- RabbitMQ health budget raised: it took 27 s to start here, and upstream's
  5 s x 3 retries failed the plain deploy ("rabbitmq-1 is unhealthy").
- The optional FoundationDB services are dropped (NuQ on Postgres is the
  default). A `ready` service makes the deploy wait for the API to answer.

Like upstream, nothing is persisted: jobs and results live in Postgres/Redis
for the lifetime of the stack, and a redeploy starts with an empty queue.
