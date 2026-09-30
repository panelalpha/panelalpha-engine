# OctoPrint (github.com/OctoPrint/OctoPrint)

Web interface for consumer 3D printers.

## Deploying

The first visit opens OctoPrint's own setup wizard (access control, first
user), as upstream ships it. A hosted account has no serial/USB device, so a
locally attached printer cannot be driven; file management, slicing profiles
and network-printer plugins work.

## What the recipe does

- `overrides/docker-compose.yml` runs `octoprint/octoprint:1.11.8` (port 80
  in the container) with `/octoprint` (config, users, uploads, plugins) on the
  named volume `octoprint-data`, kept across redeploys. `ready` makes
  `compose up -d` wait for the UI.
- Without it the engine's python strategy builds the package but has no start
  command and serves its placeholder page.
