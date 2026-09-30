#!/bin/bash
# After the clone, before `docker compose up`. Generates the admin password and
# JWT_SECRET once into ~/.panelalpha/invio/, which survives redeploys.
set -e

say() { echo "[panelalpha] invio: $*" >&2; }

STORE="${HOME}/.panelalpha/invio"
SECRET_ENV="${STORE}/secret.env"
NOTE="${STORE}/credentials.txt"

mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

rnd() { openssl rand -base64 "$1" | tr -d '\n=/+'; }

if [ ! -f "${SECRET_ENV}" ]; then
    ADMIN_PASS="$(rnd 24)"
    (
        umask 077
        printf 'ADMIN_USER=admin\nADMIN_PASS=%s\nJWT_SECRET=%s\n' "${ADMIN_PASS}" "$(openssl rand -hex 32)" > "${SECRET_ENV}"
        cat > "${NOTE}" <<NOTE_EOF
Invio on this account
=====================

The administrator was created on the first deploy from these values.

  Username: admin
  Password: ${ADMIN_PASS}

Invio seeds the admin only while its user table is empty, so a password
changed in the app (Settings) is kept; this file is not updated then.
More users: the Users page (admin only). Data (invio.db) is on the named volume
invio_data and survives redeploys.
NOTE_EOF
    )
    say "secrets and credentials written to ${NOTE}"
else
    say "reusing the secrets in ${STORE}"
fi
chmod 600 "${SECRET_ENV}" "${NOTE}" 2>/dev/null || true
say "prepare complete"
