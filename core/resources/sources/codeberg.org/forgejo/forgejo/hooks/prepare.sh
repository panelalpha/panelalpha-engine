#!/bin/bash
# After the clone, before `docker compose up`. Generates Forgejo's secrets once
# into ~/.panelalpha/forgejo/ (~/project is wiped on every deploy; a new
# SECRET_KEY would make stored 2FA/OAuth secrets unreadable). The admin login
# is the engine's (`credentials:` in panelalpha.yaml), in ~/.panelalpha/app-credentials.env.
set -e
cd ~/project

say() { echo "[panelalpha] forgejo: $*" >&2; }

STORE="${HOME}/.panelalpha/forgejo"
SECRETS="${STORE}/secrets.env"

mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

# JWT secrets must be base64url of 32 bytes, unpadded.
jwt() { openssl rand 32 | base64 | tr '+/' '-_' | tr -d '=\n'; }

if [ ! -f "${SECRETS}" ]; then
    (
        umask 077
        cat > "${SECRETS}" <<SECRETS_EOF
FORGEJO__security__SECRET_KEY=$(openssl rand -hex 32)
FORGEJO__security__INTERNAL_TOKEN=$(openssl rand -hex 48)
FORGEJO__oauth2__JWT_SECRET=$(jwt)
FORGEJO__server__LFS_JWT_SECRET=$(jwt)
SECRETS_EOF
    )
    say "generated SECRET_KEY, INTERNAL_TOKEN and JWT secrets"
fi

chmod 600 "${SECRETS}"
say "prepare complete"
