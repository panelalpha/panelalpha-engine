# Langfuse (github.com/langfuse/langfuse)

LLM engineering platform: tracing, prompt management, evaluations. Next.js web
app and worker with PostgreSQL, ClickHouse, Redis and MinIO.

## Deploying

Give the account 4096 MB (about 2.5 GB in use at idle: web 1.3 GB, worker
0.75 GB, ClickHouse 0.4 GB). No project environment variables are needed.
Open the site and sign up; create an organization, a project and API keys
there (upstream behaviour). SDKs and OTLP send to `https://<domain>`.

## What the recipe does

- `overrides/docker-compose.yml` is upstream's `docker-compose.yml` with the
  images pinned to the current release (`langfuse`/`langfuse-worker` 4.47.0;
  MinIO by digest, Chainguard publishes only `latest`), `NEXTAUTH_URL` set to
  the site's address (upstream defaults to `http://localhost:3000`, which sends
  every sign-in to localhost) and only `langfuse-web` publishing a port.
- `hooks/prepare.sh` replaces the CHANGEME defaults with values generated once
  into `~/.panelalpha/langfuse/` (0600): PostgreSQL, ClickHouse, Redis and
  MinIO passwords, `NEXTAUTH_SECRET`, `SALT`, `ENCRYPTION_KEY`.
- Data stays on upstream's named volumes; `ready` makes `compose up -d` wait
  until `/api/public/health` answers.

Browser media uploads are not configured: they need MinIO on a public address,
and an account publishes one port.
