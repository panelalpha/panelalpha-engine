# Gathio (github.com/lowercasename/gathio)

Shareable, self-destructing event pages without accounts: one Node server on
:3000 backed by MongoDB.

## What the recipe does

- `overrides/docker-compose.yml` replaces the repository's compose file, which
  builds the checkout and bind-mounts `./gathio-docker/config`, empty until
  someone copies `config.example.toml` there by hand.
- Runs the release image `ghcr.io/lowercasename/gathio:1.6.7` on :3000 beside
  `mongo:7.0`. The database (`mongodb_data_db`) and uploaded event images
  (`images`) are named volumes, so a redeploy keeps them.
- `files/config/config.toml` is upstream's example config with the domain taken
  from the site's address (`GATHIO_DOMAIN`) and `mongodb_url` pointing at the
  `mongo` service. Email is off (`mail_service = "none"`), as upstream ships it.
- `ready` makes `compose up -d` wait until Gathio answers.

## Which MongoDB

Upstream's compose uses `mongo:latest`. Every maintained 8.x image (`mongo:8`
= 8.3.11, `mongo:8.0` = 8.0.32, checked 2026-09-29) refuses to start on Linux
6.19 or newer:

```
"s":"F","c":"CONTROL","id":12257600,"msg":"MongoDB cannot start: Linux kernel
versions 6.19 and newer has a known incompatibility with this version of
MongoDB. See https://jira.mongodb.org/browse/SERVER-121912"
```

Containers share the host's kernel, so on such a host the deploy fails at the
mongo healthcheck. `mongo:7.0` has no such guard and Gathio runs on it (its
driver is mongoose 5.13, which predates 8.0 anyway).

`mongo:8.2` (8.2.12) does start, but that line is finished: its image was last
rebuilt on 2026-07-23, when 8.3 replaced it, while 7.0, 8.0 and 8.3 were all
rebuilt on 2026-09-16. 7.0 is the newest maintained line that starts.

**An install already on mongo 8 cannot simply switch to this recipe.** MongoDB
7.0 will not open a data directory written by 8.x (featureCompatibilityVersion
8.0). Moving such an install means `mongodump` from the 8.x server on a host
that can still run it, then `mongorestore` into the 7.0 one; or lowering the
featureCompatibilityVersion to 7.0 on the 8.x server first, per MongoDB's
downgrade procedure. Neither works on a 6.19+ kernel, where 8.x does not start.
