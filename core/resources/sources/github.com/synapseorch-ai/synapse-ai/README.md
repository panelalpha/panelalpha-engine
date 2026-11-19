# Synapse (github.com/synapseorch-ai/synapse-ai)

AI agent orchestrator: a Python (FastAPI) backend and a Next.js frontend
under supervisord in the root Dockerfile's single image, SQLite, blobs and the
entrypoint's secrets on `/data`.

## Deploying

Deploy as is (the image installs Playwright's Chromium, so the build takes
several minutes), open `/login` and paste the access token the engine
generates (`SYNAPSE_ACCESS_TOKEN`; there is no username) into the access-link
box: read it with `GET /projects/{name}/app-credentials` (MCP
`app_credentials_get`). `/?token=<token>` opens it as a link too. Running
agents needs an LLM provider key, set in Settings.

## What the recipe does

- `overrides/docker-compose.yml` builds the root Dockerfile on port 3000, as
  the dockerfile strategy did, with `/data` (the image's `VOLUME`) on the named
  volume `synapse-data` and the engine's token and the project's `.env` as
  `env_file`. The repository's `docker-compose.yml` (the host's docker.sock,
  a `./backend/data` bind, loopback ports, Postgres) is not used; the image's
  own default database, SQLite on `/data`, is.
- With login off, which is Synapse's default, it opens only with its access
  link. Unpinned, the entrypoint makes a random token on the first start and
  prints the link (`http://localhost:3000/?token=...`) to the container log
  only. A `SYNAPSE_ACCESS_TOKEN` in the environment is kept instead, so the
  engine's token is the way in on every start.
- Settings > General can turn on login with a username and password of one's
  own; then the access link no longer opens Synapse, as upstream intends, and
  the engine keeps reporting the token.
