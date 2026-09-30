#!/bin/bash
# The owner login is the engine's (`credentials:` in panelalpha.yaml), written
# to ~/.panelalpha/app-credentials.env before this hook runs.
set -e
cd ~/project

# The older recipe's note names the owner email the seed has since moved to the
# engine's; the login is returned by GET /projects/{name}/app-credentials.
rm -f "${HOME}/.panelalpha/overseerr/credentials.txt"

touch .env
