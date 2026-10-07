# SyncTube (github.com/RblSb/SyncTube)

Watch videos together with chat. Node server compiled from Haxe, built from the
repository's own `Dockerfile` and `docker-compose.yml`.

## Deploying

Nothing to set. The first visit redirects to `/setup`, where the first admin
is created (upstream behaviour). Server settings live in
`/app/user/config.json` on the `synctube-user` volume.

## What the recipe does

- `overrides/docker-compose.override.yml` replaces the repo's
  `${PWD}/user:/app/user` bind with the named volume `synctube-user`. On the
  engine `${PWD}` is empty, so the bind pointed at a root-owned `/user` that
  the app (uid 1000) cannot write, and it crash-looped on
  `Cannot read properties of undefined (reading 'removeOlderCache')`.
  The volume is seeded from the image's `user/` on first start and survives
  redeploys (users.json with the admins, state.json, logs, cache).
- `files/user/config.json` sets `"localAdmins": false`. With upstream's
  `allowProxyIps: true` the client IP is the first `X-Forwarded-For` entry,
  which the visitor supplies; sending `::ffff:<container IP>`
  there made a remote visitor an admin. Admins are the ones created on
  `/setup` or added by an admin.
- The config is baked into the image and only seeds a new volume; edit the
  file on the volume to change settings later.

WebSocket note: the `*.panelalpha.online` test edge drops WebSocket upgrades;
on a real domain pointed at the host the chat connects.
