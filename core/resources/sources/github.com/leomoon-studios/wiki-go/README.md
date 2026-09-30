# Wiki-Go

Flat-file Markdown wiki written in Go, no database. Runs from upstream's
`leomoonstudios/wiki-go` image.

Upstream: <https://github.com/leomoon-studios/wiki-go>

## What this recipe does

The repository has no `docker-compose.yml` (only `docker-compose-http.yml` and
`docker-compose-ssl.yml` examples for `:latest`). On first start Wiki-Go
writes `data/config.yaml` with the built-in `admin` / `admin`.
`overrides/docker-compose.yml` runs:

| Service | What it is |
|---|---|
| `init` | `httpd:2.4-alpine` (for `htpasswd`), one-shot: seeds `config.yaml` while the volume has none |
| `app` | `leomoonstudios/wiki-go:1.9.2`, the current release, on port 8080 |
| `ready` | no-op gated on the app's healthcheck |

The login (`WIKIGO_ADMIN_USER=admin`, `WIKIGO_ADMIN_PASSWORD`) is declared under
`credentials:` in `panelalpha.yaml`; the engine generates it and writes
`~/.panelalpha/app-credentials.env`.
`files/panelalpha/wikigo-init.sh` hashes it (bcrypt, cost 12) into a minimal
`config.yaml`; Wiki-Go adds every other setting with its defaults on start.
Once `config.yaml` exists, `init` does nothing, so a password changed in the
UI is kept.

## Login

User `admin`; `GET /projects/{name}/app-credentials` (MCP `app_credentials_get`)
returns the password.

## Seeded settings

| Setting | Why |
|---|---|
| `allow_insecure_cookies: false` | the site is only served over HTTPS |
| `trusted_proxies: ["172.16.0.0/12"]` | the engine proxy reaches the app through the account's Docker bridge. Wiki-Go then takes the last untrusted `X-Forwarded-For` hop, so the login ban (5 failures in 180s) hits the visitor, not every visitor at once, and a spoofed header is ignored |
| `owner` | the site's domain |
| `private: false` | see below |

## Public read

The wiki is deliberately readable without login, which is Wiki-Go's default
and what a wiki is for. Creating, editing, commenting and uploading return 401
without a login, and the settings, users, access-rules and backup APIs return
403. Admin > Settings > Private makes the whole wiki login-only. Access rules
can do the same per path.

## Persistence

Everything (pages, uploads, users, settings, sessions, versions) is on the
`data` volume at `/wiki/data`, which survives redeploys.

## Memory

Limit 256 MB; idle use is about 9 MB.
