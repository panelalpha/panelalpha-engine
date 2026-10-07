# Damselfly (github.com/webreaper/damselfly)

Server-based photo management for large collections: indexing, thumbnails,
face and object recognition, EXIF/IPTC keyword tagging and search.

## Deploying

Nothing is required. The site opens the Damselfly browser; the library is
empty until photos are added. There is no login by default (upstream's
default; enable authentication in the app's settings).

Photos go on the `damselfly-pictures` volume (`/pictures`); Damselfly indexes
that tree at startup and watches it. The SQLite database and settings live
on `damselfly-config` (`/config`), thumbnails on `damselfly-thumbs`.

## What the recipe does

- `overrides/docker-compose.yml` runs `webreaper/damselfly:4.5.3` instead of
  building the repo Dockerfile (it needs CI-built `publish/` and `Models/`).
- `hooks/prepare.sh` deletes the repo's Visual Studio `docker-compose.override.yml`
  (service `damselfly.web`, dev https ports): the engine still layers it over
  a replaced compose file.
- A 2 GB memory limit for indexing and the ML models.
- A no-op `ready` service holds the deploy until port 6363 accepts.
