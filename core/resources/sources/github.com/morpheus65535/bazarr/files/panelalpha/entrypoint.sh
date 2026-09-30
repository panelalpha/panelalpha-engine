#!/bin/bash
# Runs as root in front of the LinuxServer image's /init. Bazarr ships with
# authentication off; on the first boot (no config.yaml on the volume yet) write
# one that turns on form login and pins the API key. Bazarr fills in every other
# setting with its defaults. An existing config.yaml is never touched, so what
# the admin changes in the UI survives a redeploy.
set -e

CONF_DIR=/config/config
CONF="${CONF_DIR}/config.yaml"

if [ ! -s "${CONF}" ]; then
    : "${BAZARR_AUTH_USERNAME:?missing}" "${BAZARR_AUTH_PASSWORD:?missing}" "${BAZARR_AUTH_APIKEY:?missing}"
    mkdir -p "${CONF_DIR}"
    # Bazarr stores the md5 hex of the password (utilities/helper.py check_credentials).
    BAZARR_AUTH_PASSWORD_MD5="$(printf '%s' "${BAZARR_AUTH_PASSWORD}" | md5sum | cut -d' ' -f1)"
    cat > "${CONF}" <<EOF
auth:
  type: form
  username: '${BAZARR_AUTH_USERNAME}'
  password: '${BAZARR_AUTH_PASSWORD_MD5}'
  apikey: '${BAZARR_AUTH_APIKEY}'
EOF
    chown -R "${PUID:-911}:${PGID:-911}" "${CONF_DIR}"
    chmod 600 "${CONF}"
    echo "[panelalpha] seeded ${CONF} with form login for ${BAZARR_AUTH_USERNAME}"
else
    echo "[panelalpha] ${CONF} exists; leaving it as is"
    # Once, for an account the older recipe seeded: its app.env still holds the md5
    # of the password it generated. While config.yaml has that exact hash, move it
    # to the engine's password; a password changed in the UI no longer matches.
    if [ -n "${BAZARR_AUTH_PASSWORD_MD5:-}" ] && [ -n "${BAZARR_AUTH_PASSWORD:-}" ] \
        && grep -q "${BAZARR_AUTH_PASSWORD_MD5}" "${CONF}"; then
        new_md5="$(printf '%s' "${BAZARR_AUTH_PASSWORD}" | md5sum | cut -d' ' -f1)"
        sed -i "s/${BAZARR_AUTH_PASSWORD_MD5}/${new_md5}/" "${CONF}"
        echo "[panelalpha] moved the seeded login to the engine's credentials"
    fi
fi

# The seeded secrets are not needed past this point.
unset BAZARR_AUTH_USERNAME BAZARR_AUTH_PASSWORD BAZARR_AUTH_PASSWORD_MD5 BAZARR_AUTH_APIKEY
exec /init
