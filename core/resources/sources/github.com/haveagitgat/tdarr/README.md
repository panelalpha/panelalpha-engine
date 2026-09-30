# Tdarr (github.com/haveagitgat/tdarr)

Distributed media transcoding: Tdarr_Server (web UI and REST API `/api/v2` on
`:8265`, node API on `:8266`) plus Tdarr_Node workers running FFmpeg or
HandBrake. Used next to the *arr stack to convert a media library.

## Licence and source

The repository has no application source: a `package.json` with no code,
`docker/Dockerfile.{base,final}` that download closed-source binaries from
Backblaze B2, and s6 service scripts. `LICENSE.md` is an EULA with a free
"Personal Free" tier. The official image `ghcr.io/haveagitgat/tdarr` runs
without a licence key, so the recipe runs that image unmodified and builds
nothing.

## What the recipe does

- `overrides/docker-compose.yml` runs `ghcr.io/haveagitgat/tdarr:2.91.01`
  (current release) with `internalNode=true`, so one container serves the UI
  and transcodes on CPU. There is no GPU, so the node gets one CPU transcode
  worker and one CPU health-check worker. Only `:8265` is published, and the
  internal node reaches the server over localhost.
- Named volumes: `/app/server` (SQLite DB: users, API keys, libraries,
  flows, job reports), `/app/configs`, `/app/logs`, `/media` (library root)
  and `/temp` (transcode cache).
- **Auth is on** (`auth=true`). With no user, Tdarr's UI asks whoever loads it
  first to create the account (`POST /api/v2/public/auth/register`, which
  answers 403 once any user exists). `hooks/prepare.sh` writes, once:
  - `~/.panelalpha/tdarr/tdarr.env`: `authSecretKey` (JWT signing key) and
    one `tapi_…` key used as both `seededApiKey` (server) and `apiKey` (the
    internal node, which needs it once auth is on);
  - `~/.panelalpha/tdarr/admin.env`: `admin` plus a random password.
- `files/panelalpha-seed.sh` runs as the `seed` service (same image, it has
  curl). It chowns `/media` to PUID 1000, polls the server every second and
  registers the admin as soon as it answers, then logs in with those
  credentials. If a user exists that it cannot log in as, it exits 1 and the
  deploy fails, so a first visitor winning the race cannot pass unnoticed.
  `ready` makes `compose up -d` wait for the seed.

## Login

`admin` / the password in `~/.panelalpha/tdarr/admin.env`. For scripts, send
`x-api-key` with the key from `tdarr.env`, or log in at
`/api/v2/public/auth/login` and send `Authorization: Bearer <token>`.

Put media under `/media` (the `tdarr-media` volume) and add a library with
that folder, transcode cache `/temp`. A finished transcode waits in
Staging for Accept unless auto-accept is turned on.
