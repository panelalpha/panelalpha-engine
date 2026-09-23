#!/bin/bash
# Runs in the account shell after the clone and after overrides/ and files/ are
# in place, before `docker compose up`. Jobs the compose file cannot do itself:
# put the JWT secret and the generated Owner credentials somewhere the next
# deploy will not delete, and make sure the .env the compose references exists.
set -e
cd ~/project

# ~/.panelalpha/ech0 survives a redeploy; ~/project is emptied every deploy
# (engine#173). Secrets written under ~/project would be regenerated on every
# rebuild -- a new JWT secret logs everyone out, and a new owner password would
# not match the Owner already stored in the SQLite volume.
STORE_DIR="${HOME}/.panelalpha/ech0"
ENV_FILE="${STORE_DIR}/ech0.env"
CRED_FILE="${STORE_DIR}/credentials.txt"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

# First deploy only. The env file's presence is the "already initialised" flag.
# JWT_SECRET: getJWTSecret() falls back to a per-boot random secret when unset,
# which invalidates every session on restart; pin a real stable one. The owner
# password only has an upper length bound in the app (no minimum), so 48 hex is
# safe. The username is randomised so it is not guessable.
if [ ! -f "${ENV_FILE}" ]; then
    JWT_SECRET="$(openssl rand -hex 32)"
    OWNER_USERNAME="owner_$(openssl rand -hex 4)"
    OWNER_PASSWORD="$(openssl rand -hex 24)"
    # InitOwner requires a non-empty, RFC-parseable email; it is not used for
    # mail (no SMTP configured), only to satisfy the owner record.
    OWNER_EMAIL="${OWNER_USERNAME}@ech0.local"
    (
        umask 077
        cat > "${ENV_FILE}" <<EOF
# Written once on the first deploy and never regenerated. Rotating JWT_SECRET
# logs every user out; changing the owner creds here will NOT change the Owner
# already stored in the ech0-data volume. Do not delete this file.
JWT_SECRET=${JWT_SECRET}
ECH0_OWNER_USERNAME=${OWNER_USERNAME}
ECH0_OWNER_PASSWORD=${OWNER_PASSWORD}
ECH0_OWNER_EMAIL=${OWNER_EMAIL}
EOF
        cat > "${CRED_FILE}" <<EOF
Ech0 -- self-hosted microblog.

Reading the timeline is public by design. Publishing and admin require login as
the Owner below. The Owner is seeded automatically on first deploy via the
app's POST /api/init/owner endpoint (claimed so no random visitor can take it).

Owner login (auto-seeded, reused across redeploys):
  username: ${OWNER_USERNAME}
  password: ${OWNER_PASSWORD}
  email:    ${OWNER_EMAIL}  (placeholder; no mail is sent)

Public self-registration is disabled by default (ECH0_SETTING_ALLOW_REGISTER).
These values are reused on every redeploy; deleting this directory will orphan
the Owner already stored in the ech0-data volume.
EOF
    )
    chmod 600 "${ENV_FILE}" "${CRED_FILE}"
fi

# The compose lists ~/project/.env as an env_file; make sure it exists even when
# the platform has not written one yet, so `docker compose up` does not abort on
# a missing file. The account's env_vars are merged into it by the platform.
touch .env

# Pre-pull the pinned image so `compose up` starts fast (best effort).
docker pull sn0wl1n/ech0:v5.7.0 >/dev/null 2>&1 || true
