#!/usr/bin/env bash
# Engine 2.x onboarded updater — thin redirect to get.panelalpha.com → GitHub.
# Legacy Connect/license zip self-update is gone; v1 stays on license.panelalpha.com.
#
# Keeps the old entry points working:
#   bash /opt/panelalpha/shared-hosting/updater.sh -f --background
#   System::runUpdateScript()
set -euo pipefail

GET_BASE="${PANELALPHA_GET_BASE:-https://get.panelalpha.com}"
GET_BASE="${GET_BASE%/}"

forward=()
while [[ $# -gt 0 ]]; do
    case "$1" in
    --background)
        forward+=(--background)
        shift
        ;;
    --version | --engine-version)
        [[ $# -ge 2 ]] || {
            echo "[ERROR] $1 requires a value" >&2
            exit 1
        }
        forward+=(--engine-version "$2")
        shift 2
        ;;
    --version=* | --engine-version=*)
        forward+=(--engine-version "${1#*=}")
        shift
        ;;
    --no-self-update)
        forward+=(--no-self-update)
        shift
        ;;
    --no-tui)
        forward+=(--no-tui)
        shift
        ;;
    --package-host)
        shift 2
        ;;
    --package-host=*)
        shift
        ;;
    -f | --force | -h | --help)
        shift
        ;;
    -*)
        echo "[WARN] Ignoring unknown flag: $1" >&2
        shift
        ;;
    *)
        # Positional license key from the API — unused for OSS engine updates.
        shift
        ;;
    esac
done

dir="$(mktemp -d)"
# set -e leaves on a failed update; the trap removes the downloads either way
# and keeps the exit code.
trap 'rm -rf "$dir"' EXIT
echo "[INFO] Redirecting Engine update to ${GET_BASE}/engine (GitHub)..."
if ! curl -fsSL --max-time 60 -o "$dir/engine" "${GET_BASE}/engine"; then
    echo "[ERROR] Failed to fetch ${GET_BASE}/engine" >&2
    exit 1
fi
# The bootstrap runs a get.sh found beside it. Otherwise it downloads get.sh to
# a temp file and execs it, which skips its own cleanup and leaves the file.
# A private directory also means nobody else's get.sh can be the one beside it.
curl -fsSL --max-time 60 -o "$dir/get.sh" "${GET_BASE}/get.sh" || rm -f "$dir/get.sh"

# Always non-interactive here: API / at(1) have no TTY. get-engine sets ENTRY=engine;
# with shared-hosting present the wrapper chooses update.
# Not exec: the trap must outlive the installer to remove it.
sh "$dir/engine" --no-tui "${forward[@]}"
