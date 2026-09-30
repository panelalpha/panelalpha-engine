#!/bin/bash
# The login is the engine's now (`credentials:` in panelalpha.yaml), written to
# ~/.panelalpha/app-credentials.env before this hook runs.
set -e
cd ~/project

touch .env
chmod +r panelalpha/ombi-seed.sh
