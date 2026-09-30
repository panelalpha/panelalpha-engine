# EmbyStat (github.com/mregni/EmbyStat)

A statistics dashboard for an Emby or Jellyfin media server, on port 6555.
Upstream has stopped development (see the repository README); the latest
release is 0.2.0-beta.38.

## Deploying

Nothing is required. The first visit opens EmbyStat's own setup wizard, which
creates the EmbyStat user and asks for the address and API key of the Emby or
Jellyfin server to read. That server must be reachable from this account over
the internet; EmbyStat does not include one.

Settings (including whether the wizard has finished) live in `/app/config`
on the `embystat-config` volume and the SQLite database in `/app/data` on the
`embystat-data` volume; both survive redeploys.

## What the recipe does

- `overrides/docker-compose.yml` runs upstream's published
  `uping/embystat:beta-linux-x64` image of release 0.2.0-beta.38, pinned by
  digest because the tag moves, instead of building the repository: the
  generic .NET build publishes only the backend, and the frontend
  (`EmbyStat.Web`) is missing, so every page was HTTP 500.
