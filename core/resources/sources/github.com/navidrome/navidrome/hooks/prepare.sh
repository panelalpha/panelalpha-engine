#!/bin/bash
# After the clone, before `docker compose up`. Generates the password encryption
# key and the admin login once into ~/.panelalpha/navidrome/ (~/project is wiped
# on every deploy; a new encryption key would orphan every stored password).
set -e
cd ~/project

say() { echo "[panelalpha] navidrome: $*" >&2; }

STORE="${HOME}/.panelalpha/navidrome"
SECRET_ENV="${STORE}/secret.env"
ADMIN_ENV="${STORE}/admin.env"
NOTE="${STORE}/credentials.txt"

mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${SECRET_ENV}" ]; then
    # Replaces the key compiled into every Navidrome binary.
    ( umask 077; printf 'ND_PASSWORDENCRYPTIONKEY=%s\n' "$(openssl rand -hex 32)" > "${SECRET_ENV}" )
    say "generated ND_PASSWORDENCRYPTIONKEY"
fi

if [ ! -f "${ADMIN_ENV}" ]; then
    ADMIN_PASSWORD="$(openssl rand -base64 30 | tr -d '\n=/+' | cut -c1-24)"
    (
        umask 077
        printf 'NAVIDROME_ADMIN_USER=admin\nNAVIDROME_ADMIN_PASSWORD=%s\n' "${ADMIN_PASSWORD}" > "${ADMIN_ENV}"
        cat > "${NOTE}" <<NOTE_EOF
Navidrome admin for this account
================================

  username: admin
  password: ${ADMIN_PASSWORD}

Created before the site opens on the first deploy; a redeploy never resets it,
so a password changed in the web UI is kept. Music lives on the named volume
navidrome-music (mounted at /music), the database on navidrome-data. To add
music: docker compose -p project cp <dir> app:/music/ ; the file watcher picks
it up, or start a scan from the web UI.
NOTE_EOF
    )
    say "admin credentials written to ${NOTE}"
fi
chmod 600 "${SECRET_ENV}" "${ADMIN_ENV}" "${NOTE}" 2>/dev/null || true
say "prepare complete"
