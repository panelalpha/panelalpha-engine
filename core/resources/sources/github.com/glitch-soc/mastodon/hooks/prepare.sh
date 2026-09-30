#!/bin/bash
# Secrets generated once into ~/.panelalpha: ~/project is wiped on every deploy,
# the Postgres volume keeps its first password, and the Active Record
# encryption keys must never change once data exists.
set -e
STORE="${HOME}/.panelalpha/glitch-soc"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
b64url() { base64 -w0 | tr '+/' '-_' | tr -d '='; }
if [ ! -s "${STORE}/mastodon.env" ]; then
    db="$(openssl rand -hex 24)"
    key="$(mktemp)"
    # Web Push (VAPID) pair: raw P-256 private scalar and uncompressed public point.
    openssl ecparam -name prime256v1 -genkey -noout -out "${key}"
    vapid_private="$(openssl ec -in "${key}" -outform DER 2>/dev/null | dd bs=1 skip=7 count=32 2>/dev/null | b64url)"
    vapid_public="$(openssl ec -in "${key}" -pubout -outform DER 2>/dev/null | tail -c 65 | b64url)"
    rm -f "${key}"
    (umask 077; cat > "${STORE}/mastodon.env" <<ENV
SECRET_KEY_BASE=$(openssl rand -hex 64)
ACTIVE_RECORD_ENCRYPTION_DETERMINISTIC_KEY=$(openssl rand -hex 16)
ACTIVE_RECORD_ENCRYPTION_KEY_DERIVATION_SALT=$(openssl rand -hex 16)
ACTIVE_RECORD_ENCRYPTION_PRIMARY_KEY=$(openssl rand -hex 16)
VAPID_PRIVATE_KEY=${vapid_private}
VAPID_PUBLIC_KEY=${vapid_public}
DB_PASS=${db}
POSTGRES_PASSWORD=${db}
ENV
    )
fi
chmod 600 "${STORE}/mastodon.env"
