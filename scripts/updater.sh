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

SCRIPTS_DIR="$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")" && pwd)"
# Trusted release-signing public keys, one PEM per file; once one is shipped every download is verified.
KEY_DIR="${PANELALPHA_RELEASE_KEY_DIR:-$SCRIPTS_DIR/release-keys}"
# The running engine, whose release the target is compared with.
ENGINE_DIR="${PANELALPHA_ENGINE_DIR:-$(dirname "$SCRIPTS_DIR")}"
LOGS_DIR="${PANELALPHA_ENGINE_UPDATE_LOGS:-/opt/panelalpha/log/engine-updates}"
# The release get.panelalpha.com's installer clones unless --version names another.
ENGINE_REPO="${PANELALPHA_ENGINE_REPO:-https://github.com/panelalpha/engine.git}"
TARGET_REF="${PANELALPHA_ENGINE_VERSION:-main}"
CHECK_ONLY=0
# Passed to the installer through the environment: an older one stops at a flag it does not know.
ALLOW_DOWNGRADE="${PANELALPHA_ALLOW_DOWNGRADE:-0}"
REINSTALL="${PANELALPHA_REINSTALL:-0}"

usage() {
    cat <<'USAGE'
Usage: bash updater.sh [options]

Updates this engine to the newest release: the engine's code is replaced and
the engine stack restarts, so API and MCP clients disconnect for about a
minute. Hosted sites keep running. A release older than the running one is
refused, and a target that is the running release changes nothing.

      --version REF      install this git ref (default: main, the newest release)
      --check            say whether an update is available and change nothing
      --reinstall        install the target even when it is the running release
      --allow-downgrade  install the target even when it is older than the
                         running release
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
            TARGET_REF="$2"
            forward+=(--engine-version "$2")
            shift 2
            ;;
        --version=* | --engine-version=*)
            TARGET_REF="${1#*=}"
            forward+=(--engine-version "${1#*=}")
            shift
            ;;
        --check)
            CHECK_ONLY=1
            shift
            ;;
        --allow-downgrade)
            ALLOW_DOWNGRADE=1
            shift
            ;;
        --reinstall)
            REINSTALL=1
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

redact() { printf '%s' "$1" | sed -E 's#(https?://)[^/@]+@#\1***@#'; }

# The product version a core/config/system.php declares, without a leading v; empty when unreadable.
declared_version() { # <system.php>
    [ -f "$1" ] || return 0
    sed -nE "s/.*'version'[[:space:]]*=>[[:space:]]*'([^']+)'.*/\1/p" "$1" | head -n1 | sed -E 's/^[vV]//'
}

# The short commit the engine tree was installed from, as its version file holds it; empty when unknown.
installed_commit() { # <version file>
    local raw
    [ -f "$1" ] || return 0
    raw=$(tr -d '\r\n' <"$1")
    [ "$raw" = unknown ] || printf '%.8s' "$raw"
}

# Sets target and target_commit: the version the release at <ref> declares and its short commit, read from a
# shallow clone of its config alone. Either is empty when it cannot be read.
read_target() { # <repo> <ref>
    local tmp
    target='' target_commit=''
    command -v git >/dev/null || return 0
    tmp=$(mktemp -d)
    if GIT_TERMINAL_PROMPT=0 git clone --quiet --depth 1 --filter=blob:none --sparse --branch "$2" -- "$1" "$tmp/repo" >/dev/null 2>&1; then
        git -C "$tmp/repo" sparse-checkout set core/config >/dev/null 2>&1 || true
    else
        GIT_TERMINAL_PROMPT=0 git clone --quiet --depth 1 --branch "$2" -- "$1" "$tmp/repo" >/dev/null 2>&1 || true
    fi
    target=$(declared_version "$tmp/repo/core/config/system.php")
    target_commit=$(git -C "$tmp/repo" rev-parse HEAD 2>/dev/null | cut -c1-8) || target_commit=''
    rm -rf "$tmp"
}

# Whether both commits are known and equal: only then is the running release exactly the target.
same_commit() { [ -n "$running_commit" ] && [ "$running_commit" = "$target_commit" ]; }

