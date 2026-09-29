#!/usr/bin/env bash
# Engine 2.x — legacy int-updater is a redirect to the onboarded updater stub,
# which hands off to get.panelalpha.com → GitHub. Do not zip-update from Connect.
set -euo pipefail
exec bash "$(cd "$(dirname "$0")" && pwd)/updater.sh" "$@"
