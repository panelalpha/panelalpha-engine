# Flipt (github.com/flipt-io/flipt)

Git-native feature-flag server (v2): one Go binary on :8080 serving the UI,
the REST and OFREP APIs, and gRPC over HTTP.

## Deploying

Nothing is required. The site opens the Flipt UI with no authentication, as
upstream ships it. Configure Flipt with `FLIPT_*` project environment
variables, which override the config file, e.g. authentication
(`FLIPT_AUTHENTICATION_REQUIRED=true` plus a method) or a remote git
repository for the default storage.

## What the recipe does

- `overrides/docker-compose.yml` replaces the repository's developer compose
  with `ghcr.io/flipt-io/flipt:v2.13.0`, data on the `flipt-data` volume.
- `files/flipt-config.yml` is mounted as `/etc/flipt/config/default.yml`: it
  moves the default storage from memory (lost on every restart) to a local
  git repository at `/var/opt/flipt/features` on that volume.
- A no-op `ready` service holds the deploy until `/health` answers.
