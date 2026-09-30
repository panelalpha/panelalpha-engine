#!/bin/bash
# The UISP admin login is the engine's (`credentials:` in panelalpha.yaml),
# written to ~/.panelalpha/app-credentials.env before this hook runs.
set -e
cd ~/project

touch .env
