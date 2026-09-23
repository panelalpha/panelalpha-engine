#!/bin/bash
# Runs in the account shell after the clone and after overrides/ and files/ are
# in place, before `docker compose up`. One job the compose file cannot do for
# itself: generate the two long-lived secrets ONCE into a place a redeploy will
# not wipe, and make sure the .env the compose references exists.
#
# WHY THIS MATTERS:
#   WASTEBIN_SIGNING_KEY signs the cookies that authorize deleting/editing your
#   OWN pastes. If it were regenerated on every rebuild, every outstanding
#   deletion/edit cookie would silently stop working. It must be strong (upstream
#   requires >= 64 bytes) and stable.
#   WASTEBIN_PASSWORD_SALT salts the hashing of per-paste encryption passwords.
#   The upstream default is the well-known constant "somesalt"; a unique per-
#   account salt must also stay stable or previously encrypted pastes could not
#   be opened.
set -e
cd ~/project

say() { echo "[wastebin] $*" >&2; }

# ~/.panelalpha survives a redeploy; ~/project is emptied every deploy
# (engine#173). The secrets must live here or they would be regenerated on every
# rebuild.
STORE_DIR="${HOME}/.panelalpha/wastebin"
APP_ENV="${STORE_DIR}/app.env"
NOTE="${STORE_DIR}/credentials.txt"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

# First deploy only. The env file's presence is the "already initialised" flag.
if [ ! -f "${APP_ENV}" ]; then
    # hex only, so a POSIX env file reads it back verbatim. 64 raw bytes ->
    # 128 hex chars, comfortably past wastebin's >= 64 byte requirement.
    SIGNING_KEY="$(openssl rand -hex 64)"
    # 16 hex chars of unique salt, replacing the public default "somesalt".
    PASSWORD_SALT="$(openssl rand -hex 8)"
    (
        umask 077
        cat > "${APP_ENV}" <<EOF
# Written once by PanelAlpha and never regenerated. Fed to the container as an
# env_file. Rotating either of these breaks outstanding delete/edit cookies
# (signing key) or previously encrypted pastes (salt), so they are stable.
WASTEBIN_SIGNING_KEY=${SIGNING_KEY}
WASTEBIN_PASSWORD_SALT=${PASSWORD_SALT}
EOF
        cat > "${NOTE}" <<EOF
Wastebin on this account
========================

Wastebin is a public-by-design pastebin. Reading a paste by its link is public
(that is the point of a pastebin) and creating a paste is open -- wastebin has
no instance login or HTTP basic auth, so creation cannot be gated without also
breaking public reading. Abuse surface is bounded instead by a paste size limit
and a short default expiry, both set in the compose file. Individual pastes can
optionally be password-encrypted by whoever creates them.

SECRETS (generated once, reused on every redeploy; a rebuild will not reset
them):
  WASTEBIN_SIGNING_KEY  - signs the cookies that let you delete/edit your own
                          pastes.
  WASTEBIN_PASSWORD_SALT - salts per-paste encryption passwords (replaces the
                          public upstream default "somesalt").

DATA
  Pastes live in /data/state.db on a named Docker volume, which survives redeploy
  and storage reclaim. The secrets above are kept in ${STORE_DIR} (0600). Do not
  delete this directory.
EOF
    )
    say "signing key and password salt written to ${APP_ENV}; notes in ${NOTE}"
else
    say "reusing the secrets in ${APP_ENV}"
fi

# The compose app service lists ~/project/.env as an env_file; make sure it
# exists even before the platform writes it, so `docker compose up` does not
# abort on a missing file. The account's env_vars are merged in and win.
touch .env
say "prepare complete"
