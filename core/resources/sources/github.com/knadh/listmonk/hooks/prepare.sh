#!/bin/bash
# The Super Admin login is the engine's (`credentials:` in panelalpha.yaml),
# written to ~/.panelalpha/app-credentials.env before this hook runs. This hook
# only makes sure ~/.panelalpha/listmonk/ exists.
set -e

DATA_HOME="${HOME}/.panelalpha/listmonk"

mkdir -p "${DATA_HOME}"
chmod 700 "${DATA_HOME}"
