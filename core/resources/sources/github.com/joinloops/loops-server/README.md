# Loops (github.com/joinloops/loops-server)

Federated short-video platform (Laravel on FrankenPHP, Horizon, MySQL, Redis).

## What the recipe does

- The repo's `docker-compose.yml` bind-mounts `./storage`, so the engine reads
  it as a workstation file and runs its generic Laravel strategy with
  `.env.example` (`REDIS_PASSWORD=null`) against a kept Redis that requires a
  password: every page is a 500 `NOAUTH Authentication required`.
- `overrides/docker-compose.yml` follows upstream's compose and
  `DOCKER_COMPOSE_SETUP.md`:
  - `build` builds `loops-local:latest` once from the repo's `Dockerfile`
    (ffmpeg is compiled from source: ~10 minutes, needs the 4 GB memory limit);
    `loops`, `init`, `horizon` and `scheduler` run that image.
  - `db` is `mysql:9`, `redis` is `redis:8-trixie` with a password, as upstream.
  - `loops` migrates on start (upstream's `AUTORUN_*`) and answers on 8080.
  - `init` (one-shot) runs upstream's setup steps 6-8: `AdminSettingsSeeder`
    once (a marker on the storage volume stops it resetting admin changes),
    `passport:keys` when the keys are missing, `app:ensure-boottime`.
- `hooks/prepare.sh` generates `APP_KEY` and the DB/Redis passwords once into
  `~/.panelalpha/loops/app.env` (0600).
- `storage/` (media, OAuth keys), MySQL and Redis are named volumes.
- Optional settings (`MAIL_*`, `AWS_*` for S3 media, ...) come from the
  project's environment variables.

No account is seeded: register on the site, or run
`php artisan create-admin-account` in the `loops` service for an admin.
