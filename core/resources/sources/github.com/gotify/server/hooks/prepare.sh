#!/bin/bash
# The login is the engine's (`credentials:` in panelalpha.yaml), written to
# ~/.panelalpha/app-credentials.env before this hook runs.
set -e
cd ~/project

# The compose file lists .env; make sure it exists.
touch .env
