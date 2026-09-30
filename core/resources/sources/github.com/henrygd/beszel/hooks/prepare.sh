#!/bin/bash
# The first user's login is the engine's (`credentials:` in panelalpha.yaml),
# written to ~/.panelalpha/app-credentials.env before this hook runs. Beszel's
# initial migration reads it.
set -e
cd ~/project

# The compose file lists .env; make sure it exists.
touch .env
