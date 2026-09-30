# Woodpecker CI (github.com/woodpecker-ci/woodpecker)

## Deploying

1. On the forge, create an OAuth application with the callback URL
   `https://<site address>/authorize`.
2. Set the project environment variables, for example for GitHub:
   `WOODPECKER_GITHUB=true`, `WOODPECKER_FORGE_CLIENT=<client id>`,
   `WOODPECKER_FORGE_SECRET=<client secret>`. For Gitea/Forgejo/GitLab use
   `WOODPECKER_GITEA=true` (etc.) and `WOODPECKER_FORGE_URL=https://<forge>`.
   Any other upstream `WOODPECKER_*` server setting is passed through too.
3. Deploy. Without a forge the deploy fails with
   `woodpecker: set in the project's environment variables, then redeploy: ...`
   naming each missing value.

## What the recipe does

- `overrides/docker-compose.yml` runs `woodpeckerci/woodpecker-server:v3.18.1`
  on :8000, `/var/lib/woodpecker` on the named volume `data`, and the whole
  project `.env` as the server's environment.
- `forge-check` (alpine variant of the same image, one-shot) runs
  `files/woodpecker-forge-check.sh`; the server starts only once it passes.
- No agent: pipelines need an agent connected to gRPC :9000.
