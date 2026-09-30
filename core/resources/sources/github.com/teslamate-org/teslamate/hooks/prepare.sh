#!/bin/bash
set -e
cd ~/project

# The repository ships no .env and no compose file, so the engine would create
# an empty .env for the generated app service's env_file: and start TeslaMate
# with nothing configured. runtime.exs reads DATABASE_USER / DATABASE_PASS /
# DATABASE_HOST / DATABASE_NAME through System.fetch_env! in :prod — missing
# means a raise, not a default — and the image's entrypoint blocks on
# `nc -z $DATABASE_HOST 5432` before that.
#
# The secrets are generated once into ~/.panelalpha/teslamate: ~/project (and
# its .env) is emptied on every deploy, while the postgres volume keeps the
# password it was created with.
STORE="${HOME}/.panelalpha/teslamate"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
if [ ! -s "${STORE}/secrets.env" ]; then
    DB_PASSWORD=$(openssl rand -hex 16)
    # SHA-256 of this key is the AES-GCM key TeslaMate.Vault encrypts the stored
    # Tesla API tokens with. Unset, the vault generates one per boot and only
    # warns, so the tokens saved before a restart can never be decrypted after.
    ENCRYPTION_KEY=$(openssl rand -hex 32)
    # Phoenix session/LiveView signing. Left unset both are regenerated on every
    # boot (Util.random_string), which invalidates open sessions on restart.
    SECRET_KEY_BASE=$(openssl rand -hex 32)
    SIGNING_SALT=$(openssl rand -hex 8)
    (
        umask 077
        cat > "${STORE}/secrets.env.tmp" <<EOT
DATABASE_PASS=${DB_PASSWORD}
POSTGRES_PASSWORD=${DB_PASSWORD}
ENCRYPTION_KEY=${ENCRYPTION_KEY}
SECRET_KEY_BASE=${SECRET_KEY_BASE}
SIGNING_SALT=${SIGNING_SALT}
EOT
        mv "${STORE}/secrets.env.tmp" "${STORE}/secrets.env"
    )
fi
chmod 600 "${STORE}/secrets.env"

# The generated app service reads .env through env_file: and the override
# interpolates POSTGRES_PASSWORD from it, so it is rebuilt on every deploy.
{
    cat <<EOT
DATABASE_HOST=database
DATABASE_NAME=teslamate
DATABASE_USER=teslamate
POSTGRES_DB=teslamate
POSTGRES_USER=teslamate
EOT
    cat "${STORE}/secrets.env"
} > .env
chmod 600 .env
