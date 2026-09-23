#!/bin/bash
set -e
cd ~/project

# BugPin persists everything it needs on the /data named volume (SQLite DB,
# uploads, and its own auto-generated .secret signing key), so the only thing
# this hook must persist itself is the admin password it generates — and that
# has to outlive the deploy, because ~/project is wiped and re-cloned every
# time. ~/.panelalpha is the only writable, rebuild-surviving place for it.
SECRETS_DIR=~/.panelalpha/bugpin
ADMIN_ENV="${SECRETS_DIR}/admin.env"

mkdir -p "${SECRETS_DIR}"
chmod 700 "${SECRETS_DIR}"

# Generate the admin credential once and reuse it forever. Regenerating on a
# redeploy would be harmless the first time (the seed is idempotent and would
# no-op against the existing DB) but would silently drift the stored password
# away from the real one, so it is written only when missing.
if [ ! -f "${ADMIN_ENV}" ]; then
    ADMIN_PASSWORD=$(openssl rand -base64 24 | tr -d '/+=' | cut -c1-24)
    cat > "${ADMIN_ENV}" <<EOF
BUGPIN_ADMIN_EMAIL=admin@bugpin.local
BUGPIN_ADMIN_PASSWORD=${ADMIN_PASSWORD}
EOF
    chmod 600 "${ADMIN_ENV}"
fi

# Pre-pull so the engine's `docker compose up -d` starts instantly instead of
# blocking the deploy on a first-time image fetch. The image is anonymously
# pullable from the project's own registry.
docker pull registry.arantic.cloud/bugpin/bugpin:latest
