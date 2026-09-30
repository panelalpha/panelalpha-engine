# Formbricks (github.com/formbricks/formbricks)

Survey and experience-management platform: Next.js app, SpiceDB for
authorization, Formbricks Hub, Cube for analytics, PostgreSQL (pgvector) and
Valkey.

## What the recipe does

- `overrides/docker-compose.yml` is upstream's `docker/docker-compose.yml`
  from release 6.0.1 with pinned images (`formbricks:6.0.1`, `hub:0.8.7`,
  `spicedb:v1.52.0`, `cube:v1.6.6`, `pgvector:pg18`, upstream's valkey digest)
  and memory caps that fit a 2500 MB account. The profiled extras (taxonomy,
  vLLM, authzed-ops) and the SAML bind mount are left out.
- One-shot services run in upstream's order: Prisma migrations, SpiceDB
  database bootstrap and `datastore migrate`, Hub migrations, AuthZed prepare.
  `ready` makes `compose up -d` wait for `/health`.
- `hooks/prepare.sh` writes every secret once to `~/.panelalpha/formbricks/`
  (`db.env`, `app.env`, `authzed.env`, `hub.env`, `cube.env`).

Open the site after the first deploy: `/setup` creates the first user and
organization. SMTP (`MAIL_FROM`, `SMTP_*`) and S3 (`S3_*`) are optional
project env vars, as in upstream's compose file.
