#!/bin/bash
# Upstream's compose signs every token with published secrets: the chat21 JWT
# key `tokenKey` (which the chat21-rabbitmq image also trusts, so anyone could
# sign a RabbitMQ admin token for the public /mqws endpoint), the server's
# GLOBAL_SECRET default `nodeauthsecret` (dashboard logins) and pre-signed
# RabbitMQ and chat21 admin tokens. Here the secrets are generated once in
# ~/.panelalpha (survives redeploys; ~/project does not), and on every deploy
# the admin tokens are signed with them and written per service, with the
# RabbitMQ config that trusts the same key.
set -e
cd ~/project

STORE="${HOME}/.panelalpha/tiledesk"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

if [ ! -s "${STORE}/secrets.env" ]; then
    (umask 077; {
        printf 'CHAT21_SECRET=%s\n' "$(openssl rand -hex 32)"
        printf 'GLOBAL_SECRET=%s\n' "$(openssl rand -hex 32)"
        printf 'LLM_SECRET=%s\n' "$(openssl rand -hex 32)"
        printf 'WEBHOOK_TOKEN=%s\n' "$(openssl rand -hex 24)"
    } > "${STORE}/secrets.env")
    echo "[panelalpha] tiledesk: generated secrets into ${STORE}/secrets.env" >&2
fi
chmod 600 "${STORE}/secrets.env"
# shellcheck disable=SC1091
. "${STORE}/secrets.env"

base64url() { openssl base64 -A | tr '+/' '-_' | tr -d '='; }

# HS256 with CHAT21_SECRET: the claims of upstream's tokens, signed with this account's key.
token() {
    local header payload signature
    header="$(printf '%s' '{"alg":"HS256","typ":"JWT"}' | base64url)"
    payload="$(printf '{"jti":"%s","sub":"%s","scope":["rabbitmq.read:*/*/*","rabbitmq.write:*/*/*","rabbitmq.configure:*/*/*"],"client_id":"%s","cid":"%s","azp":"%s","user_id":"%s","app_id":"tilechat","iat":1696492287,"exp":2007532287,"aud":["rabbitmq","%s"],"kid":"tiledesk-key","tiledesk_api_roles":"%s"}' \
        "$1" "$2" "$2" "$2" "$2" "$2" "$2" "$3" | base64url)"
    signature="$(printf '%s.%s' "${header}" "${payload}" | openssl dgst -sha256 -hmac "${CHAT21_SECRET}" -binary | base64url)"
    printf '%s.%s.%s' "${header}" "${payload}" "${signature}"
}

ADMIN_TOKEN="$(token b23591fe-97aa-4f5d-8ee9-264679cbc43f 100-APIADMIN admin)"
OBSERVER_URL="amqp://ignored:$(token 7630a00e-1551-47cb-ba30-64de62fbcf4f 01-OBSERVER user)@rabbitmq:5672?heartbeat=60"
ADMIN_URL="amqp://ignored:${ADMIN_TOKEN}@rabbitmq:5672?heartbeat=60"

write_env() {
    (umask 077; cat > "${STORE}/$1")
    chmod 600 "${STORE}/$1"
}

write_env server.env <<EOF
GLOBAL_SECRET=${GLOBAL_SECRET}
APPS_ACCESS_TOKEN_SECRET=${GLOBAL_SECRET}
CHAT21_JWT_SECRET=${CHAT21_SECRET}
CHAT21_ADMIN_TOKEN=${ADMIN_TOKEN}
AMQP_MANAGER_URL=${OBSERVER_URL}
JWT_SECRET_KEY=${LLM_SECRET}
EOF
write_env chatbot.env <<EOF
AMQP_MANAGER_URL=${OBSERVER_URL}
EOF
write_env llm.env <<EOF
AMQP_MANAGER_URL=${OBSERVER_URL}
JWT_SECRET_KEY=${LLM_SECRET}
EOF
write_env chat21-http.env <<EOF
JWT_KEY=${CHAT21_SECRET}
RABBITMQ_URI=${ADMIN_URL}
PUSH_WH_CHAT21_API_ADMIN_TOKEN=${ADMIN_TOKEN}
PUSH_WH_WEBHOOK_TOKEN=${WEBHOOK_TOKEN}
EOF
write_env chat21-server.env <<EOF
RABBITMQ_URI=${OBSERVER_URL}
WEBHOOK_ENDPOINTS=http://server:3000/chat21/requests,http://chat21httpserver:8004/api/tilechat/push/webhook/endpoint/${WEBHOOK_TOKEN}
EOF

# The chat21-rabbitmq image's own advanced.config with this account's key in
# place of `tokenKey`. Read by the rabbitmq user inside the container, so it is
# world-readable; the 0700 directory keeps it from other host users.
cat > "${STORE}/rabbitmq-advanced.config" <<EOF
[
    {rabbit, [
        {auth_backends, [rabbit_auth_backend_oauth2]}
    ]},
    {rabbitmq_auth_backend_oauth2, [
        {resource_server_id, <<"rabbitmq">>},
        {key_config, [
            {default_key, <<"legacy-token-key">>},
            {signing_keys, #{
                <<"legacy-token-key">> =>
                    {map, #{
                        <<"alg">> => <<"HS256">>,
                        <<"value">> => <<"${CHAT21_SECRET}">>,
                        <<"kty">> => <<"MAC">>}
                    }
                }
            }
        ]}
    ]}
].
EOF
chmod 644 "${STORE}/rabbitmq-advanced.config"
