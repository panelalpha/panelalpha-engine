# Gameyfin (github.com/gameyfin/gameyfin)

Video game library manager: Spring Boot + Vaadin/Hilla on :8080 (actuator on
:8081), H2 database under /opt/gameyfin/db.

## Why a recipe

No root Dockerfile or compose file, so the engine picks the Java (Gradle)
strategy and compiles from source; the build dies with
`Gradle build daemon disappeared unexpectedly`. Upstream's deployment is the
published image (`docker/docker-compose.example.yml`).

## What the recipe does

- `overrides/docker-compose.yml`: `ghcr.io/gameyfin/gameyfin:2.4.0` (current
  release), named volumes for db, data, plugindata, logs and `/library`.
- `hooks/prepare.sh` generates `APP_KEY` (base64 of 32 random bytes; the app
  exits without a valid AES key) once into `~/.panelalpha/gameyfin/app.env`.
- A bash `/dev/tcp` probe of `/actuator/health` plus a no-op `ready` service
  gate the deploy.

First visit goes to `/setup`, where the admin account is created, as upstream
ships it. A client without cookies loops on `/login` (Gameyfin's session
redirect); browsers are fine.
