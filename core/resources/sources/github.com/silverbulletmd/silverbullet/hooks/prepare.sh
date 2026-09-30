#!/bin/bash
# The admin login is the engine's (`credentials:` in panelalpha.yaml), written to
# ~/.panelalpha/app-credentials.env before this hook runs; without it the first
# visitor would claim the setup wizard.
set -e
cd ~/project

# The compose file lists .env; make sure it exists.
touch .env
