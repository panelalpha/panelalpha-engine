#!/bin/bash
# The login is the engine's (`credentials:` in panelalpha.yaml), written to
# ~/.panelalpha/app-credentials.env before this hook runs. The collector runs
# as the image's `symon` user, which must be able to read the wrapper.
set -e
cd ~/project

chmod a+r panelalpha-symon-start.sh
