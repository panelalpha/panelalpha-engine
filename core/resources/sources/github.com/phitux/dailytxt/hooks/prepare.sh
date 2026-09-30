#!/bin/bash
# After the clone, before `docker compose up`. Generates the cookie secret, the
# admin-panel password and the first user's password once into
# ~/.panelalpha/dailytxt/, which survives redeploys (~/project is wiped).
set -e
cd ~/project

say() { echo "[panelalpha] dailytxt: $*" >&2; }

STORE="${HOME}/.panelalpha/dailytxt"
SECRET_ENV="${STORE}/secret.env"
USER_ENV="${STORE}/user.env"
NOTE="${STORE}/credentials.txt"

mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

rnd() { openssl rand -base64 "$1" | tr -d '\n=/+'; }

if [ ! -f "${SECRET_ENV}" ] || [ ! -f "${USER_ENV}" ]; then
    ADMIN_PASSWORD="$(rnd 24)"
    USER_PASSWORD="$(rnd 24)"
    (
        umask 077
        printf 'SECRET_TOKEN=%s\nADMIN_PASSWORD=%s\n' "$(openssl rand -base64 32 | tr -d '\n')" "${ADMIN_PASSWORD}" > "${SECRET_ENV}"
        printf 'DAILYTXT_USER=admin\nDAILYTXT_PASSWORD=%s\n' "${USER_PASSWORD}" > "${USER_ENV}"
        cat > "${NOTE}" <<NOTE_EOF
DailyTxT on this account
========================

Public registration is closed. The first diary user was created on the first
deploy, before the site was reachable.

DIARY LOGIN
  Username: admin
  Password: ${USER_PASSWORD}

ADMIN PANEL (Settings > Admin, while logged in)
  Admin password: ${ADMIN_PASSWORD}
  From there registration can be opened for a few minutes to add a user.

Entries are encrypted with a key derived from the user's password: change it
only inside DailyTxT (Settings), and keep it - a lost password cannot be
recovered without backup codes. A redeploy never resets anything; users.json
and the encrypted diary live on the named volume dailytxt-data.
NOTE_EOF
    )
    say "secrets and credentials written to ${NOTE}"
else
    say "reusing the secrets in ${STORE}"
fi
chmod 600 "${SECRET_ENV}" "${USER_ENV}" "${NOTE}" 2>/dev/null || true
say "prepare complete"
