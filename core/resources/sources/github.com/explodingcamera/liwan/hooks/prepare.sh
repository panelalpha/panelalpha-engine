#!/bin/bash
# Account shell, after the clone and after files/ + overrides have been written,
# before the build. One job: put this account's administrator password
# somewhere the next deploy will not delete, and record it for the owner.
set -e
cd ~/project

say() { echo "[liwan] $*" >&2; }

if [ ! -f Cargo.toml ] || [ ! -f src/cli.rs ]; then
    say "WARNING: this does not look like explodingcamera/liwan"
fi

# engine#173: every deploy re-clones and empties ~/project, so a guard on a file
# in there never fires. Regenerating the admin password would roll a credential
# nobody was ever told while the database still holds the first one.
# ~/.panelalpha/liwan/ survives the clone and is the only place that does.
STORE_DIR="${HOME}/.panelalpha/liwan"
ADMIN_ENV="${STORE_DIR}/admin.env"
NOTE="${STORE_DIR}/credentials.txt"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

if [ ! -f "${ADMIN_ENV}" ]; then
    ADMIN_USER="admin"
    # No '/', '+' or '=': the value is read back by a POSIX shell from an
    # unquoted env file and typed into a login form.
    ADMIN_PASSWORD="$(openssl rand -base64 24 2>/dev/null | tr -d '\n=/+' || head -c 18 /dev/urandom | od -An -tx1 | tr -d ' \n')"
    (
        umask 077
        cat > "${ADMIN_ENV}" <<EOF
# Written by PanelAlpha on the first deploy and never regenerated: the account
# a value that already exists in liwan-app.sqlite. Read once by the container
# entrypoint to seed the first administrator.
LIWAN_ADMIN_USERNAME=${ADMIN_USER}
LIWAN_ADMIN_PASSWORD=${ADMIN_PASSWORD}
EOF
        cat > "${NOTE}" <<EOF
Liwan administrator for this account
====================================

  username: ${ADMIN_USER}
  password: ${ADMIN_PASSWORD}

Created on the first deploy and never changed by PanelAlpha afterwards. If you
change the password inside Liwan, that one wins and nothing here overwrites it.

The dashboard, all statistics and the admin surface are behind this login
(the session cookie is scoped to /api/dashboard). The tracking script
(/script.js) and the collection endpoint (POST /api/event) are public by
design -- that is how a browser reports a page view.
EOF
    )
    say "administrator credentials written to ${NOTE}"
else
    say "reusing the administrator secret in ${STORE_DIR}"
fi

# Pull the wrapper's base images now, through the account's nested daemon, so
# the build is not the slow path. Best effort.
docker pull ghcr.io/explodingcamera/liwan:latest >/dev/null 2>&1 || true
docker pull alpine:3 >/dev/null 2>&1 || true
