# gocron (github.com/flohoss/gocron)

Task scheduler: recurring jobs from a YAML file, run on cron schedules, with a
web UI for triggering runs and reading logs.

## Deploying

Nothing is required. The site opens gocron's job list with the example jobs
it writes on first start. Edit `/app/config/config.yaml` on the `config`
volume (`docker compose -p project exec gocron ...` inside the account);
gocron reloads jobs when the file changes.

## What the recipe does

- `overrides/docker-compose.yml` replaces the repository's development
  `compose.yml` with the published `ghcr.io/flohoss/gocron:v0.13.1`, as
  upstream's quick start, with `/app/config` (config and SQLite database)
  on a named volume.
- A no-op `ready` service holds the deploy until `/health` answers.
