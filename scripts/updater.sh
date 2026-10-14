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

# Exits with an error unless the flag <name> still has a value after it.
require_value() { # <args left> <name>
    [[ $1 -ge 2 ]] || {
        echo "[ERROR] $2 requires a value" >&2
        exit 1
    }
}

# The old updater's flags, as get.panelalpha.com's installer takes them, into
# FORWARD. The positional license key from the API is unused for OSS engine updates.
parse_args() {
    while [[ $# -gt 0 ]]; do
        case "$1" in
        --background)
            forward+=(--background)
            shift
            ;;
        --version | --engine-version)
            require_value $# "$1"
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
            require_value $# "$1"
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
            shift
            ;;
        esac
    done
}

# HTTPS only, redirects included: what is fetched here runs as root. Nothing
# checks a signature yet, so TLS to get.panelalpha.com is all that vouches for it.
fetch() { curl -fsSL --proto '=https' --max-time 60 -o "$1" "$2"; }

# Fetches ${GET_BASE}/<page> into <dir>/<page>, or says so and exits.
fetch_or_exit() { # <dir> <page>
    fetch "$1/$2" "${GET_BASE}/$2" || {
        echo "[ERROR] Failed to fetch ${GET_BASE}/$2" >&2
        exit 1
    }
}

# Into a private directory, so nobody else's get.sh can be the one beside the
# bootstrap, which runs it; otherwise it would fetch get.sh over a curl that
# follows a redirect to plain http. A failed fetch stops the update.
download_installer() { # <dir>
    fetch_or_exit "$1" engine
    fetch_or_exit "$1" get.sh
}

# Always non-interactive: the API and at(1) have no TTY. get-engine sets
# ENTRY=engine; with shared-hosting present the wrapper chooses update.
run_installer() { # <dir>
    sh "$1/engine" --no-tui "${forward[@]}"
}

forward=()
parse_args "$@"

dir="$(mktemp -d)"
# set -e leaves on a failed update; the trap removes the downloads either way
# and keeps the exit code. The installer is not exec'd, so the trap outlives it.
trap 'rm -rf "$dir"' EXIT
echo "[INFO] Redirecting Engine update to ${GET_BASE}/engine (GitHub)..."
download_installer "$dir"
run_installer "$dir"
