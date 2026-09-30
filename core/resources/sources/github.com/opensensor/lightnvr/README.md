# LightNVR (github.com/opensensor/lightNVR)

Lightweight network video recorder: a C daemon with a web UI and REST API on
`:8080`, SQLite for users/streams/recordings, and go2rtc (managed by LightNVR)
for RTSP, WebRTC and HLS.

## What the recipe does

- `overrides/docker-compose.yml` replaces the repository compose (which
  publishes RTSP, WebRTC and go2rtc's unauthenticated API on the host) with
  `ghcr.io/opensensor/lightnvr:0.42.8`. `/etc/lightnvr` and
  `/var/lib/lightnvr/data` (database, recordings) are named volumes.
- **admin/admin never exists:** LightNVR creates `admin` on first start with
  `[web] password` from `lightnvr.ini`, or `admin` when that is empty.
  `hooks/prepare.sh` writes `~/.panelalpha/lightnvr/admin.env` once (24 hex
  chars: LightNVR stores the ini password in a 32-byte buffer and silently
  truncates anything over 31 characters). `files/panelalpha-seed.sh` runs as
  the `seed` service before the app starts and, while no database exists,
  writes that password into `lightnvr.ini`. Once the database exists it does
  nothing, so a password changed in the UI survives redeploys.
- No self-registration; other users are created by an admin (Users page).
- `ready` gates `compose up` on the app answering `/api/auth/login/config`.

## Cameras and streaming

Cameras are added at runtime in the UI. Only `:8080` is published. LightNVR
proxies go2rtc's HLS and snapshot endpoints (`/go2rtc/api/hls/*`,
`/go2rtc/api/frame.jpeg`) through it, so HLS live view should work; this was
not exercised (no camera in the test). WebRTC (8555), RTSP re-streaming
(8554) and the go2rtc API (1984) are not reachable from outside the account.
The cameras must be reachable from the host (public RTSP URL or a tunnel).

## Login

`admin` / the password in `~/.panelalpha/lightnvr/admin.env`. To reset it:
stop the app, `DELETE FROM users WHERE username='admin'` in
`/var/lib/lightnvr/data/database/lightnvr.db`, put `password = ...` back under
`[web]` in `/etc/lightnvr/lightnvr.ini`, and start it again.
