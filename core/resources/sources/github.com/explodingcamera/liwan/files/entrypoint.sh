#!/bin/sh
# Liwan container entrypoint. Everything the distroless image cannot do for
# itself: point the config at the account, seed the first administrator once,
# then hand off to the real binary.
set -e

# The embedded database lives here; the compose override mounts a named volume
# on it so it survives redeploys.
export LIWAN_DATA_DIR=/data

# Liwan's own default is 9042, which is none of the engine's preferred web
# ports -- so the deploy's port detection (ComposePortScan / ListeningSockets)
# does not recognise it and the reverse proxy falls back to 8080, a mismatch
# that 502s. Its realignment pass (AppPortAlignment) that would otherwise
# correct that is skipped whenever a compose override is present. So bind the
# one port the engine forwards to by default. LIWAN_PORT maps to the `port`
# config key (config.rs map_env_key); overridable by the account.
export LIWAN_PORT="${LIWAN_PORT:-8080}"

# Liwan reads its public origin from LIWAN_BASE_URL (config.rs). The framework
# injects the account's public https URL as BASE_URL/PUBLIC_URL (none of which
# Liwan reads), so map it. The dashboard's tracking snippet and absolute links
# are built from it, and Liwan marks the session cookie Secure when it is https
# -- which it must, since TLS is terminated at the edge and the container only
# ever sees http. Fall back to the image default if no domain is set yet.
if [ -z "${LIWAN_BASE_URL:-}" ]; then
    export LIWAN_BASE_URL="${BASE_URL:-${PUBLIC_URL:-http://localhost:9042}}"
fi

# No NTP reach-out on boot, and cap DuckDB on a shared host. All overridable by
# the account's own env_vars, which arrive already set in the environment.
export LIWAN_DISABLE_NTP_CHECK="${LIWAN_DISABLE_NTP_CHECK:-true}"
export LIWAN_DUCKDB_MEMORY_LIMIT="${LIWAN_DUCKDB_MEMORY_LIMIT:-256MB}"
export LIWAN_DUCKDB_THREADS="${LIWAN_DUCKDB_THREADS:-2}"
# The account's own proxy reaches the container from a bridge IP, not loopback,
# so the default trusted-proxy list (127.0.0.1, ::1) would discard its
# X-Forwarded-For and attribute every hit to the proxy. Only that one proxy can
# reach the container inside the account's isolated network, so trust it to set
# the client IP. Override with a CIDR list to tighten.
export LIWAN_TRUSTED_PROXIES="${LIWAN_TRUSTED_PROXIES:-*}"

# The owner administrator. hooks/prepare.sh generated the password once into
# ~/.panelalpha/liwan/admin.env and the override mounts that directory at /pa.
if [ -f /pa/admin.env ]; then
    # shellcheck disable=SC1091
    . /pa/admin.env
fi
ADMIN_USER="${LIWAN_ADMIN_USERNAME:-admin}"

# `add-user` is a plain insert and dies on a duplicate username, so it must run
# only when the user is absent -- which is what keeps every redeploy working
# against the persisted database. `liwan users` prints " - <name> (<role>)" per
# user on stdout (the config warning goes to stderr); creating the db and
# running migrations here is harmless, the serve below reuses it.
if [ -n "${LIWAN_ADMIN_PASSWORD:-}" ]; then
    if /liwan users 2>/dev/null | grep -qE "^ - ${ADMIN_USER} \("; then
        echo "[panelalpha] administrator '${ADMIN_USER}' already exists; leaving it alone"
    else
        echo "[panelalpha] creating administrator '${ADMIN_USER}'"
        /liwan add-user "${ADMIN_USER}" "${LIWAN_ADMIN_PASSWORD}" --admin true
    fi
else
    echo "[panelalpha] no admin password in the environment; skipping seed (onboarding token remains the way in)"
fi

echo "[panelalpha] LIWAN_BASE_URL=${LIWAN_BASE_URL}"
exec /liwan
