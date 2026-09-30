# MediaMTX

Runs the official `bluenviron/mediamtx:1.21.1` image instead of building the Go
source (the build embeds generated files the generic recipe cannot produce).

- Published port: HLS server `8888` -> `https://<domain>/<path>/` (player page)
  and `/<path>/index.m3u8`.
- Configure with MediaMTX's `MTX_*` env vars as project env vars; add a pulled
  source with `MTX_PATHS_<NAME>_SOURCE=rtsp://...`.
- RTSP/RTMP/SRT/WebRTC listeners run but are not reachable from outside the
  account (only HTTP is routed).
- `/recordings` is a named volume (recording is off by default).
