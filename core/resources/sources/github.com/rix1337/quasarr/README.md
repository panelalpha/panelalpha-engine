# Quasarr (github.com/rix1337/quasarr)

Bridges JDownloader to Radarr/Sonarr/Lidarr: a Newznab indexer and a
SABnzbd-compatible download client (both API-key guarded) plus a web UI. One
Python (bottle) process on `:8080`; config and state in `/config`
(`Quasarr.ini`, `Quasarr.db`).

## What the recipe does

- `overrides/docker-compose.yml` runs upstream's own image,
  `ghcr.io/rix1337/quasarr:4.6.19` (the current release). The source checkout
  has no entry point the generic Python strategy recognises, and outside the
  image (`DOCKER` unset) Quasarr keeps its config path in a file beside the
  code, which a redeploy wipes. `/config` is a named volume.
- Quasarr protects its UI and the first-run wizard only when both `USER` and
  `PASS` are set; otherwise everything is open. `hooks/prepare.sh` writes
  `~/.panelalpha/quasarr/auth.env` once (`USER=admin`, random hex `PASS`) and
  the app reads it as env; `AUTH=form` is pinned. The repo's `.env.example`
  (`admin`/`change-me`) lands in `~/project/.env` but is not given to the
  container.
- The entrypoint is the image's own restart loop, preceded by setting
  `EXTERNAL_ADDRESS` to the public URL and `INTERNAL_ADDRESS` to the public
  URL with the scheme's port (Quasarr appends `:8080` to an address without
  one, and that is what Radarr/Sonarr are told to call).
- `ready` gates `compose up` on the web server answering.

## Login and setup

`admin` / the password in `~/.panelalpha/quasarr/auth.env`. After login the
setup wizard asks for source hostnames, their logins, FlareSolverr (skippable)
and a My JDownloader account with a connected device. The Newznab/SABnzbd API
and the API key only come up once that last step succeeds; that is the user's
runtime configuration (a My JDownloader account is required by design).
