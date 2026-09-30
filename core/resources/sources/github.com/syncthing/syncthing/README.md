# Syncthing (github.com/syncthing/syncthing)

Continuous peer-to-peer file sync. One Go binary: web GUI + REST API on
`:8384`, sync protocol on `:22000`. Everything lives under `/var/syncthing`.

## What the recipe does

- `overrides/docker-compose.yml` replaces the source-build Dockerfile deploy
  with `syncthing/syncthing:2.1.5`. `/var/syncthing` (device keys, config.xml,
  database, the default `Sync` folder) is a named volume.
- **GUI is never open:** `hooks/prepare.sh` writes
  `~/.panelalpha/syncthing/admin.env` once (`admin`, random hex password).
  `files/panelalpha-seed.sh` runs as the `seed` service before the app starts:
  if config.xml has no password it runs `syncthing generate --gui-user
  --gui-password -` (bcrypt), then fails unless a user and bcrypt hash are
  present. A password changed later in the GUI is left alone.
- `STGUIADDRESS=0.0.0.0:8384` overrides config.xml's `127.0.0.1:8384` at
  runtime. Syncthing's Host-header (DNS-rebinding) check is only applied to a
  loopback GUI address, so the proxy's public Host is accepted with
  `insecureSkipHostcheck` left off.
- The API key is Syncthing's own, generated once into config.xml
  (`<apikey>`); it is shown in Settings > General.

## Sync traffic

Only 8384 is published. Port 22000 (TCP + QUIC/UDP) and local discovery
(21027/udp) are not reachable from outside the account, so other devices
cannot dial in directly. The instance still connects outward and through the
public relay pool (relaying is on by default), so syncing works, at relay
speed.

## Login

`admin` / the password in `~/.panelalpha/syncthing/admin.env`. To reset it,
delete `<password>` from config.xml in the volume and redeploy, or run
`syncthing generate --gui-password=...` inside the app container and restart.
