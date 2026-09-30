#!/bin/bash
# Generates the API key and the hash of the engine's admin password
# (`credentials:` in panelalpha.yaml, ~/.panelalpha/app-credentials.env, written
# before this hook runs) once, in ~/.panelalpha (survives redeploys; ~/project
# does not). The init service seeds them into NZBHydra2.
set -e
cd ~/project

STORE="${HOME}/.panelalpha/nzbhydra"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/nzbhydra.env" ]; then
    set -a; . "${HOME}/.panelalpha/app-credentials.env"; set +a
    key="$(openssl rand -hex 16 | tr 'a-f' 'A-F')"
    # Spring's BCryptPasswordEncoder; $2a$ is what NZBHydra2 itself writes.
    hash="$(P="$NZBHYDRA_ADMIN_PASSWORD" php -r 'echo password_hash(getenv("P"), PASSWORD_BCRYPT);' | sed 's/^\$2y\$/$2a$/')"
    # Single quotes: compose would otherwise interpolate the $ in the hash.
    (umask 077; printf "NZBHYDRA_ADMIN_HASH='%s'\nNZBHYDRA_API_KEY=%s\n" "$hash" "$key" > "${STORE}/nzbhydra.env")
fi
chmod 600 "${STORE}/nzbhydra.env"

# The compose file lists .env; make sure it exists.
touch .env
chmod +r panelalpha/nzbhydra-seed.sh
