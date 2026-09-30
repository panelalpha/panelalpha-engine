# PiGallery2 (github.com/bpatrik/pigallery2)

Directory-first photo gallery: it indexes the photos in its media folder and
serves them with thumbnails, search, maps and sharing.

## Deploying

Nothing is required. The site opens PiGallery2's login; the first-run user is
upstream's default `admin` / `admin`, change it in the app.

Photos live on the `pigallery2-images` volume (`/app/data/images`). Upload
through the app is off by default; enable it in Settings (Upload) as an admin.

## What the recipe does

- `overrides/docker-compose.yml` runs `bpatrik/pigallery2:3.5.2` instead of
  the static-Angular build the engine would detect, with config, database,
  photos and thumbnails on named volumes and a 2 GB memory limit (upstream
  suggests up to 3 GB for large libraries and video transcoding).
- A no-op `ready` service holds the deploy until `/heartbeat` answers.
