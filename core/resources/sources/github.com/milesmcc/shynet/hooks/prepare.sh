#!/bin/bash
# Account shell, after the clone and after overrides/docker-compose.yml and
# files/ have been written, before the build.
#
# Two things the compose file cannot do for itself: put this account's secrets
# somewhere the next deploy will not delete, and write the tunable settings
# into ~/project/.env where the account's own env_vars can be merged over
# them.
set -e
cd ~/project

say() { echo "[shynet] $*" >&2; }

# ---------------------------------------------------------------------------
# 0. Is this the repository this recipe is about?
#
# A recipe is looked up by clone URL and a fork answers to the same one.
# Nothing below reads the checkout, but saying so turns a confusing result
# into one line in the deploy log.
if [ ! -f shynet/shynet/settings.py ] || [ ! -f shynet/manage.py ]; then
    say "WARNING: this does not look like milesmcc/shynet"
fi

# ---------------------------------------------------------------------------
# 1. Secrets.
#
# engine#173: every deploy re-clones and ProjectTree::clearContents()
# (GitRepository.php:89) empties ~/project first, so a guard on a file in
# there never fires. Regenerating DJANGO_SECRET_KEY logs every session out and
# invalidates every outstanding password-reset link; regenerating the postgres
# password locks the app out of the pgdata volume, which still holds the old
# one, and the deploy comes up on an authentication failure it cannot recover
# from. ProjectEnvironment::apply() also republishes ~/project/.env as
# .env.default at mode 644, so a database password may not be written there
# either.
#
# ~/.panelalpha/shynet/ survives the clone and is the only place in the
# account that does. Two files, not one: the database container has no
# business holding the administrator password or the key that signs sessions,
# and compose delivers each as its own env_file.
STORE_DIR="${HOME}/.panelalpha/shynet"
DB_ENV="${STORE_DIR}/db.env"
APP_ENV="${STORE_DIR}/app.env"
NOTE="${STORE_DIR}/credentials.txt"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

if [ ! -f "${APP_ENV}" ] || [ ! -f "${DB_ENV}" ]; then
    ADMIN_EMAIL="admin@localhost"
    # No '/', '+' or '=': these values are read back by a POSIX shell from an
    # unquoted env file, handed to psycopg2 in a DSN, and typed into a login
    # form.
    ADMIN_PASSWORD="$(openssl rand -base64 24 | tr -d '\n=/+')"
    PG_PASSWORD="$(openssl rand -base64 24 | tr -d '\n=/+')"
    # secrets.token_urlsafe() is what TEMPLATE.env tells the operator to use;
    # hex is the same entropy with nothing an env file can misread.
    SECRET_KEY="$(openssl rand -hex 32)"
    (
        umask 077
        cat > "${DB_ENV}" <<EOF
# Read by the postgres container on its first boot to create the role, and by
# nothing else. Written by PanelAlpha on the first deploy and never
# regenerated: the value is already baked into the pgdata volume, so changing
# it here would lock the application out of its own database.
POSTGRES_PASSWORD=${PG_PASSWORD}
EOF
        cat > "${APP_ENV}" <<EOF
# Written by PanelAlpha on the first deploy, and never regenerated. Deleting
# this file does not reset the application.

# shynet/settings.py:36 falls back to the literal string "onlyusethisindev",
# which ships in every copy of the repository and would sign every session
# cookie and password-reset token on this account.
DJANGO_SECRET_KEY=${SECRET_KEY}

# The same value as POSTGRES_PASSWORD in db.env; settings.py:124 reads it
# under this name.
DB_PASSWORD=${PG_PASSWORD}

# The superuser panelalpha/shynet/init.sh creates before the web container is
# allowed to start. Shynet has no sign-up page unless ACCOUNT_SIGNUPS_ENABLED
# is turned on, so without this the account has no way in.
SHYNET_ADMIN_EMAIL=${ADMIN_EMAIL}
SHYNET_ADMIN_PASSWORD=${ADMIN_PASSWORD}
EOF
        cat > "${NOTE}" <<EOF
Shynet administrator for this account
=====================================

  email:    ${ADMIN_EMAIL}
  password: ${ADMIN_PASSWORD}

Shynet logs in by email address, not by username
(shynet/settings.py:253). Created on the first deploy and never changed by
PanelAlpha afterwards -- if you change the password inside Shynet, the one in
the application wins and nothing here overwrites it.

Sign-ups are off (ACCOUNT_SIGNUPS_ENABLED=False in ~/project/.env, read at
shynet/settings.py:261 and enforced by dashboard/apps.py:9, which replaces
allauth's is_open_for_signup with one that always refuses). Turn it on only if
you want strangers able to register on this instance -- Shynet has no
invitation flow, and a registered user can see every other registered user.

"Forgot password" is not usable until you configure SMTP. shynet/settings.py:297
falls back to Django's console mail backend whenever EMAIL_HOST is unset, so the
reset mail -- including a working reset link for this account -- is printed to
the application container's log instead of being sent. Anyone who can read that
log can take the administrator account over. Set EMAIL_HOST, EMAIL_PORT,
EMAIL_HOST_USER, EMAIL_HOST_PASSWORD and SERVER_EMAIL in the project's
environment variables before relying on password reset, and treat the container
log as sensitive until you have.
EOF
    )
    say "administrator credentials written to ${NOTE}"
else
    say "reusing the secrets in ${STORE_DIR}"
fi

# ---------------------------------------------------------------------------
# 2. Settings that an operator may reasonably want to change.
#
# ~/project/.env is the base ProjectEnvironment::apply() merges the account's
# env_vars over, and both compose services read it as their first env_file --
# so a key written here is a default the panel can override, while a key in
# the compose file's own `environment:` cannot be. Structural values (DB_HOST,
# PORT, PA_PUBLIC_URL) are in the compose file for exactly that reason;
# everything below is a preference.
#
# Nothing secret goes in here: the file is mode 644 and is copied to
# .env.default.
write_default() {
    grep -q "^$1=" .env 2>/dev/null || printf '%s=%s\n' "$1" "$2" >> .env
}
touch .env

# settings.py:212 defaults to America/New_York, which is a choice made for
# upstream's own deployment and not for this account. Affects how every date
# in the dashboard is bucketed.
write_default TIME_ZONE UTC
# settings.py:261. Off is upstream's default and the only safe one on a public
# address: there is no invitation flow and no approval step, so on a site
# anyone can find, the first stranger to fill the form gets an account.
write_default ACCOUNT_SIGNUPS_ENABLED False
# settings.py:262. Only meaningful with sign-ups on, and there is no SMTP
# server configured by default -- settings.py:297 falls back to the console
# backend, so a verification mail would be written to the container log and
# nowhere else.
write_default ACCOUNT_EMAIL_VERIFICATION none
# webserver.sh passes this to gunicorn as --workers. The image's default is 1,
# and one sync worker serves one request at a time: with CELERY_TASK_ALWAYS_
# EAGER the tracking beacon is processed inside its own request, so a busy
# page's heartbeats would queue in front of the dashboard. Two, because each
# is a whole Django process.
write_default NUM_WORKERS 2
# settings.py:365 defaults this to False; upstream's own TEMPLATE.env ships it
# True and says why -- with it on, the visitor hash includes the date and the
# service id, so the same visitor cannot be correlated across two services or
# across a day boundary. The cost is that a session cannot span midnight.
write_default AGGRESSIVE_HASH_SALTING True
# settings.py:356. The footer otherwise advertises the exact Shynet version
# this account runs to anyone who opens the login page.
write_default SHOW_SHYNET_VERSION False
say "wrote defaults to ~/project/.env"
