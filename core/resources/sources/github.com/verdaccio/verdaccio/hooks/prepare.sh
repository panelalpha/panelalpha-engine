#!/bin/bash
# The admin login is the engine's (`credentials:` in panelalpha.yaml), written
# to ~/.panelalpha/app-credentials.env before this hook runs. The htpasswd-seed
# service hashes it into storage.
set -e
cd ~/project
