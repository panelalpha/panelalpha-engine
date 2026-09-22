#!/bin/bash
set -e
cd ~/project

# Onloc's compose reads its secrets from ~/project/.env via ${...}. But ~/project
# is wiped on every redeploy (engine#173), so the values themselves are kept in
# ~/.panelalpha/onloc/ — the only account-writable directory that survives a
# rebuild — and reused. Regenerating them would change the database password out
# from under the persisted postgres volume (auth failures) and invalidate every
# session, so generate once and reuse.
STORE="${HOME}/.panelalpha/onloc"
SECRETS="${STORE}/onloc.env"
mkdir -p "${STORE}"
chmod 700 "${STORE}"

if [ ! -f "${SECRETS}" ]; then
  DB_PASSWORD=$(openssl rand -hex 16)
  ACCESS_TOKEN_SECRET=$(openssl rand -hex 32)
  REFRESH_TOKEN_SECRET=$(openssl rand -hex 32)
  # First admin, seeded by the compose `init` service before caddy goes public.
  # Username is fixed; the password is generated once and lives only here (0600).
  ONLOC_ADMIN_USER=admin
  ONLOC_ADMIN_PASSWORD=$(openssl rand -hex 18)
  umask 077
  cat > "${SECRETS}" <<EOF
DB_PASSWORD=${DB_PASSWORD}
ACCESS_TOKEN_SECRET=${ACCESS_TOKEN_SECRET}
REFRESH_TOKEN_SECRET=${REFRESH_TOKEN_SECRET}
ONLOC_ADMIN_USER=${ONLOC_ADMIN_USER}
ONLOC_ADMIN_PASSWORD=${ONLOC_ADMIN_PASSWORD}
EOF
  chmod 600 "${SECRETS}"
fi

# Compose interpolates ${...} in the override from this .env at `up` time.
cp -f "${SECRETS}" .env
chmod 600 .env
