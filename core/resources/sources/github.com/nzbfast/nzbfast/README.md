# nzbfast (github.com/nzbfast/nzbfast)

Usenet downloader: one Rust binary with a web dashboard, a built-in indexer
and a SABnzbd/NZBGet-compatible API.

## Deploying

Nothing to set. Open the site and add your Usenet server in the Welcome
panel. nzbfast generates an API key on first run (printed in the container
log, stored in `/config`) for Sonarr/Radarr.

## What the recipe does

`overrides/docker-compose.yml` runs `nzbfast/nzbfast:1.7.1` on port 6789 and
replaces the repository's compose file, which:

- keeps `/config` (settings, API key, queue, index) and `/watch` on `./`
  bind mounts inside `~/project`, which every deploy empties: here they are
  named volumes, as is `/data/usenet` (downloads), which upstream maps to a
  host path;
- adds a Watchtower service that needs `/var/run/docker.sock`;
- pairs `cap_drop: [ALL]` with `cap_add`, and the engine keeps only the drop,
  so the entrypoint stops at
  `failed switching to "1000:1000": operation not permitted`. The recipe
  leaves the default capability set.

`ready` makes `compose up -d` wait for upstream's own healthcheck URL.
