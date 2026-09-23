#!/bin/bash
# After the clone, before `docker compose up`. Writes the secrets nothing else
# can supply, once, into a directory the next clone will not delete.
set -e
cd ~/project

say() { echo "[gramps] $*" >&2; }

# engine#173: every deploy re-clones and ~/project is emptied first, so a secret
# kept in there would be regenerated every time. ~/.panelalpha/gramps/ survives
# the wipe and is the only place in the account that does.
#   GRAMPSWEB_SECRET_KEY   Flask signs its session/JWT tokens with it; a new one
#                          logs every client out.
#   owner password         the account already exists in the users volume after
#                          the first deploy -- a regenerated password is one the
#                          database never learns (init.sh only adds a missing
#                          owner, it never resets an existing one).
STORE_DIR="${HOME}/.panelalpha/gramps"
ENV_STORE="${STORE_DIR}/gramps.env"
NOTE="${STORE_DIR}/credentials.txt"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}" 2>/dev/null || true

if [ ! -f "${ENV_STORE}" ]; then
    # No '/', '+' or '=' in the password: it is read back by a POSIX shell from
    # an unquoted env file and pasted into a JSON login body.
    OWNER_PASSWORD="$(openssl rand -base64 24 | tr -d '\n=/+' | cut -c1-24)"
    SECRET_KEY="$(openssl rand -hex 32)"
    (
        umask 077
        cat > "${ENV_STORE}" <<EOF
# Written by PanelAlpha on the first deploy of Gramps Web, and never
# regenerated. Deleting this file does not reset the application: the owner
# already exists in the gramps_users volume.
GRAMPSWEB_SECRET_KEY=${SECRET_KEY}
GRAMPSWEB_OWNER_USER=owner
GRAMPSWEB_OWNER_EMAIL=owner@localhost
GRAMPSWEB_OWNER_PASSWORD=${OWNER_PASSWORD}
EOF
    )
    say "owner credentials written to ${NOTE}"
else
    say "reusing the secrets in ${ENV_STORE}"
fi

# Re-assert the mode every deploy rather than trust the umask, but never let a
# chmod that cannot run (a file an operator re-owned as root) end the deploy;
# fail only if the file is still readable by anyone but its owner.
chmod 600 "${ENV_STORE}" 2>/dev/null || true
mode="$(stat -c '%a' "${ENV_STORE}" 2>/dev/null || echo '')"
case "${mode}" in
    600|400) ;;
    '') say "WARNING: cannot stat ${ENV_STORE}" ;;
    *)
        say "${ENV_STORE} is mode ${mode} and cannot be secured; it holds this account's owner password"
        exit 1
        ;;
esac

# The note is derived from the store and rewritten each deploy, so a first
# deploy that failed after writing the env file still leaves the owner a note.
OWNER_USER="$(sed -n 's/^GRAMPSWEB_OWNER_USER=//p' "${ENV_STORE}" | head -n 1)"
OWNER_PW="$(sed -n 's/^GRAMPSWEB_OWNER_PASSWORD=//p' "${ENV_STORE}" | head -n 1)"
(
    umask 077
    cat > "${NOTE}" <<EOF
Gramps Web owner account for this account
=========================================

  username: ${OWNER_USER}
  password: ${OWNER_PW}

Log in at your site's address, or from the API:

  curl -s https://<your-domain>/api/token/ \\
    -H 'content-type: application/json' \\
    -d '{"username":"${OWNER_USER}","password":"..."}'

Created on the first deploy and never changed by PanelAlpha afterwards. If you
change the password in the app, this file is out of date and the app wins.
EOF
)
