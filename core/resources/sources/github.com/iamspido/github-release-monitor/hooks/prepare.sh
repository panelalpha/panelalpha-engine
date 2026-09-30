#!/bin/bash
# Generates BETTER_AUTH_SECRET and AUTH_SETUP_TOKEN once, in ~/.panelalpha
# (survives redeploys; ~/project does not), so sessions and the setup token
# stay the same across rebuilds.
set -e
cd ~/project

STORE="${HOME}/.panelalpha/ghrm"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/app.env" ]; then
    (umask 077; printf 'BETTER_AUTH_SECRET=%s\nAUTH_SETUP_TOKEN=%s\n' \
        "$(openssl rand -hex 32)" "$(openssl rand -hex 24)" > "${STORE}/app.env")
    echo "[panelalpha] ghrm: generated BETTER_AUTH_SECRET and AUTH_SETUP_TOKEN into ${STORE}/app.env" >&2
fi
chmod 600 "${STORE}/app.env"

# The compose file lists .env; the project's environment variables merge into it.
touch .env
