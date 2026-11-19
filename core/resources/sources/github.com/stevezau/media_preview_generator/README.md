# Media Preview Generator (github.com/stevezau/media_preview_generator)

Preview thumbnails (BIF), intro and credits markers for Plex, Emby and
Jellyfin: a Flask app in a linuxserver.io image (s6-overlay `/init`), its
settings under `/config`. It works on the media server's files, so a preview
can show the UI and the setup wizard but needs a media server and its library
paths to do any work.

## Deploying

Deploy as is, open `/login` and sign in with the token the engine generates
(`MEDIA_PREVIEW_TOKEN`): read it with `GET /projects/{name}/app-credentials`
(MCP `app_credentials_get`). The setup wizard follows; its last step, and
Settings later, can set a token of your own.

## Why a recipe

The web UI's only login is an access token. On the first start the app makes
a random one in `/config/auth.json` and logs only its last four characters;
the login page says to read the file or set `WEB_AUTH_TOKEN`. Nobody deploying
it could do either, so the login page was a wall.

## What the recipe does

- `overrides/docker-compose.override.yml` runs
  `files/panelalpha/media-preview-start.sh` as the app service's entrypoint and
  mounts the engine's `app-credentials.env` read-only at
  `/pa/app-credentials.env`.
- `media-preview-start.sh`, as root, before the image's own
  `/init /app/wrapper.sh`: while `/config/auth.json` holds no token, writes
  `{"token": "<MEDIA_PREVIEW_TOKEN>"}` there, as the app writes it (0600, the
  app user's), then execs `/init`. The token is read in a subshell, so the
  server's environment never has it.
- Not `WEB_AUTH_TOKEN`: that variable always wins and locks the token, so the
  app's own "set a custom token" would refuse. With `auth.json` it keeps
  working, and a token changed there is never overwritten.

`/config` is not a volume (the Dockerfile declares none), so a redeploy that
recreates the container starts with new settings and the generated token again.

Checked locally (2026-11-19, 74c61e78, the image built from the repository's
Dockerfile, the override over a stand-in for the generated service): the
generated token signs in (`POST /login` 302, `/api/auth/status`
`authenticated: true`), a wrong one is refused (200 with the form,
`authenticated: false`); a token set through `/api/setup/set-token` survived a
restart, and the generated one was refused after it.
