#!/bin/bash
set -e
cd ~/project

# Receipt Wrangler's secrets must be stable across redeploys: SECRET_KEY signs
# every JWT (a new one logs everyone out) and ENCRYPTION_KEY decrypts at-rest
# columns (a new one makes existing encrypted data unreadable). ~/project
# is wiped on every deploy (engine#173), so these are generated once and kept in
# ~/.panelalpha/receipt-wrangler — the only account-writable dir that survives a
# rebuild — and reused on every later deploy.
STORE="${HOME}/.panelalpha/receipt-wrangler"
SECRETS="${STORE}/receipt-wrangler.env"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}" 2>/dev/null || true

if [ ! -f "${SECRETS}" ]; then
  umask 077
  {
    echo "RW_SECRET_KEY=$(openssl rand -hex 32)"
    echo "RW_ENCRYPTION_KEY=$(openssl rand -hex 32)"
  } > "${SECRETS}"
  chmod 600 "${SECRETS}"
fi

# Compose interpolates ${RW_*} from ~/project/.env at `up` time. Rewritten fresh
# each deploy from the persisted store so the values never drift. The admin
# login is the engine's (`credentials:`), read by `init` from
# ~/.panelalpha/app-credentials.env; older stores still hold it, so it is left
# out of .env.
grep -v '^RW_ADMIN_' "${SECRETS}" > .env || true
chmod 600 .env
