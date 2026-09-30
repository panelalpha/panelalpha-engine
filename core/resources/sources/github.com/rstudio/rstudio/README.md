# RStudio Server (github.com/rstudio/rstudio)

The browser IDE for R, served on :8787.

## Deploying

No variables are required. Sign in as `rstudio`. Without `PASSWORD` the image
generates a password on every container start and prints it in the log:

    docker compose -p project logs app | grep -i password

Set `PASSWORD` in the project's environment to choose a fixed one.

## What the recipe does

- `overrides/docker-compose.yml` runs `rocker/rstudio:4.6.1` (R 4.6.1 with the
  released RStudio Server). The repository is the IDE source; its root
  `Dockerfile.dispatcher` is a CI image that only runs `/bin/bash`.
- `/home/rstudio` is the named volume `rstudio-home`: projects, IDE state and
  packages installed into the user library survive redeploys. Packages
  installed system-wide (as root) do not.
- `RUNROOTLESS=false`: the image's `auto` check reads the account's user
  namespace as rootless Docker, deletes the `rstudio` user and leaves only
  `root` able to sign in.
- `ready` makes `compose up -d` wait until rserver listens.
