# Tiledesk (github.com/tiledesk/tiledesk)

Live-chat and chatbot customer engagement platform (Tiledesk Community):
dashboard, design studio, web widget, agent chat, chat21 MQTT messaging.

## Deploying

The default 2500 MB is enough (about 2.0 GB in use at idle across 15
containers). No project environment variables are needed; set `EMAIL_*`,
`GPTKEY` or `LICENSE_KEY` as project variables if you use those features.
Open the site (it redirects to `/dashboard/`) and sign in with upstream's
default admin `admin@tiledesk.com` / `superadmin`, then change it.

Upstream's compose ships fixed JWT secrets (`tokenKey`) and pre-signed
RabbitMQ tokens that match its chat21-rabbitmq image; the recipe keeps them
as upstream does.

## What the recipe does

- `overrides/docker-compose.yml` is upstream's `docker-compose/docker-compose.yml`
  (the file its README deploys) with the same services and image tags.
- Only `proxy` (upstream's nginx router for `/dashboard`, `/api`, `/widget`,
  `/chat`, `/cds`, `/chatapi`, `/ws`, `/mqws`) publishes a port. A plain
  deploy publishes every service and the site lands on the API (3000).
- `EXTERNAL_BASE_URL` becomes the site's address and the MQTT endpoint
  `wss://<domain>/mqws/ws` (upstream defaults to `localhost:8081`).
- The rabbitmq health check gets a 60 s start period and 30 retries; upstream
  allows one 5-second probe and the plain deploy failed on it
  (`The service rabbitmq did not start (unhealthy)`).
- `qdrant:latest` is pinned to v1.19.1 and `chat21/chat21-rabbitmq` (only a
  `latest` tag, 2021-08-03) by digest. Qdrant storage moved from a `./` bind
  to a named volume; MongoDB keeps upstream's named volume.
- The two LLM backends run `WORKERS=1` instead of 3 (1.25 GB each at 3).

The `*.panelalpha.online` test front strips websocket upgrades; the MQTT
websocket answers `101` through the host directly.
