# URL-to-PNG (github.com/jasonraimondi/url-to-png)

HTTP screenshot service: `GET /?url=https://example.com` renders the page in
headless Chromium (Playwright) and returns a PNG. `GET /ping` answers `"pong"`.
There is no web UI; `/` without `url` answers 400.

## Why a recipe

The repository's `docker-compose.yml` is a development stack: it builds from
source and adds CouchDB, MinIO and `minio/mc:latest`. That tag no longer
exists on Docker Hub, so the plain deploy fails with
`The base image minio/mc:latest could not be downloaded`.

## What the recipe does

- `overrides/docker-compose.yml` runs `ghcr.io/jasonraimondi/url-to-png:v2.1.4`
  (current release) on port 3089, `NODE_ENV=production`, `mem_limit: 2g` for
  the Chromium pool.
- Storage is upstream's default `stub` (renders every request, caches
  nothing), as the getting-started `docker run` does. To cache, set
  `STORAGE_PROVIDER=s3` or `couchdb` and its credentials as project env vars
  (passed through `.env`), as with `ALLOW_LIST`, `BLOCK_LIST`, `CRYPTO_KEY`.
- A no-op `ready` service holds the deploy until `/ping` answers.

Upstream ships the endpoint open: anyone can make it fetch any URL. Restrict
it with `ALLOW_LIST` / `CRYPTO_KEY` (encrypted query strings) if needed.
