#!/bin/bash
# The compose file falls back to key pairs and tokens published in the
# repository, the same on every install. Generate them once per account the way
# upstream's init.sh does and hand them to compose through .env.
set -e
cd ~/project
STORE="${HOME}/.panelalpha/datalens"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
if [ ! -s "${STORE}/secrets.env" ]; then
    rnd() { openssl rand -base64 48 | tr -dc a-zA-Z0-9 | head -c "$1"; }
    rsa() {
        local priv pub
        priv=$(openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:4096 2>/dev/null)
        pub=$(echo "${priv}" | openssl rsa -pubout 2>/dev/null | sed 's|$|\\n|' | tr -d '\n')
        priv=$(echo "${priv}" | sed 's|$|\\n|' | tr -d '\n')
        printf '%s_PRIVATE_KEY="%s"\n%s_PUBLIC_KEY="%s"\n' "$1" "${priv}" "$1" "${pub}"
    }
    (
        umask 077
        {
            printf 'POSTGRES_PASSWORD=%s\n' "$(rnd 32)"
            printf 'US_MASTER_TOKEN=%s\n' "$(rnd 32)"
            printf 'AUTH_MASTER_TOKEN=%s\n' "$(rnd 32)"
            printf 'EXPORT_DATA_VERIFICATION_KEY=%s\n' "$(rnd 32)"
            printf 'CONTROL_API_CRYPTO_KEY="%s"\n' "$(rnd 32 | openssl enc -base64 -A)"
            rsa BI_DYNAMIC_US_AUTH
            rsa UI_DYNAMIC_US_AUTH
            rsa TEMPORAL_AUTH
            rsa AUTH_TOKEN
        } > "${STORE}/secrets.env.tmp"
        mv "${STORE}/secrets.env.tmp" "${STORE}/secrets.env"
    )
fi
chmod 600 "${STORE}/secrets.env"
install -m 600 "${STORE}/secrets.env" .env
