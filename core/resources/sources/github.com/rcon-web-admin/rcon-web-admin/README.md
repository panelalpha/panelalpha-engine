# RCON Web Admin

Node web UI for RCON game-server administration, on the engine's `express`
platform (host-compiled `node_modules`).

- Started as `node src/main.js start` (the app's CLI needs the subcommand).
- HTTP 4326 and WebSocket 4327 are merged behind the published port 3000 by an
  nginx sidecar sharing the app's network namespace (`files/panelalpha/rcon-proxy.conf`);
  `RWA_WEBSOCKET_URL_SSL=wss://<domain>/websocket` tells the browser where to connect.
- `db/` and `public/widgets/` are named volumes. Core widgets are installed once
  on first boot with `node src/main.js install-core-widgets` (downloads from GitHub).
- First visit: the app's own panel to create the first user.
- The sidecar joins the app container's network namespace; if the app
  container is recreated, compose recreates the sidecar with it.
