#!/bin/bash
# Runs after the clone, before `docker compose up`. The admin login is the
# engine's (`credentials:` in panelalpha.yaml), written to
# ~/.panelalpha/app-credentials.env before this hook; nothing to generate.
set -e
echo "[movary] prepare complete" >&2
