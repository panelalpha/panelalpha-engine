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
# The owner login is the engine's (`credentials:` in panelalpha.yaml), in
# ~/.panelalpha/app-credentials.env.
STORE_DIR="${HOME}/.panelalpha/gramps"
ENV_STORE="${STORE_DIR}/gramps.env"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}" 2>/dev/null || true

if [ ! -f "${ENV_STORE}" ]; then
    SECRET_KEY="$(openssl rand -hex 32)"
    (
        umask 077
        cat > "${ENV_STORE}" <<EOF
# Written by PanelAlpha on the first deploy of Gramps Web, and never
# regenerated. Deleting it logs every client out.
GRAMPSWEB_SECRET_KEY=${SECRET_KEY}
EOF
    )
    say "secret written to ${ENV_STORE}"
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
        say "${ENV_STORE} is mode ${mode} and cannot be secured; it holds this account's secret key"
        exit 1
        ;;
esac
