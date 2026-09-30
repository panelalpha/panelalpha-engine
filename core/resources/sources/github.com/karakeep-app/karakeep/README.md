# Karakeep (github.com/karakeep-app/karakeep)

Bookmark-everything app (Next.js web + workers in one image, SQLite), with a
headless Chrome for crawling and Meilisearch for full-text search.

## What the recipe does

- `overrides/docker-compose.yml` runs upstream's `docker/docker-compose.yml`
  stack with pinned images of the current release: `karakeep:0.33.2`,
  `karakeep-chrome:151.0.7922.47-r1`, `meilisearch:v1.41.0`. `/data` and the
  Meilisearch index are named volumes kept across redeploys.
- `hooks/prepare.sh` writes `NEXTAUTH_SECRET` and `MEILI_MASTER_KEY` once to
  `~/.panelalpha/karakeep/secrets.env`.
- `NEXTAUTH_URL` is the site's address. `ready` makes `compose up -d` wait for
  `/api/health`.

The first account registered is the admin. AI tagging is optional: set
`OPENAI_API_KEY` (or `OLLAMA_BASE_URL`) as a project env var and redeploy.
