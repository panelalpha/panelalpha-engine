#!/bin/busybox sh
# PanelAlpha wrapper for the upstream Conduit release image.
#
# The release image is a nix scratch image with no shell, so the busybox copied
# in by the Dockerfile runs this file; the conduit binary is upstream's. The job
# is to wire the platform's injected public host into the CONDUIT_* environment
# the server reads, and to pin server_name so a redeploy can never change it
# (server_name is baked into every user and room id - permanent).
BB=/bin/busybox
set -u

DATA=/data
$BB mkdir -p "$DATA"

# --- server_name: derived once from the account's public host, then frozen ---
# PublicUrlEnvironment injects SERVER_NAME (bare host) and PUBLIC_URL. Once
# written, the frozen file wins, so a redeploy that somehow saw a different
# domain cannot rewrite the name the database was created with.
NAME_FILE="$DATA/.server_name"
if [ -s "$NAME_FILE" ]; then
    SERVER_NAME="$($BB cat "$NAME_FILE")"
else
    SN="${SERVER_NAME:-}"
    if [ -z "$SN" ] && [ -n "${PUBLIC_URL:-}" ]; then
        SN="${PUBLIC_URL#*://}"; SN="${SN%%/*}"; SN="${SN%%:*}"
    fi
    if [ -z "$SN" ]; then
        echo "[panelalpha] conduit: no public host injected (SERVER_NAME/PUBLIC_URL empty); cannot set Matrix server_name" >&2
        exit 1
    fi
    SERVER_NAME="$SN"
    printf '%s' "$SERVER_NAME" > "$NAME_FILE"
    echo "[panelalpha] conduit: pinned Matrix server_name=$SERVER_NAME (permanent)" >&2
fi
$BB chmod 600 "$NAME_FILE" 2>/dev/null || true

# server_name and the delegation that follows from it are the only settings that
# depend on the runtime host; the rest come from the compose override.
export CONDUIT_SERVER_NAME="$SERVER_NAME"
# Delegate federation and client discovery to the public domain on :443, which
# the engine's reverse proxy forwards to this container's 6167. Conduit maps
# CONDUIT_WELL_KNOWN_SERVER/_CLIENT onto [global.well_known] (well_known is a
# SUB_TABLE in its Figment env parser).
export CONDUIT_WELL_KNOWN_CLIENT="https://$SERVER_NAME"
export CONDUIT_WELL_KNOWN_SERVER="$SERVER_NAME:443"

# Conduit .expect()s CONDUIT_CONFIG to be set before it parses anything; empty
# means "no toml file, env only", exactly as upstream's docker-compose example.
export CONDUIT_CONFIG="${CONDUIT_CONFIG:-}"

# The static binary lives at a nix store path that carries the version, so an
# image bump moves it. Find it by glob instead of hardcoding the hash; the
# upstream image ships exactly one conduit binary.
CONDUIT_BIN=""
for c in /nix/store/*-conduit-*/bin/conduit; do
    [ -x "$c" ] && { CONDUIT_BIN="$c"; break; }
done
if [ -z "$CONDUIT_BIN" ]; then
    echo "[panelalpha] conduit: could not locate the conduit binary under /nix/store" >&2
    exit 1
fi

exec "$CONDUIT_BIN"
