# KOReader Sync Server (github.com/koreader/koreader-sync-server)

Reading-progress sync API for KOReader: OpenResty/Lua with Redis inside one
container. There is no web UI.

## Deploying

Nothing to set. In KOReader: Tools -> Progress sync -> Custom sync server ->
`https://<your domain>`, then Register / Login. Set the project environment
variable `ENABLE_USER_REGISTRATION=false` after your devices have accounts to
close sign-up.

Check it answers:

```
curl -H "Accept: application/vnd.koreader.v1+json" https://<your domain>/healthcheck
{"state":"OK"}
```

## What the recipe does

- `overrides/docker-compose.yml` runs `koreader/kosync:v2.1.1` and publishes
  its plain-HTTP port 17200 instead of the self-signed HTTPS port 7200 the
  repository's compose publishes (the engine terminates TLS itself).
- Redis data (users and progress) is on the named volume `redis-data`, kept
  across redeploys.
