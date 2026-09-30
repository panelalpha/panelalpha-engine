#!/bin/bash
# Generates HITKEEP_JWT_SECRET once into ~/.panelalpha/hitkeep/ (~/project is
# wiped every deploy; without a fixed secret every restart logs everyone out).
set -e

say() { echo "[panelalpha] hitkeep: $*" >&2; }

STORE="${HOME}/.panelalpha/hitkeep"
SECRET_ENV="${STORE}/secret.env"

mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${SECRET_ENV}" ]; then
    ( umask 077; printf 'HITKEEP_JWT_SECRET=%s\n' "$(openssl rand -hex 32)" > "${SECRET_ENV}" )
    say "generated HITKEEP_JWT_SECRET"
else
    say "reusing ${SECRET_ENV}"
fi
chmod 600 "${SECRET_ENV}"
