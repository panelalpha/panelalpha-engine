#!/bin/bash
# One-shot, run to completion before the web container is allowed to start.
#
# Three jobs, and the order is the point.
#
# 1. Migrate. The image does this too, but startup_checks.sh runs *before*
#    webserver.sh only in the sense of the shell's `&&` -- the site is
#    published the moment gunicorn binds, and on a first boot that is while
#    the schema is still being created.
# 2. Create the administrator. Shynet has no anonymous first-user form, so
#    this is not a security race the way Tandoor's /setup/ is; it is the
#    difference between an account that can be logged into and one whose owner
#    has no way in at all. `manage.py registeradmin` exists but invents its own
#    password and prints it to a deploy log, so the password is generated in
#    hooks/prepare.sh instead and written to a file in the account.
# 3. Point the Django Site at this account's domain. allauth builds
#    password-reset links from it and it ships as `example.com`.
#
# Exiting non-zero fails the deploy: `app` depends on this with
# service_completed_successfully, so a database that never came up or a
# superuser that could not be created stops the stack rather than publishing
# something half-configured.
set -e

say() { echo "[panelalpha/init] $*"; }

cd /usr/src/shynet

# compose already gates this on the db healthcheck; this is the second belt,
# for the case where postgres answers pg_isready on its bootstrap socket and
# is still restarting for the real one. No pg_isready in this image -- the
# Dockerfile installs postgresql-libs, not the client -- so psycopg2, which is
# what Django will use anyway, asks the question.
attempt=0
until python - <<'PY' 2>/dev/null
import os, sys
import psycopg2
try:
    psycopg2.connect(
        host=os.environ["DB_HOST"], port=os.environ.get("DB_PORT", "5432"),
        dbname=os.environ["DB_NAME"], user=os.environ["DB_USER"],
        password=os.environ["DB_PASSWORD"], connect_timeout=5,
    ).close()
except Exception:
    sys.exit(1)
PY
do
    attempt=$((attempt + 1))
    if [ "${attempt}" -ge 60 ]; then
        say "database ${DB_HOST}:${DB_PORT:-5432} never accepted a connection"
        exit 1
    fi
    sleep 2
done

say "migrating"
python manage.py migrate --noinput

# `manage.py shell -c` prints whatever the Django app registry prints on
# import as well as what the snippet prints -- Django 4.1 has no --no-imports
# to quiet it, and an app that logs a line on ready() would turn a bare
# `print(count)` into something that is not a number. Measured on this image
# the output is clean, but reading a marker out of the stream costs nothing
# and cannot be fooled by a line appearing later.
count_users() {
    python manage.py shell -c \
        'from core.models import User; print("PACOUNT:%d" % User.objects.count())' 2>/dev/null \
        | sed -n 's/.*PACOUNT:\([0-9][0-9]*\).*/\1/p' | tail -n 1
}

users="$(count_users)"
say "existing users: ${users:-unreadable}"
if [ -z "${users}" ]; then
    say "could not read the user count; refusing to guess"
    exit 1
fi

if [ "${users}" = "0" ]; then
    if [ -z "${SHYNET_ADMIN_EMAIL:-}" ] || [ -z "${SHYNET_ADMIN_PASSWORD:-}" ]; then
        say "no administrator credentials in the environment; refusing to leave this account with no way in"
        exit 1
    fi
    say "creating administrator ${SHYNET_ADMIN_EMAIL}"
    # core.models.User keeps AbstractUser's username field but defaults it to a
    # uuid and logs in by email (settings.py:253,256), which is exactly what
    # core/management/commands/registeradmin.py does.
    python manage.py shell -c '
import os, uuid
from core.models import User
User.objects.create_superuser(
    str(uuid.uuid4()),
    email=os.environ["SHYNET_ADMIN_EMAIL"],
    password=os.environ["SHYNET_ADMIN_PASSWORD"],
)
print("PACREATED:1")
'
    # create_superuser can be a no-op on a race; confirm rather than assume.
    users="$(count_users)"
    if [ "${users:-0}" = "0" ]; then
        say "the administrator was not created; stopping"
        exit 1
    fi
else
    # Never touched again. The password in ~/.panelalpha/shynet/ is the one
    # this account was created with; if it was changed inside Shynet, that is
    # the owner's business and overwriting it here would be data loss.
    say "an account already exists; leaving it alone"
fi

# django.contrib.sites ships example.com and Shynet's own startup_checks
# treats that literal as "not configured". The dashboard builds its tracking
# snippet from request.get_host() rather than from here, so this is not what
# makes the snippet correct -- but allauth's password-reset mail is built from
# it, and a reset link pointing at example.com is a dead link.
host=$(printf '%s' "${PA_PUBLIC_URL:-}" | sed -e 's#^[a-zA-Z][a-zA-Z0-9+.-]*://##' -e 's#/.*##')
case "${host}" in localhost|localhost:*|'') host='' ;; esac
if [ -n "${host}" ]; then
    SHYNET_SITE_HOST="${host}" python manage.py shell -c '
import os
from django.conf import settings
from django.contrib.sites.models import Site
site = Site.objects.get(pk=settings.SITE_ID)
site.domain = os.environ["SHYNET_SITE_HOST"]
if site.name in ("", "example.com"):
    site.name = "Shynet"
site.save()
print("PASITE:%s" % site.domain)
'
fi

say "done"
