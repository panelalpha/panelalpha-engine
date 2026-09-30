#!/bin/bash
# Account shell, after the clone and after overrides/ and files/ are written,
# before the stack starts. One job: put this account's secrets somewhere the
# next clone will not delete. The administrator login is the engine's
# (`credentials:` in panelalpha.yaml), in ~/.panelalpha/app-credentials.env.
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

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}" 2>/dev/null || true

if [ ! -f "${ENV_STORE}" ]; then
    # No '/', '+' or '=' -- read back by a POSIX shell, written into an env file
    # with no quoting, and pasted into an API string.
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
EOF
    )
    say "secrets written to ${ENV_STORE}"
else
    say "reusing the secrets in ${ENV_STORE}"
fi
# An older deploy kept the administrator login here too; the engine adopted it.
sed -i '/^FUNKWHALE_ADMIN_\(USERNAME\|EMAIL\)=\|^FUNKWHALE_CLI_USER_PASSWORD=\|^# The superuser created by panelalpha\|^# FUNKWHALE_CLI_USER_PASSWORD is the envvar\|^# option reads, so it never lands/d' "${ENV_STORE}"

# Re-assert the mode every deploy, but never let a chmod that cannot run (a file
# an operator left root-owned) end the deploy: fix what can be fixed, fail only
# if it is still readable by anyone but its owner.
chmod 600 "${ENV_STORE}" 2>/dev/null || true
mode="$(stat -c '%a' "${ENV_STORE}" 2>/dev/null || echo '')"
case "${mode}" in
    600|400) ;;
    '') say "WARNING: cannot stat ${ENV_STORE}" ;;
    *)
        say "${ENV_STORE} is mode ${mode} and cannot be changed; it holds this account's database password"
        exit 1
        ;;
esac
