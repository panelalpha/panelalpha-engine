# Jackett (github.com/Jackett/Jackett)

Torznab/TorrentPotato proxy for the *arr applications: Sonarr, Radarr and the
rest send it searches, it runs them against tracker sites, and a web UI on 9117
configures the indexers. .NET, no database; all state is JSON under
`/config/Jackett`.

## Strategy

The repository has no Dockerfile and no compose file (a .NET solution,
installers, service scripts). `overrides/docker-compose.yml` runs the
linuxserver.io image the README points Docker users to, pinned to the current
release (`linuxserver/jackett:0.24.2685`, the Docker Hub name of
`lscr.io/linuxserver/jackett`). The image runs `jackett --NoUpdates` unless
`AUTO_UPDATE=true`, so a redeploy decides the version, not the app.

`/config` is a named volume: `ServerConfig.json`, `Indexers/*.json` (indexer
credentials) and `DataProtection/` (the keys that sign login cookies, so
sessions survive a rebuild).

## Security: upstream has no password

`SecurityService.CheckAuthorised()` returns true for everyone while
`AdminPassword` is empty, which is how Jackett starts. The whole UI, every
indexer's credentials and the API key are then open to whoever has the
address.

- The admin password is declared in `panelalpha.yaml` (`credentials:`): the
  engine generates it once and writes `~/.panelalpha/app-credentials.env`
  before the prepare hook on every deploy (`adopt_from` keeps the password of
  an account seeded from `~/.panelalpha/jackett/jackett.env`).
- `hooks/prepare.sh` generates the API key once into
  `~/.panelalpha/jackett/jackett.env` (0600 in a 0700 dir).
- The one-shot `init` service (same image, `files/panelalpha/jackett-seed.sh`)
  writes `ServerConfig.json` before Jackett first boots, **only if it is
  absent**: Jackett's own first-boot defaults, the API key, `AdminPassword` as
  Jackett stores it (`SecurityService.HashPassword()`: SHA512 over UTF-16LE of
  password + APIKey, lowercase hex), `UpdateDisabled`, and `BaseUrlOverride` =
  the public URL so Torznab links point at the account. A password changed
  later in the UI is never reset by a redeploy (the engine then keeps
  returning the seeded one).
- `ready` gates `compose up -d` on the app's healthcheck (`/UI/Login` 200).

Log in at the site with the password `GET /projects/{name}/app-credentials`
(MCP `app_credentials_get`) returns; the API key for the *arr apps is shown on
the dashboard.

## Verified (10.10.10.25, engine 705f250a, memory_limit 2000)

- Deploy 62s, rebuild 45s; app at ~100 MB.
- Anonymous `/` ends on `/UI/Login?cookiesChecked=1` (`<title>Jackett</title>`,
  a password field); `/UI/Dashboard`, `/api/v2.0/server/config`,
  `/api/v2.0/indexers` all 302 to the login page.
- `/api/v2.0/indexers/all/results?apikey=wrong` 401; the seeded key 200.
- Wrong password: still 302 to login. Seeded password: dashboard 200 (44 KB),
  config API 200 with the seeded API key.
- Round trip: `POST /api/v2.0/server/config` (cache_ttl 1500, blackholedir
  `/config`) read back; indexer `nyaasi` configured, Torznab search returned
  a real release through the public URL.
- After `rebuild`: init printed `ServerConfig.json exists; left alone`,
  `jackett.env` checksum unchanged, the pre-rebuild session cookie still
  valid, the new password login works, config values and the indexer kept.
- `/.env`, `/.git/config`, `/ServerConfig.json` all 404.
