# google-webfonts-helper (github.com/majodev/google-webfonts-helper)

Web UI and API to download Google Fonts (woff2/woff/ttf + CSS) for
self-hosting. Node/Express on :8080, no database, nothing kept on disk.

## Required variable

- `GOOGLE_FONTS_API_KEY`: a Google Fonts Developer API key
  (https://developers.google.com/fonts/docs/developer_api). The server exits
  at start without a working one, so the recipe's `env-check` service fails
  the deploy with a message naming it while it is unset, and with Google's own
  answer (`API key not valid ...`) while Google rejects it.

## What the recipe does

- `overrides/docker-compose.yml` runs the published image
  `ghcr.io/majodev/google-webfonts-helper:v1.7.1` (current release, the tagged
  HEAD). The repository's compose file is a development container.
- `files/gwfh-env-check.sh` is the one-shot check; `app` waits for it with
  `service_completed_successfully`.
- A no-op `ready` service makes `compose up -d` return once `/api/fonts`
  answers, i.e. the font list was loaded from Google.
