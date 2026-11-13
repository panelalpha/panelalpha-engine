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

# Trusted release-signing public keys, one PEM per file; once one is shipped every download is verified.
KEY_DIR="${PANELALPHA_RELEASE_KEY_DIR:-$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")" && pwd)/release-keys}"

usage() {
    cat <<'USAGE'
Usage: bash updater.sh [options]

Updates this engine to the newest release: the engine's code is replaced and
the engine stack restarts, so API and MCP clients disconnect for about a
minute. Hosted sites keep running.

      --version REF      install this git ref (default: main, the newest release)
      --background       let the installer run on after this script exits
      --no-self-update   run get.panelalpha.com's installer as downloaded
  -f, --force            accepted for older callers; ignored
  -h, --help             print this and exit
USAGE
    exit 0
}

# Usage comes before anything else, whatever the other arguments are.
wants_help() {
    local arg
    for arg in "$@"; do
        case "$arg" in
        -h | --help) return 0 ;;
        esac
    done
    return 1
}

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
        -f | --force)
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

# HTTPS only, redirects included: what is fetched here runs as root.
fetch() { curl -fsSL --proto '=https' --max-time 60 -o "$1" "$2"; }

# Fetches ${GET_BASE}/<page> into <dir>/<page>, or says so and exits.
fetch_or_exit() { # <dir> <page>
    fetch "$1/$2" "${GET_BASE}/$2" || {
        echo "[ERROR] Failed to fetch ${GET_BASE}/$2" >&2
        exit 1
    }
}

has_release_keys() { compgen -G "$KEY_DIR/*.pem" >/dev/null; }

# Whether a pinned key verifies <file>.sig, an `openssl dgst -sha256 -sign` signature.
signed_by_a_release_key() { # <file>
    local key
    for key in "$KEY_DIR"/*.pem; do
        openssl dgst -sha256 -verify "$key" -signature "$1.sig" "$1" >/dev/null 2>&1 && return 0
    done
    return 1
}

# Fails closed once a key is pinned: no signature, no openssl or a bad one stops the update.
verify_download() { # <dir> <page>
    if ! has_release_keys; then
        echo "[WARN] No release-signing key is pinned in ${KEY_DIR}; ${GET_BASE}/$2 is not verified." >&2
        return 0
    fi
    command -v openssl >/dev/null || {
        echo "[ERROR] openssl is needed to verify ${GET_BASE}/$2; nothing was run." >&2
        exit 1
    }
    fetch_or_exit "$1" "$2.sig"
    signed_by_a_release_key "$1/$2" || {
        echo "[ERROR] ${GET_BASE}/$2 does not match its signature; nothing was run." >&2
        exit 1
    }
}

# Into a private directory, so nobody else's get.sh can be the one beside the
# bootstrap, which runs it; otherwise it would fetch get.sh over a curl that
# follows a redirect to plain http. A failed fetch or check stops the update.
download_installer() { # <dir>
    fetch_or_exit "$1" engine
    fetch_or_exit "$1" get.sh
    verify_download "$1" engine
    verify_download "$1" get.sh
}

# Always non-interactive: the API and at(1) have no TTY. get-engine sets
# ENTRY=engine; with shared-hosting present the wrapper chooses update.
run_installer() { # <dir>
    sh "$1/engine" --no-tui "${forward[@]}"
}

wants_help "$@" && usage
forward=()
parse_args "$@"

dir="$(mktemp -d)"
# set -e leaves on a failed update; the trap removes the downloads either way
# and keeps the exit code. The installer is not exec'd, so the trap outlives it.
trap 'rm -rf "$dir"' EXIT
echo "[INFO] Redirecting Engine update to ${GET_BASE}/engine (GitHub)..."
download_installer "$dir"
run_installer "$dir"
