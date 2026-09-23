#!/bin/bash
# Account shell, after the clone and after overrides/ and files/ are written,
# before the stack starts. One job: put this account's secrets somewhere the
# next clone will not delete, and tell the owner where the admin password is.
set -e
cd ~/project

say() { echo "[funkwhale] $*" >&2; }

# Is this the repository this recipe is about? A recipe is looked up by clone
# URL and a fork answers to the same one. Nothing below reads the checkout;
# saying so turns a confusing result into one log line.
if [ ! -f api/funkwhale_api/__init__.py ] && [ ! -f api/manage.py ]; then
    say "WARNING: this does not look like funkwhale/funkwhale"
fi

# ---------------------------------------------------------------------------
# Secrets. engine#173: every deploy re-clones and empties ~/project first, so a
# guard on a file in there never fires. ~/.panelalpha/funkwhale/ is the only
# place in the account that survives the clone. Each value has a different
# consequence if regenerated:
#
#   DJANGO_SECRET_KEY   signs sessions and password-reset/email tokens
#                       (settings/production.py:16); a new one logs everyone out.
#   POSTGRES_PASSWORD   already inside the pgdata volume after first boot;
#                       regenerating it locks the app out of its own database.
STORE_DIR="${HOME}/.panelalpha/funkwhale"
ENV_STORE="${STORE_DIR}/funkwhale.env"
NOTE="${STORE_DIR}/credentials.txt"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}" 2>/dev/null || true

if [ ! -f "${ENV_STORE}" ]; then
    # Funkwhale blocklists a set of reserved usernames -- admin, owner, root,
    # superuser, funkwhale... (common.py ACCOUNT_USERNAME_BLACKLIST) -- and the
    # signup serializer rejects them, so the seeded owner cannot be `admin`.
    ADMIN_USERNAME=administrator
    ADMIN_EMAIL=admin@localhost
    # No '/', '+' or '=' -- read back by a POSIX shell, written into an env file
    # with no quoting, and pasted into an API string.
    ADMIN_PASSWORD="$(openssl rand -base64 24 | tr -d '\n=/+')"
    PG_PASSWORD="$(openssl rand -base64 24 | tr -d '\n=/+')"
    SECRET_KEY="$(openssl rand -base64 45 | tr -d '\n')"
    (
        umask 077
        cat > "${ENV_STORE}" <<EOF
# Written by PanelAlpha on the first deploy, never regenerated. Deleting this
# file does not reset the pod: POSTGRES_PASSWORD is already in the pgdata volume
# and the administrator already exists in the database.

# settings/production.py:16 reads this with no default and raises without it.
DJANGO_SECRET_KEY=${SECRET_KEY}

# Read by postgres on its first boot to set the role password, and by the api
# (common.py:404-434 builds DATABASE_URL from DATABASE_PASSWORD). Same value
# under both names so the two never drift.
POSTGRES_PASSWORD=${PG_PASSWORD}
DATABASE_PASSWORD=${PG_PASSWORD}

# The superuser created by panelalpha/funkwhale/init.sh before anything listens.
# FUNKWHALE_CLI_USER_PASSWORD is the envvar the \`fw users create\` password
# option reads, so it never lands on the command line.
FUNKWHALE_ADMIN_USERNAME=${ADMIN_USERNAME}
FUNKWHALE_ADMIN_EMAIL=${ADMIN_EMAIL}
FUNKWHALE_CLI_USER_PASSWORD=${ADMIN_PASSWORD}
EOF
    )
    say "administrator credentials written to ${NOTE}"
else
    say "reusing the secrets in ${ENV_STORE}"
fi

# Re-assert the mode every deploy, but never let a chmod that cannot run (a file
# an operator left root-owned) end the deploy: fix what can be fixed, fail only
# if it is still readable by anyone but its owner.
chmod 600 "${ENV_STORE}" 2>/dev/null || true
mode="$(stat -c '%a' "${ENV_STORE}" 2>/dev/null || echo '')"
case "${mode}" in
    600|400) ;;
    '') say "WARNING: cannot stat ${ENV_STORE}" ;;
    *)
        say "${ENV_STORE} is mode ${mode} and cannot be changed; it holds this account's database and administrator passwords"
        exit 1
        ;;
esac

# ---------------------------------------------------------------------------
# The note. Written every deploy: it is derived from the store and a first
# deploy that failed after writing the env file would otherwise leave the owner
# with no note at all.
ADMIN_USERNAME="$(sed -n 's/^FUNKWHALE_ADMIN_USERNAME=//p' "${ENV_STORE}" | head -n 1)"
ADMIN_EMAIL="$(sed -n 's/^FUNKWHALE_ADMIN_EMAIL=//p' "${ENV_STORE}" | head -n 1)"
ADMIN_PASSWORD="$(sed -n 's/^FUNKWHALE_CLI_USER_PASSWORD=//p' "${ENV_STORE}" | head -n 1)"
(
    umask 077
    cat > "${NOTE}" <<EOF
Funkwhale administrator for this account
========================================

  username: ${ADMIN_USERNAME}
  email:    ${ADMIN_EMAIL}
  password: ${ADMIN_PASSWORD}

Open your site in a browser and sign in with the username and password above.
Public registration is closed by default; new users are created from the admin
interface, or you can open registration in Settings once you have configured an
SMTP server (EMAIL_CONFIG in this account's environment) for email confirmation.

Created on the first deploy and never changed by PanelAlpha afterwards. If you
change the password in the app, this file is out of date and the app wins.
EOF
)
