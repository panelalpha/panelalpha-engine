# Manyfold (github.com/manyfold3d/manyfold)

Digital asset manager for 3D print files (STL, OBJ, 3MF and more): libraries,
previews, creators, collections, tags.

## Deploying

Nothing is required. The first visit shows Manyfold's own setup form for the
administrator account (as upstream ships it). Upload models in the web UI or
create a library at `/libraries` (the `libraries` volume). Multi-user mode,
SMTP and other options are upstream's env vars (`MULTIUSER=enabled`, ...).

## What the recipe does

- `overrides/docker-compose.yml` runs upstream's single-container image
  `manyfold3d/manyfold-solo` 0.150.0 (SQLite + bundled Redis) instead of the
  engine's Rails source build, which fails on `ruby file: ".ruby-version"`.
- `hooks/prepare.sh` generates `SECRET_KEY_BASE` once into
  `~/.panelalpha/manyfold/app.env`.
- `/config` (database, plugins) and `/libraries` are named volumes; a one-shot
  `perms` service hands them to UID 1000, the user upstream's example runs as.
- `PUBLIC_HOSTNAME` is the site's domain (absolute URLs, HTTPS assumed behind
  the proxy). The healthcheck does not follow redirects for that reason.
