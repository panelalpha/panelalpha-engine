# tinyfeed (github.com/thebigroomxxl/tinyfeed)

Aggregates feeds into one static HTML page, refreshed periodically.

## Deploying

Set in the project's environment variables:

- `TINYFEED_FEEDS` (required): feed URLs separated by spaces or commas.
- `TINYFEED_NAME` (optional): page title, default `Feed`.
- `TINYFEED_INTERVAL` (optional): refresh period in minutes, default `60`.

Changing the list takes a redeploy.

## What the recipe does

- `init` writes `feeds.txt` from `TINYFEED_FEEDS` into the `data` volume and
  fails the deploy when it is empty.
- `tinyfeed` runs `thebigroomxxl/tinyfeed:v1.5.0 --daemon`, writing
  `index.html` into the volume.
- `web` is Caddy serving that page on 8080 (upstream's Caddy example, every
  path rewritten to `index.html`); `ready` holds the deploy until it answers.

Bumping: change the tinyfeed image tag.
