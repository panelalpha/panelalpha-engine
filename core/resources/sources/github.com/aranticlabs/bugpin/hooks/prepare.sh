#!/bin/bash
set -e
cd ~/project

# BugPin persists everything it needs on the /data named volume (SQLite DB,
# uploads, and its own auto-generated .secret signing key). The admin login is
# the engine's (`credentials:` in panelalpha.yaml), written to
# ~/.panelalpha/app-credentials.env before this hook runs.

# Pre-pull so the engine's `docker compose up -d` starts instantly instead of
# blocking the deploy on a first-time image fetch. The image is anonymously
# pullable from the project's own registry.
docker pull registry.arantic.cloud/bugpin/bugpin:latest
