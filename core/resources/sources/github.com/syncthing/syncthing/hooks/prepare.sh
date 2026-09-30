#!/bin/bash
# The GUI login is the engine's (`credentials:` in panelalpha.yaml), written to
# ~/.panelalpha/app-credentials.env before this hook runs; only the seed service
# reads it, and Syncthing keeps a bcrypt hash in config.xml.
set -e
cd ~/project

# The compose file lists .env; the seed script runs as the image's uid 1000.
touch .env
chmod +r panelalpha-seed.sh
