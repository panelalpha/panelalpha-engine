# Cr*nMaster (github.com/fccview/cronmaster)

A web UI for cron jobs and scripts: one Next.js server in the published
`ghcr.io/fccview/cronmaster` image, its scripts, snippets and data under
`/app`.

## Deploying

Deploy as is, open `/login` and sign in with the password the engine
generates (`CRONMASTER_AUTH_PASSWORD`; there is no username): read it with
`GET /projects/{name}/app-credentials` (MCP `app_credentials_get`).

## What the recipe does

- The repository's `docker-compose.yml` runs as before (port 40123 to 3000,
  `./scripts`, `./data` and `./snippets` bind-mounted). The engine drops its
  Docker socket, `privileged` and `pid: host` as for any project. Cr*nMaster
  reads and writes cron jobs with `nsenter -t 1` into the host, which needs
  those, so on the engine its job list stays empty and saving a job fails
  (`Error executing host crontab command` in the log). The recipe does not
  change that; it only fixes the login.
- `overrides/docker-compose.override.yml` adds the engine's login as
  `env_file` and an entrypoint that copies `CRONMASTER_AUTH_PASSWORD` into
  `AUTH_PASSWORD` before the image's `node server.js` runs. The compose file
  sets `AUTH_PASSWORD=very_strong_password` (the README's example) in
  `environment:`, which an `env_file` cannot beat, so the published password
  no longer signs in.
- Cr*nMaster has no password change in its UI: the login is the environment's
  on every start.
