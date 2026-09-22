# Crafty Controller (gitlab.com/crafty-controller/crafty-4)

A self-hosted Minecraft-server launcher/manager: a Python (Tornado) web panel
that creates, starts and administers Minecraft servers, backed by SQLite and
bundling a Java runtime.

## Strategy

`dockerfile`, `FROM` the official upstream image
(`registry.gitlab.com/crafty-controller/crafty-4:latest`). Upstream bakes the
Python venv and five OpenJDK runtimes into that image (~2.5 GB), so the recipe
runs it rather than recompiling the repo's own build. Nothing about Crafty is
patched.

## Wrinkles it solves

- **HTTPS-only app behind an HTTP-forwarding proxy.** Crafty's Tornado server
  listens HTTPS only, on :8443, with a self-signed cert; there is no plain-HTTP
  mode. The engine's proxy terminates TLS and forwards plain HTTP, and the
  generated domain rule always uses `upstream_protocol: http`. The recipe's
  Dockerfile adds a small `stunnel` TLS bridge: plain HTTP on :8000 (the
  published port) is wrapped in TLS to Crafty on 127.0.0.1:8443. Crafty's
  redirects are relative and its cookies are secured by its own TLS socket, so
  the account's public HTTPS reaches the panel cleanly.
- **Generated admin password.** On a fresh install Crafty writes a strong random
  admin password to `app/config/default-creds.txt` (0600). The baked
  `default.json` (`admin`/`crafty`) is rejected as too short (< 8), so the random
  password is the real one. Because `app/config` is the persistent bind mount,
  that file is already in the owner's `~/.panelalpha/crafty/config/` store; the
  entrypoint also writes a clearly named copy,
  `panelalpha-admin-credentials.txt`, beside it. The password is never printed
  to the deploy log.
- **Persistence.** `config/`, `servers/`, `backups/`, `logs/` and `import/` are
  bind-mounted from `~/.panelalpha/crafty`, which survives the per-deploy
  `~/project` wipe (engine#173). Admin login and created server definitions
  persist across a rebuild.

## Files

| File | Purpose |
|---|---|
| `panelalpha.yaml` | dockerfile strategy, `port: 8000`, `dockerfile`/`port_hint` for the pre-probe path |
| `files/Dockerfile` | `FROM` the official image + `stunnel4` + the wrapper entrypoint |
| `files/panelalpha-stunnel.conf` | stunnel client bridge, plain :8000 -> TLS :8443 |
| `files/panelalpha-entrypoint.sh` | starts the bridge, captures the admin creds, execs Crafty's launcher |
| `overrides/docker-compose.override.yml` | the five persistent bind mounts + a memory ceiling |
| `hooks/prepare.sh` | creates `~/.panelalpha/crafty/*` and writes `.env` |

## Login

`admin` / the password in `~/.panelalpha/crafty/config/panelalpha-admin-credentials.txt`
(or `default-creds.txt` beside it).
