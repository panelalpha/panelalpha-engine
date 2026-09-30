#!/bin/bash
# Generates the admin login and API key once, in ~/.panelalpha (survives
# redeploys; ~/project does not). The init service seeds them into NZBHydra2.
set -e
cd ~/project

STORE="${HOME}/.panelalpha/nzbhydra"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/nzbhydra.env" ]; then
    pw="$(openssl rand -base64 24 | tr -d '\n=/+')"
    key="$(openssl rand -hex 16 | tr 'a-f' 'A-F')"
    # Spring's BCryptPasswordEncoder; $2a$ is what NZBHydra2 itself writes.
    hash="$(P="$pw" php -r 'echo password_hash(getenv("P"), PASSWORD_BCRYPT);' | sed 's/^\$2y\$/$2a$/')"
    # Single quotes: compose would otherwise interpolate the $ in the hash.
    (umask 077; printf "NZBHYDRA_ADMIN_USER=admin\nNZBHYDRA_ADMIN_PASSWORD=%s\nNZBHYDRA_ADMIN_HASH='%s'\nNZBHYDRA_API_KEY=%s\n" \
        "$pw" "$hash" "$key" > "${STORE}/nzbhydra.env")
fi
chmod 600 "${STORE}/nzbhydra.env"

# The compose file lists .env; make sure it exists.
touch .env
chmod +r panelalpha/nzbhydra-seed.sh
