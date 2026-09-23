#!/bin/busybox sh
# PanelAlpha wrapper for the upstream Continuwuity release image.
#
# The release image is FROM scratch and has no shell, so the busybox copied in
# by the Dockerfile runs this file; the conduwuit binary is upstream's. The job
# is to wire the platform's injected public host into the CONTINUWUITY_*
# environment the server reads, and to pin server_name so a redeploy can never
# change it (server_name is baked into every user and room id - permanent).
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
        echo "[panelalpha] continuwuity: no public host injected (SERVER_NAME/PUBLIC_URL empty); cannot set Matrix server_name" >&2
        exit 1
    fi
    SERVER_NAME="$SN"
    printf '%s' "$SERVER_NAME" > "$NAME_FILE"
    echo "[panelalpha] continuwuity: pinned Matrix server_name=$SERVER_NAME (permanent)" >&2
fi
$BB chmod 600 "$NAME_FILE" 2>/dev/null || true

# server_name and the delegation that follows from it are the only settings
# that depend on the runtime host; the rest come from the compose override.
export CONTINUWUITY_SERVER_NAME="$SERVER_NAME"
# Delegate federation and client discovery to the public domain on :443, which
# the engine's reverse proxy forwards to this container's 8008.
export CONTINUWUITY_WELL_KNOWN__CLIENT="https://$SERVER_NAME"
export CONTINUWUITY_WELL_KNOWN__SERVER="$SERVER_NAME:443"

exec /sbin/conduwuit
