#!/bin/bash
# Graylog will not start without password_secret and root_password_sha2.
# Generated once into ~/.panelalpha (survives redeploys; ~/project does not).
set -e
cd ~/project

STORE="${HOME}/.panelalpha/graylog"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/graylog.env" ]; then
    pass="$(openssl rand -hex 16)"
    (umask 077
     printf '%s\n' "${pass}" > "${STORE}/admin-password"
     printf 'GRAYLOG_PASSWORD_SECRET=%s\nGRAYLOG_ROOT_PASSWORD_SHA2=%s\n' \
        "$(openssl rand -hex 48)" "$(printf '%s' "${pass}" | sha256sum | cut -d' ' -f1)" \
        > "${STORE}/graylog.env")
    echo "[graylog] admin password written to ${STORE}/admin-password" >&2
fi
chmod 600 "${STORE}/graylog.env" "${STORE}/admin-password"
