# GoModel (github.com/enterpilot/gomodel)

OpenAI/Anthropic-compatible AI gateway (Go) on :8080. The dashboard is at
`/admin/dashboard`; the site root is the API and answers 404/401.

## Deploying

No variables are required. Optional project env vars, as upstream documents
them in `.env.template`: provider keys (`OPENAI_API_KEY`,
`ANTHROPIC_API_KEY`, ...) and `GOMODEL_MASTER_KEY`. Without a master key the
gateway starts in upstream's unauthenticated mode and says so in its log.

## What the recipe does

- `overrides/docker-compose.yml` replaces upstream's compose, which starts the
  gateway only under the `app` profile (built from source) and otherwise runs
  its development Redis/PostgreSQL/MongoDB/Adminer.
- Runs the published `enterpilot/gomodel:0.1.98` alone with its default
  SQLite storage; `/app/data` (database, media, install id) is a named volume.
