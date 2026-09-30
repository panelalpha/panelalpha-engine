# OctoBot (github.com/Drakkar-Software/OctoBot)

Self-hosted cryptocurrency trading bot. Since 3.0 the image starts in node
mode: the UI ("OctoBot Node", FastAPI + SPA) is on port 8000 at `/app`; the
classic web interface on 5001 is disabled.

## What the recipe does

- `overrides/docker-compose.yml` replaces the repo compose (which maps host 80
  to the dead 5001 and runs a watchtower needing the host Docker socket) with
  `drakkarsoftware/octobot:3.0.0-beta2`, publishing only 8000.
- `/octobot/user` (config, node wallets, profiles), `/octobot/tentacles`,
  `/octobot/logs` and `/octobot/backtesting` are named volumes; the scheduler
  SQLite file is moved into the user volume via `SCHEDULER_SQLITE_FILE`.
- The image healthcheck (5001) is replaced with one on `/app`; `ready` makes
  `compose up -d` wait for it.

First visit shows the node setup (create or import the admin wallet), as
upstream ships it.
