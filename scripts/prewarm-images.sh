#!/bin/bash
#
# Warm the host image cache so no customer deploy pays a first-use penalty.
#
# The expensive item is the shared PHP base: PhpBaseImage compiles ten bundled
# extensions from the PHP source tree, ~150s per PHP minor, and without this
# the bill lands on whichever customer deploys that minor first.
#
# Runs in the background by default - a full warm is ~12 minutes and there is
# no reason to hold up an install or an update for it. Everything it does is
# idempotent and budget-capped, so a second run is cheap and a full disk is a
# no-op rather than a failure.
#
# Usage: prewarm-images.sh [--foreground] [artisan options...]
#   PREWARM_BUDGET=6G   max disk to spend       (default 6G, "none" = unlimited)
#   PREWARM_RESERVE=10G free space to leave     (default 10G)
#   PREWARM_RUNTIMES=php,static,node            (default: all)

set -uo pipefail

COMPOSE_FILE="/opt/panelalpha/shared-hosting/docker-compose.yml"
LOG_DIR="/opt/panelalpha/log/prewarm"
FOREGROUND=0

args=()
for arg in "$@"; do
    if [[ "$arg" == "--foreground" ]]; then
        FOREGROUND=1
    else
        args+=("$arg")
    fi
done

[[ -n "${PREWARM_BUDGET:-}" ]] && args+=("--budget=${PREWARM_BUDGET}")
[[ -n "${PREWARM_RESERVE:-}" ]] && args+=("--reserve=${PREWARM_RESERVE}")
[[ -n "${PREWARM_RUNTIMES:-}" ]] && args+=("--runtimes=${PREWARM_RUNTIMES}")

if [[ ! -f "$COMPOSE_FILE" ]]; then
    echo "[WARNING] $COMPOSE_FILE not found, skipping image prewarm" >&2
    exit 0
fi

mkdir -p "$LOG_DIR"
log_file="$LOG_DIR/$(date +%Y%m%d-%H%M%S).log"
ln -sfn "$log_file" "$LOG_DIR/latest"

if [[ "$FOREGROUND" -eq 1 ]]; then
    docker compose -f "$COMPOSE_FILE" exec -T core \
        php artisan system:image:prewarm "${args[@]+"${args[@]}"}" 2>&1 | tee "$log_file"
    exit "${PIPESTATUS[0]}"
fi

nohup bash -c \
    'exec 200>&- 2>/dev/null || true
     docker compose -f "$1" exec -T core php artisan system:image:prewarm "${@:2}"' \
    _ "$COMPOSE_FILE" "${args[@]+"${args[@]}"}" >"$log_file" 2>&1 &

echo "[INFO] Prewarming base images in the background, log: $LOG_DIR/latest"
