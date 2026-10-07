# Hoard (github.com/rleeon/hoard)

Game-save backup and sync server. One Rust container on :12421 serving the
sync API for the desktop app/CLI and a web panel at `/panel`; SQLite and the
save snapshots live in `/var/lib/hoard`.

## Deploying

No variables are required: the site opens the panel's sign-in page. To get
the first account, either set the project environment variables
`HOARD_ADMIN_USERNAME` and `HOARD_ADMIN_PASSWORD` before the first deploy
(upstream reads them only while the database has no users, and prints a
device token for the desktop app in the log once), or create one afterwards:

```
docker compose -p project exec app hoard-admin --config /etc/hoard/config.toml user create <name> --admin --password '<password>'
```

The panel's login throttle is keyed on the client address, and Hoard only
believes `X-Forwarded-For` from `trusted_proxies` (default: loopback). Behind
the engine every visitor arrives from the proxy, so all of them share one
throttle bucket. Trusting the proxy instead would read the first, client-supplied
`X-Forwarded-For` entry, so the default is kept.

## What the recipe does

- `overrides/docker-compose.yml` is upstream's single-node compose with
  `ghcr.io/rleeon/hoard:1.2.0` and named volumes `hoard-data`
  (`/var/lib/hoard`) and `hoard-config` (`/etc/hoard`), both kept across
  redeploys. The project's `.env` (where the engine writes the project's
  environment variables) is the service's `env_file`, which is how the two
  optional admin variables reach it.
