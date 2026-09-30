#!/bin/bash
# After the clone, before `docker compose up`. Generates the password encryption
# key once into ~/.panelalpha/navidrome/ (~/project is wiped on every deploy; a
# new encryption key would orphan every stored password). The admin login is the
# engine's (`credentials:`), in ~/.panelalpha/app-credentials.env.
set -e
cd ~/project

say() { echo "[panelalpha] navidrome: $*" >&2; }

STORE="${HOME}/.panelalpha/navidrome"
SECRET_ENV="${STORE}/secret.env"

mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${SECRET_ENV}" ]; then
    # Replaces the key compiled into every Navidrome binary.
    ( umask 077; printf 'ND_PASSWORDENCRYPTIONKEY=%s\n' "$(openssl rand -hex 32)" > "${SECRET_ENV}" )
    say "generated ND_PASSWORDENCRYPTIONKEY"
fi

chmod 600 "${SECRET_ENV}" 2>/dev/null || true
say "prepare complete"
