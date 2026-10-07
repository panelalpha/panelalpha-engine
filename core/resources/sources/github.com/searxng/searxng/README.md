# SearXNG

Privacy-respecting metasearch engine. Public by design: no accounts, no stored
user data, so there is no login to seed.

## What the recipe does

- Runs upstream's own deployment shape (`container/docker-compose.yml`): the
  official `docker.io/searxng/searxng` image, pinned to the current release tag,
  plus `valkey/valkey:9-alpine`. The repository root is the source tree, which
  detection would otherwise build as a server-less Python app (the engine
  placeholder page).
- `settings.yml` and `limiter.toml` are recipe files mounted read-only at
  `/etc/searxng`:
  - `server.public_instance: true` — upstream's mode for an instance on the open
    internet. It forces the limiter, the `link_token` check and the image proxy,
    and SearXNG exits if Valkey is unreachable (fails closed).
  - `search.formats: [html]` — the JSON/CSV/RSS API stays closed (403).
  - `trusted_proxies` covers the private ranges of the engine's proxy chain, so
    the limiter counts each visitor's own address (rightmost untrusted
    `X-Forwarded-For` hop) rather than the proxy's.
- `server.secret_key` comes from `SEARXNG_SECRET`, generated once by
  `hooks/prepare.sh` into `~/.panelalpha/searxng/secret.env` (0600 in 0700) and
  reused on every redeploy. `server.base_url` is `${PA_PUBLIC_URL}/`.
- A `ready` gate holds the deploy until `/healthz` answers.

## Test domains (`*.panelalpha.online`)

Searches return `429 Too Many Requests` on a `*.panelalpha.online` name. That
name resolves to the WithoutDNS edge (`eu1.withoutdns.com`), which forwards
every request with `Accept-Encoding: identity`; SearXNG's limiter rejects a
`/search` whose `Accept-Encoding` has neither `gzip` nor `deflate`
(`searx/botdetection/http_accept_encoding.py`). The same edge also puts its own
address last in `X-Forwarded-For`, so every visitor would share one rate-limit
bucket. On the account's own domain (direct to the engine host) both are
correct.

The engine's health probe reports `HTTP 429` for `/`: the limiter rejects its
non-browser User-Agent, which is the protection working.
