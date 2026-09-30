# Agent Zero (github.com/agent0ai/agent-zero)

Autonomous AI agent framework with a web chat UI. The image bundles the UI
(port 80), SearXNG, cron and sshd under supervisord.

## Deploying

Deploy as is. Open the site, then add an LLM provider key under Settings.
Login protection is the app's own UI login (Settings, stored in
`/a0/usr/.env` as `AUTH_LOGIN` / `AUTH_PASSWORD`); upstream ships it off.

## What the recipe does

- `overrides/docker-compose.yml` runs `agent0ai/agent-zero:v2.13` (the current
  release) on port 80 with `/a0/usr` on the named volume `a0-usr`, the mount
  upstream documents (`docker run -p 80:80 -v a0_usr:/a0/usr agent0ai/agent-zero`).
- A one-shot `ready` service waits for the UI to answer, so the deploy finishes
  only once it serves.

The image is about 3.1 GB compressed, so the first deploy spends most of its
time pulling it.

## Notes

- With login off, the app adds the origin of the first browser visit to
  `ALLOWED_ORIGINS` in `/a0/usr/.env` and refuses other origins (upstream
  behaviour). A request without an `Origin`/`Referer` header gets "Origin None
  not allowed"; a browser does not.
- Idle memory is about 1.2-1.6 GB (UI and tunnel processes preload models), so
  the app is capped at 2 GB; an account of 2500 MB fits it.
