# OliveTin (github.com/olivetin/olivetin)

Web dashboard of buttons that run predefined shell commands.

## Deploying

Nothing is required. The site opens OliveTin's dashboard with upstream's
example actions. Define your own in `/config/config.yaml` on the `config`
volume (`docker compose -p project exec -u root olivetin vi /config/config.yaml`
inside the account; the image ships `/config` root-owned and runs as uid
1000, as upstream's own compose example does); OliveTin reloads the file
when it changes.

## What the recipe does

- `overrides/docker-compose.yml` runs `jamesread/olivetin:3000.20.0`, since
  the repository's Dockerfiles only package a binary built by the release
  pipeline. `/config` lives on a named volume, seeded from the image on
  first start.
- A no-op `ready` service holds the deploy until `/readyz` answers.
