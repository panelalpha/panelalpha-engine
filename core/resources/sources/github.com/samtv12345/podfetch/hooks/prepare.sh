#!/bin/bash
# After the clone, before `docker compose up`. The admin login is the engine's
# (`credentials:` in panelalpha.yaml), written to
# ~/.panelalpha/app-credentials.env before this hook runs; nothing to generate.
set -e
echo "[panelalpha] podfetch: prepare complete" >&2
