#!/bin/bash
# Generates OpenSlides' four boot secrets once, in ~/.panelalpha (survives
# redeploys; ~/project does not). Same shapes as `osmanage setup`. The
# superadmin password is the engine's (`credentials:`), in
# ~/.panelalpha/app-credentials.env.
set -e
STORE="${HOME}/.panelalpha/openslides"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
umask 077

pw() { LC_ALL=C tr -dc 'A-Za-z0-9' </dev/urandom | head -c "$1"; }
if [ ! -f "${STORE}/secrets.env" ]; then
    {
        printf 'OS_AUTH_TOKEN_KEY=%s\n' "$(openssl rand -base64 32)"
        printf 'OS_AUTH_COOKIE_KEY=%s\n' "$(openssl rand -base64 32)"
        printf 'OS_INTERNAL_AUTH_PASSWORD=%s\n' "$(openssl rand -base64 32)"
        printf 'OS_POSTGRES_PASSWORD=%s\n' "$(pw 40)"
    } > "${STORE}/secrets.env"
fi
chmod 600 "${STORE}/secrets.env"
