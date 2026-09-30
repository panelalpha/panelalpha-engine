#!/bin/bash
# The admin login is the engine's (`credentials:` in panelalpha.yaml), written
# to ~/.panelalpha/app-credentials.env before this hook runs. The init service
# hashes it into config.yaml only when the data volume has no config yet, so it
# is the first-run password.
set -e
