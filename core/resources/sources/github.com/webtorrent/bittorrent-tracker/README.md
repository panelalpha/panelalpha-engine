# bittorrent-tracker (github.com/webtorrent/bittorrent-tracker)

The WebTorrent project's BitTorrent tracker server: HTTP tracker, WebSocket
tracker (for WebTorrent browser clients) and a stats page, on one port.

## Deploying

Nothing is required. Once deployed:

- HTTP tracker: `https://<domain>/announce` (and `/scrape`)
- WebSocket tracker: `wss://<domain>`
- Stats: `https://<domain>/stats` (HTML) or `/stats.json`

`/` answers a bencoded `invalid action in HTTP request` (HTTP 200); that is
the tracker's own response to a request that is not an announce or scrape.

## What the recipe does

- Builds with the express platform (`npm install --omit=dev`; the repo has
  no lockfile) and starts the package's own CLI, `bin/cmd.js`, with
  `--http --ws --trust-proxy` on port 3000. The plain deploy ran `node
  index.js`, which is the library entry point and exits at once.
- No UDP tracker: an account has no inbound UDP.
- `--trust-proxy` records each peer's address from `X-Forwarded-For` instead
  of the account gateway's. The tracker takes the first entry, which a
  client can set itself.
- Swarm state is in memory only; nothing is persisted.
