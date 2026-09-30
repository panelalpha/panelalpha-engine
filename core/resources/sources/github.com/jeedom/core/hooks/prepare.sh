#!/bin/bash
# The Jeedom admin login is the engine's (`credentials:` in panelalpha.yaml),
# written to ~/.panelalpha/app-credentials.env before this hook runs.
# seed.php puts it in place of admin/admin.
set -e
cd ~/project

say() { echo "[panelalpha] jeedom: $*" >&2; }
say "prepare complete"
