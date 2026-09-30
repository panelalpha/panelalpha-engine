# Jelu (github.com/bayang/jelu)

Read and to-read book tracker (Kotlin/Spring Boot, SQLite, Vue UI, Calibre
for metadata lookup).

## Deploying

No environment variables are needed. On first visit Jelu shows its own
first-run page, where the first (admin) user is created.

## What the recipe does

- `overrides/docker-compose.yml` runs `wabayang/jelu:0.87.3` on port 11111
  with `/database` (SQLite and logs), `/files/images`, `/files/imports` and
  `/config` on named volumes, kept across redeploys. `ready` makes
  `compose up -d` wait for `/api/v1/setup/status` (first boot runs the
  Liquibase migrations, ~30s).
- The container is capped at 1024 MB with the heap at 60% of it
  (`JAVA_TOOL_OPTIONS`); the app idles at ~310 MB.
- The repository's Dockerfile is not built: it copies a jar that CI builds
  beforehand, and the UI only gets into that jar through the separate Gradle
  task `copyWebDist`. Built on the host without it, the site is a Spring
  "Whitelabel Error Page" 404.