# older, same or newer: the target release against the running one.
release_order() { # <running> <target>
    if [ "$1" = "$2" ]; then
        echo same
    elif [ "$(printf '%s\n%s\n' "$1" "$2" | sort -V | head -n1)" = "$2" ]; then
        echo older
    else
        echo newer
    fi
}

# A run that stops here never reaches the installer, so it writes the record core reads as latest_update.
record_stop() { # <exit code> <result> <reason>
    local run="$LOGS_DIR/$(date +%s)-$$"
    {
        mkdir -p "$run" &&
            echo $$ >"$run/pid" &&
            printf '%s' "$running" >"$run/from_version" &&
            printf '%s' "$target" >"$run/to_version" &&
            printf '%s' "$running_commit" >"$run/from_commit" &&
            printf '%s' "$target_commit" >"$run/to_commit" &&
            printf '%s' "$2" >"$run/result" &&
            printf '%s' "$3" >"$run/reason" &&
            printf '%s\n' "$3" >"$run/stdout" &&
            echo "$1" >"$run/exit_code" &&
            ln -sfn "$run" "$LOGS_DIR/latest"
    } 2>/dev/null || true
}

stop() { # <exit code> <result> <reason>
    if [ "$1" = 0 ]; then
        echo "[INFO] $3"
    else
        echo "[ERROR] $3" >&2
    fi
    record_stop "$@"
    exit "$1"
}

# What --check prints: both versions and what an update would do. Changes nothing.
report_check() {
    echo "Running:   ${running:-unknown} (${running_commit:-unknown commit})"
    echo "Available: ${target:-unknown} (${target_commit:-unknown commit}, ${TARGET_REF})"
    if [ -z "$target" ]; then
        echo "Could not read the release version at ${TARGET_REF} from $(redact "$ENGINE_REPO")." >&2
        exit 1
    fi
    case "$(release_order "${running:-0}" "$target")" in
    newer) echo "An update is available." ;;
    same)
        if same_commit; then
            echo "Already up to date."
        else
            echo "An update within the release is available."
        fi
        ;;
    older) echo "The available release is older than the running one; an update refuses it without --allow-downgrade." ;;
    esac
    exit 0
}

# Before anything is downloaded: a newer release, or a newer commit of the running one, replaces it; an older
# release only with --allow-downgrade, and the very same commit only with --reinstall.
guard_target() {
    [ -n "$target" ] ||
        stop 1 failed "Could not read the release version at ${TARGET_REF} from $(redact "$ENGINE_REPO"). Nothing was changed."
    [ -n "$running" ] || return 0
    case "$(release_order "$running" "$target")" in
    older)
        [ "$ALLOW_DOWNGRADE" = 1 ] ||
            stop 1 refused "Refusing to install ${target} (${TARGET_REF}) over the running ${running}: it is an older release. Nothing was changed. Pass --allow-downgrade to install it anyway."
        ;;
    same)
        if ! same_commit; then
            echo "[INFO] Updating within ${running}: ${running_commit:-unknown} → ${target_commit:-unknown} (${TARGET_REF})."
        elif [ "$REINSTALL" != 1 ]; then
            stop 0 up_to_date "Already up to date: ${running} (${running_commit}) is running and ${TARGET_REF} is the same commit. Nothing was changed. Pass --reinstall to install it again."
        fi
        ;;
    esac
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

running=$(declared_version "$ENGINE_DIR/core/config/system.php")
running_commit=$(installed_commit "$ENGINE_DIR/version")
read_target "$ENGINE_REPO" "$TARGET_REF"
[ "$CHECK_ONLY" = 0 ] || report_check
guard_target
export PANELALPHA_ALLOW_DOWNGRADE="$ALLOW_DOWNGRADE" PANELALPHA_REINSTALL="$REINSTALL"

dir="$(mktemp -d)"
# set -e leaves on a failed update; the trap removes the downloads either way
# and keeps the exit code. The installer is not exec'd, so the trap outlives it.
trap 'rm -rf "$dir"' EXIT
echo "[INFO] Redirecting Engine update to ${GET_BASE}/engine (GitHub)..."
download_installer "$dir"
run_installer "$dir"
