# GoCD (github.com/gocd/gocd)

Continuous delivery server: web UI and REST API on :8153, under `/go`.

## Deploying

No variables are required. About 700 MB of memory at idle (1 GB heap cap).
GoCD ships with no authentication configured; add an authorization plugin
(e.g. password file) under Admin > Security if the site should not be open.

## What the recipe does

- `overrides/docker-compose.yml` runs `gocd/gocd-server:v26.1.0` (current
  release) instead of building the Gradle source, whose build needs a Java 25
  toolchain the java strategy does not provide.
- `/godata` (cruise-config.xml, H2 database, artifacts, plugins, logs) and
  `/home/go` (SSH keys) are named volumes and survive a rebuild.
- `ready` makes `compose up -d` wait until `/go/api/v1/health` answers.

## Limitation

No agent runs in the account. Start agents elsewhere (`gocd/gocd-agent-*`
images) with `GO_SERVER_URL=https://<domain>/go`.
