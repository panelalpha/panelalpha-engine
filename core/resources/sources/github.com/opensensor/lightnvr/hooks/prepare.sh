#!/bin/bash
# The admin login is the engine's (`credentials:` in panelalpha.yaml), written
# to ~/.panelalpha/app-credentials.env before this hook runs. Its 24 characters
# fit LightNVR's [web] password buffer (32 bytes, anything over 31 truncated).
set -e
cd ~/project

chmod +r panelalpha-seed.sh
