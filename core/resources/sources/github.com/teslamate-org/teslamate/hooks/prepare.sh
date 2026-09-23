#!/bin/bash
set -e
cd ~/project

# The repository ships no .env and no compose file, so the engine would create
# an empty .env for the generated app service's env_file: and start TeslaMate
# with nothing configured. runtime.exs reads DATABASE_USER / DATABASE_PASS /
# DATABASE_HOST / DATABASE_NAME through System.fetch_env! in :prod — missing
# means a raise, not a default — and the image's entrypoint blocks on
# `nc -z $DATABASE_HOST 5432` before that. Write the file once; a redeploy must
# not roll the password, or the existing postgres volume stops accepting it.
if [ ! -f .env ]; then
    DB_PASSWORD=$(openssl rand -hex 16)
    # SHA-256 of this key is the AES-GCM key TeslaMate.Vault encrypts the stored
    # Tesla API tokens with. Unset, the vault generates one per boot and only
    # warns, so the tokens saved before a restart can never be decrypted after.
    ENCRYPTION_KEY=$(openssl rand -hex 32)
    # Phoenix session/LiveView signing. Left unset both are regenerated on every
    # boot (Util.random_string), which invalidates open sessions on restart.
    SECRET_KEY_BASE=$(openssl rand -hex 32)
    SIGNING_SALT=$(openssl rand -hex 8)

    cat > .env <<EOF
DATABASE_HOST=database
DATABASE_NAME=teslamate
DATABASE_USER=teslamate
DATABASE_PASS=${DB_PASSWORD}
POSTGRES_DB=teslamate
POSTGRES_USER=teslamate
POSTGRES_PASSWORD=${DB_PASSWORD}
ENCRYPTION_KEY=${ENCRYPTION_KEY}
SECRET_KEY_BASE=${SECRET_KEY_BASE}
SIGNING_SALT=${SIGNING_SALT}
EOF
    chmod 600 .env
fi
