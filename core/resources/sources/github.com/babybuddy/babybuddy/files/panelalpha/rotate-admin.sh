#!/bin/bash
# One-shot: closes the shipped admin/admin default.
#
# Baby Buddy's overridden `migrate` command creates a superuser admin/admin
# whenever the database has no superuser (babybuddy/management/commands/
# migrate.py). This runs after the web service is healthy -- so migrations ran
# and the user exists -- and sets admin's password to the per-account secret
# seeded once in ~/.panelalpha. /config is a named volume nothing can pre-seed a
# database into, so the default cannot be pre-empted; it is rotated here instead,
# before the `ready` gate lets the deploy finish.
#
# Idempotent: if admin already has the target password (a redeploy), it is left
# unchanged, so a re-hash does not invalidate live sessions.
set -e

cd /app/www/public

export DJANGO_SETTINGS_MODULE="babybuddy.settings.base"
# Same key the web service runs with; fall back to the image's persisted one.
export SECRET_KEY="${SECRET_KEY:-$(cat /config/.secretkey 2>/dev/null || true)}"
: "${DB_NAME:=/config/data/db.sqlite3}"
export DB_NAME
: "${BB_ADMIN_PASSWORD:?BB_ADMIN_PASSWORD is not set; prepare.sh did not run}"

python3 manage.py shell -c '
import os
from django.contrib.auth import get_user_model

User = get_user_model()
pw = os.environ["BB_ADMIN_PASSWORD"]
u = User.objects.filter(username="admin").first()
if u is None:
    print("[panelalpha] no admin user found; nothing to rotate")
elif u.check_password(pw):
    print("[panelalpha] admin already rotated; password left unchanged")
else:
    u.set_password(pw)
    u.is_superuser = True
    u.is_staff = True
    u.save()
    print("[panelalpha] admin password rotated to the generated secret")
'

# This one-shot runs as root (no /init here), so any file Django created during
# the write is root-owned; hand /config/data back to the account uid the web
# container runs as, or the next boot cannot write the database.
chown -R "${PUID:-911}:${PGID:-911}" /config/data 2>/dev/null || true
