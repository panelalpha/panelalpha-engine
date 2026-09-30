# Hypermind

Node/Express live P2P node counter (Hyperswarm), on the compose strategy.

- `overrides/docker-compose.yml` runs `ghcr.io/lklynet/hypermind:1.0.1` with
  port 3000 published; upstream's compose uses `network_mode: host`, which the
  account cannot have (engine#357: stripped with no port published -> 502).
- Stateless: identity and peer list are in memory. Chat/map/themes add-ons are
  off as upstream ships them (`ENABLE_CHAT`, `ENABLE_MAP`, `ENABLE_THEMES`).
