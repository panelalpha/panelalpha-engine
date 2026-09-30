#!/bin/bash
# Generates the per-account secrets once, in ~/.panelalpha (survives redeploys;
# ~/project does not). The admin login is the engine's (`credentials:` in
# panelalpha.yaml), in ~/.panelalpha/app-credentials.env. Regenerating PWPUSH_MASTER_KEY
# would make every stored push undecryptable.
set -e
cd ~/project

STORE="${HOME}/.panelalpha/pwpush"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/app.env" ]; then
    (
        umask 077
        cat > "${STORE}/app.env" <<ENV
# Written once by PanelAlpha; do not delete or regenerate.
SECRET_KEY_BASE=$(openssl rand -hex 64)
PWPUSH_MASTER_KEY=$(openssl rand -hex 32)
ENV
    )
    echo "[pwpush] secrets written to ${STORE}" >&2
fi
# An older deploy kept the admin login here too; the engine adopted it.
sed -i '/^PA_ADMIN_\(EMAIL\|PASSWORD\)=/d' "${STORE}/app.env"
chmod 600 "${STORE}/app.env"

# The compose file lists .env; make sure it exists.
touch .env
