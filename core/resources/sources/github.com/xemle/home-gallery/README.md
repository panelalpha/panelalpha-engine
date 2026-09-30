# HomeGallery (github.com/xemle/home-gallery)

Self-hosted photo and video gallery with AI tagging (objects, faces) through
its api-server.

## Deploying

Nothing is required. The site opens the gallery; with no photos yet it is
empty. Like upstream's default there is no login; enable `server.auth` in
`/data/config/gallery.config.yml` if needed.

Photos go on the `homegallery-pictures` volume (`/data/Pictures`); the
server imports and watches that source. Config, database and previews live on
`homegallery-data` (`/data`).

## What the recipe does

- `overrides/docker-compose.yml` replaces the repo compose (which needs a
  manual `run init`, a `${HOME}/Pictures` bind mount and `${CURRENT_USER}`)
  with the `xemle/home-gallery:1.21.0` and api-server images.
- The gallery runs upstream's `run init --source /data/Pictures` before
  `run server`; init leaves an existing config alone.
- Both services get 1 GB; the api-server's TensorFlow model load does not fit
  the engine's default 384 MB.
- A no-op `ready` service holds the deploy until the gallery answers.
