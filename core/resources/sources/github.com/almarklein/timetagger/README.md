# TimeTagger (github.com/almarklein/timetagger)

Self-hosted time tracker (Python/uvicorn); each user's records are an SQLite
file under the data directory.

## Deploying

Deploy as is, open `/timetagger/login` and sign in with the login the engine
generates (`TIMETAGGER_USERNAME`, default `admin`, and `TIMETAGGER_PASSWORD`):
read it with `GET /projects/{name}/app-credentials` (MCP `app_credentials_get`).

For several users, set the project environment variable
`TIMETAGGER_CREDENTIALS` to `user:hash` pairs from https://timetagger.app/cred,
writing every `$` as `$$` (the value passes through compose interpolation):
`alice:$$2a...,bob:$$2a...`. It replaces the engine's login.

## What the recipe does

- `overrides/docker-compose.yml` runs `ghcr.io/almarklein/timetagger:v26.1.3`
  on port 8080 with `/root/_timetagger` on the named volume `timetagger-data`
  (kept across redeploys).
- `files/timetagger-start.sh` is the app's entrypoint: without
  `TIMETAGGER_CREDENTIALS` it hashes `TIMETAGGER_PASSWORD` with the image's
  bcrypt into `TIMETAGGER_USERNAME:<hash>`, then runs `python -m timetagger`.
