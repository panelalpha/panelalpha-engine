#!/bin/sh
# One-shot, run to completion before the app is allowed to start. Three jobs:
# migrate the database (which creates the citext/pg_trgm/uuid-ossp extensions and
# the whole schema), install the web frontends, and seed a confirmed admin so
# the owner can log in with no email round-trip. `app` waits on this with
# service_completed_successfully, so any failure here stops the deploy instead of
# publishing a broken site.
set -e

say() { echo "[panelalpha/init] $*"; }

# Every pleroma_ctl call here must run in its own eval instance, not RPC into a
# node (nothing is running yet): migrate/create are eval by default, but
# `user new` and `frontend install` otherwise try to RPC and fail :noconnection.
export PLEROMA_CTL_RPC_DISABLED=true

# Derive DOMAIN the same way the app entrypoint does; config/docker.exs needs it.
url="${PA_PUBLIC_URL:-http://localhost}"
host="${url#*://}"; host="${host%%/*}"; host="${host%%:*}"
[ -n "${host}" ] || host="localhost"
export DOMAIN="${host}"
: "${ADMIN_EMAIL:=admin@${host}}"; export ADMIN_EMAIL
: "${NOTIFY_EMAIL:=${ADMIN_EMAIL}}"; export NOTIFY_EMAIL

DB_HOST="${DB_HOST:-db}"
DB_PORT="${DB_PORT:-5432}"
DB_USER="${DB_USER:-akkoma}"
DB_NAME="${DB_NAME:-akkoma}"

# compose already gates on the db healthcheck; second belt for the restart window.
say "waiting for database ${DB_HOST}:${DB_PORT}"
i=0
until pg_isready -h "${DB_HOST}" -p "${DB_PORT}" -U "${DB_USER}" -d "${DB_NAME}" -q -t 1; do
    i=$((i + 1)); [ "${i}" -ge 60 ] && { say "database never became ready"; exit 1; }
    sleep 2
done

# Fresh named volumes can be root-owned or carry a stale owner; make sure the
# akkoma user owns its state before it writes secret.exs / uploads / frontends.
# (Runs as root via the compose `user: "0:0"`.)
mkdir -p /var/lib/akkoma/uploads /var/lib/akkoma/static
chown -R akkoma:akkoma /var/lib/akkoma

# migrate: creates extensions + schema. Runs as the akkoma user with the release
# config (DB creds from env). ReleaseTasks.migrate does not boot the endpoint.
say "running migrations"
su akkoma -s /bin/sh -c 'cd /opt/akkoma && bin/pleroma_ctl migrate'

# Install the web UI into /var/lib/akkoma/static/frontends (persisted on the
# named volume). pleroma-fe is the primary UI, admin-fe the moderation console.
# Idempotent: a re-run just re-downloads the same ref. Best-effort per frontend
# so a transient CDN hiccup on one does not wipe an already-installed deploy,
# but a totally missing primary FE is fatal (the site would have no UI).
install_fe() {
    say "installing frontend $1 ($2)"
    su akkoma -s /bin/sh -c "cd /opt/akkoma && PLEROMA_CTL_RPC_DISABLED=true bin/pleroma_ctl frontend install $1 --ref $2"
}
install_fe pleroma-fe stable
install_fe admin-fe stable || say "admin-fe install failed (non-fatal); moderation UI unavailable until reinstalled"
if [ ! -f /var/lib/akkoma/static/frontends/pleroma-fe/stable/index.html ]; then
    say "primary frontend pleroma-fe did not install; refusing to publish a UI-less site"
    exit 1
fi

# Seed the admin. Idempotent AND domain-change-safe: only when NO admin exists.
if [ -z "${ADMIN_PASSWORD:-}" ]; then
    say "no ADMIN_PASSWORD in the store; refusing to seed a blank-password admin"
    exit 1
fi
admins="$(PGPASSWORD="${DB_PASS}" psql -tA \
    -h "${DB_HOST}" -p "${DB_PORT}" -U "${DB_USER}" -d "${DB_NAME}" \
    -c "SELECT count(*) FROM users WHERE is_admin = true;" 2>/dev/null || echo "")"

if [ "${admins}" = "0" ]; then
    say "seeding administrator admin (${ADMIN_EMAIL})"
    # RPC disabled -> runs in its own eval instance, not an RPC into a node.
    # -y skips the confirm prompt; --password sets it (no reset link); the user
    # is registered is_confirmed:true, immediately usable.
    su akkoma -s /bin/sh -c "cd /opt/akkoma && PLEROMA_CTL_RPC_DISABLED=true bin/pleroma_ctl user new admin '${ADMIN_EMAIL}' --password '${ADMIN_PASSWORD}' --admin -y"
    say "administrator seeded"
elif [ -z "${admins}" ]; then
    say "could not query for existing administrators"; exit 1
else
    say "an administrator already exists (${admins}); leaving it untouched"
fi

say "done"
