# OwnTracks Recorder (github.com/owntracks/recorder)

Stores location updates from the OwnTracks iOS/Android apps and shows them
on a map, as a table or via the HTTP API.

## What the recipe does

- `overrides/docker-compose.yml` runs `owntracks/recorder:1.0.4` (the image
  upstream builds from owntracks/docker-recorder) with `/store` on the named
  volume `recorder-store`, so history survives redeploys.
- MQTT is off (`OTR_PORT=0`): there is no broker in the account. Configure
  the app in HTTP mode with the URL `https://<domain>/pub`; the user and
  device come from `?u=<user>&d=<device>` or the app's HTTP login fields.

## Notes

The Recorder has no authentication of its own: anyone who can reach the
site can read the history and publish to `/pub`.
