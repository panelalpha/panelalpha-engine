# Plex Rewind (github.com/raunot/plex-rewind)

"Spotify Wrapped"-style statistics for a Plex server, read from Tautulli.
Next.js standalone server on :8383, sign-in with a Plex account (next-auth).

## Deploying

Nothing to set. Open the site: the first visit goes to the connection
settings, where the Plex and Tautulli addresses and API keys are entered.

## What the recipe does

- `overrides/docker-compose.yml` replaces the repo's build-from-source compose
  (which needs a missing `.env.local`) with `ghcr.io/raunot/plex-rewind:5.2.0`
  on port 8383; `/app/config` is on the named volume `plex-rewind-config`
  (kept across redeploys).
- `hooks/prepare.sh` generates `NEXTAUTH_SECRET` once into
  `~/.panelalpha/plex-rewind/app.env`, so sessions survive a redeploy.
- `NEXTAUTH_URL` and `NEXT_PUBLIC_SITE_URL` are the site's public address; the
  image reads them at runtime.
