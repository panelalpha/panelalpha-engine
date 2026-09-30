#!/bin/bash
# Account shell, cwd ~/project, after the clone and after overrides/
# docker-compose.yml is in place. Generates the secrets and PostgreSQL TLS
# material once into ~/.panelalpha/snagtime (survives redeploys), then writes
# docker-compose.override.yml with the commit's BUILD_ID and the account uid.
set -e
cd ~/project

DATA="${HOME}/.panelalpha/snagtime"
SEC="${DATA}/secrets"
TLS="${DATA}/tls"
say() { echo "[panelalpha] snagtime: $*"; }

if [ ! -f compose.production.yml ] || [ ! -f infrastructure/postgresql/Dockerfile ]; then
    echo "[panelalpha] snagtime: compose.production.yml / infrastructure/postgresql missing -- not a Snagtime checkout" >&2
    exit 1
fi

mkdir -p "${SEC}" "${TLS}"
chmod 700 "${DATA}" "${SEC}" "${TLS}"
umask 077

# Hex only, so the values can sit in a connection URL unescaped.
gen() { [ -s "${SEC}/$1" ] || openssl rand -hex "$2" > "${SEC}/$1"; }
gen postgres_owner_password 24
for s in db_migration_password db_app_password db_worker_password db_monitor_password \
         auth_secret booking_capability_secret email_token_secret tenant_context_secret \
         rate_limit_hash_secret proxy_shared_secret operator_health_secret; do
    gen "$s" 24
done
gen token_encryption_key 32
[ -s "${SEC}/booking_capability_keyring" ] || printf '{}' > "${SEC}/booking_capability_keyring"

# The production contract requires sslmode=verify-full against an explicit CA
# (infrastructure/postgresql rejects non-TLS). A private CA, generated once.
if [ ! -s "${SEC}/postgres_server_cert" ]; then
    # Explicit -CAserial: OpenSSL 3.0 derives the default from the last "." in
    # the CA path, which here is ~/.panelalpha -> ~/.srl (not writable).
    openssl req -x509 -newkey rsa:2048 -nodes -days 3650 -subj "/CN=snagtime-postgres-ca" \
        -keyout "${TLS}/ca.key" -out "${SEC}/postgres_ca_cert" 2>/dev/null
    openssl req -newkey rsa:2048 -nodes -subj "/CN=postgres" \
        -keyout "${SEC}/postgres_server_key" -out "${TLS}/server.csr" 2>/dev/null
    printf 'subjectAltName=DNS:postgres\n' > "${TLS}/san.ext"
    openssl x509 -req -in "${TLS}/server.csr" -CA "${SEC}/postgres_ca_cert" -CAkey "${TLS}/ca.key" \
        -CAserial "${TLS}/ca.srl" -CAcreateserial -days 3650 -extfile "${TLS}/san.ext" -out "${SEC}/postgres_server_cert"
    say "generated the PostgreSQL CA and server certificate"
fi

# Role URLs with the parameters the runtime insists on (production-config.mjs).
q="sslmode=verify-full&sslrootcert=/run/secrets/postgres_ca_cert"
rt="${q}&connect_timeout=3&pool_timeout=20&connection_limit=20&statement_timeout=2000"
printf 'postgresql://tempocove_owner:%s@postgres:5432/tempocove?%s' "$(cat "${SEC}/postgres_owner_password")" "$q" > "${SEC}/owner_database_url"
printf 'postgresql://tempocove_app_login:%s@postgres:5432/tempocove?%s' "$(cat "${SEC}/db_app_password")" "$rt" > "${SEC}/app_database_url"
printf 'postgresql://tempocove_worker_login:%s@postgres:5432/tempocove?%s' "$(cat "${SEC}/db_worker_password")" "$rt" > "${SEC}/worker_database_url"
chmod 600 "${SEC}"/*

# The image refuses to start unless BUILD_ID is the 40-hex commit it was built from.
BUILD_ID="$(git rev-parse HEAD 2>/dev/null || true)"
if ! printf '%s' "${BUILD_ID}" | grep -Eq '^[0-9a-f]{40}$'; then
    echo "[panelalpha] snagtime: cannot read the checked-out commit (git rev-parse HEAD); BUILD_ID is required" >&2
    exit 1
fi

# The web and worker images run as a fixed non-root user; run them as the
# account instead so they can read the 0600 secret files.
APP_UID="$(id -u)"
APP_GID="$(id -g)"
cat > docker-compose.override.yml <<EOF
# Written by PanelAlpha's Snagtime recipe (hooks/prepare.sh) on every deploy.
services:
  init:
    build: { args: { BUILD_ID: "${BUILD_ID}" } }
  worker:
    build: { args: { BUILD_ID: "${BUILD_ID}" } }
    user: "${APP_UID}:${APP_GID}"
    environment: { BUILD_ID: "${BUILD_ID}" }
  app:
    build: { args: { BUILD_ID: "${BUILD_ID}" } }
    user: "${APP_UID}:${APP_GID}"
    environment: { BUILD_ID: "${BUILD_ID}" }
EOF
say "prepared (BUILD_ID ${BUILD_ID}, secrets in ${SEC})"
