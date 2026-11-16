#!/bin/bash
# Generates the JWT signing secret once, in ~/.panelalpha (survives redeploys;
# ~/project does not).
set -e
cd ~/project

STORE="${HOME}/.panelalpha/leafwiki"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -f "${STORE}/app.env" ]; then
    (umask 077
     printf 'LEAFWIKI_JWT_SECRET=%s\n' "$(openssl rand -hex 32)" > "${STORE}/app.env")
fi
chmod 600 "${STORE}/app.env"

# .env.example sets the JWT secret and the admin login empty, and .env is loaded after the files above.
printf '# LeafWiki runs from the published image; settings are in docker-compose.yml.\n' > ~/project/.env
