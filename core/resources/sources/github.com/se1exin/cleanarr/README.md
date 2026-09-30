# Cleanarr (github.com/se1exin/cleanarr)

Web UI for finding and deleting duplicate files in a Plex library. One image
(`selexin/cleanarr`: nginx + uWSGI/Flask serving a React front end on :80).

## Deploying

Set these project environment variables before deploying:

- `PLEX_BASE_URL` - your Plex server's address, e.g. `http://203.0.113.5:32400`
  (it must be reachable from this host)
- `PLEX_TOKEN` - a Plex token
- optional: `LIBRARY_NAMES` (default `Movies`, several separated by `;`),
  `BYPASS_SSL_VERIFY=1`, `PAGE_SIZE`, `PLEX_TIMEOUT`

Without the two required ones the deploy fails with:

```
cleanarr: set in the project's environment variables: PLEX_BASE_URL PLEX_TOKEN (...)
```

## Why a recipe

The repository ships only a Dockerfile, which the engine rejects because of
`COPY ./backend/requirements.txt/ /app` (trailing slash after a file name), so
the plain deploy falls back to the PanelAlpha placeholder page.

## What the recipe does

- `overrides/docker-compose.yml` runs upstream's published
  `selexin/cleanarr:v2.5.2` (latest release) on port 80, `/config` on the
  `cleanarr-config` volume.
- `plex-check` (one-shot, runs `files/cleanarr-plex-check.sh`) fails the deploy
  naming each missing Plex setting; the app starts only once it passes.
