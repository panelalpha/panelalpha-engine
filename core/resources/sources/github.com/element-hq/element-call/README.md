# Element Call (github.com/element-hq/element-call)

Matrix group video-call web app: a static SPA served by nginx. It talks to a
Matrix homeserver (and the LiveKit/MatrixRTC backend that homeserver
advertises); neither is part of this deployment.

## Deploying

Set the project environment variables, then deploy:

| Variable | Required | Example |
|---|---|---|
| `ELEMENT_CALL_HOMESERVER_URL` | yes | `https://matrix.example.com` |
| `ELEMENT_CALL_SERVER_NAME` | no (defaults to the URL's host) | `example.com` |
| `ELEMENT_CALL_LIVEKIT_SERVICE_URL` | no (the homeserver's `.well-known` normally advertises it) | `https://livekit-jwt.example.com` |

Without the homeserver the deploy fails with:

```
element-call: ELEMENT_CALL_HOMESERVER_URL is not set. Set it to your Matrix homeserver's client URL ...
```

Standalone calls need a homeserver with guest/open registration and a
MatrixRTC backend; see upstream `docs/self_hosting.md`.

## What the recipe does

- `overrides/docker-compose.yml` runs `ghcr.io/element-hq/element-call:v0.26.1`
  (nginx-unprivileged) on port 8080.
- `config` (same image, one-shot) runs `files/element-call-config.sh`: it
  refuses a missing/invalid homeserver and writes `config.json` onto the named
  volume `element-call-config`; the app starts only once it passes.
- `files/element-call-nginx.conf` is upstream `config/nginx.conf` plus a
  `location = /config.json` serving that file (the image ships none).
