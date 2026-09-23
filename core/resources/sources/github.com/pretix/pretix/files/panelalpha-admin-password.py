# Replaces the password pretix ships its initial user with.
#
# pretix's very first migration (pretixbase/0001_initial.py, and the squashed
# 0001_squashed_0028) does not just create tables -- its `initial_user` data
# migration creates `admin@localhost` with `make_password('admin')` and
# is_staff set. So a pretix that has only ever been migrated is already open to
# anyone who has read its repository, and `createsuperuser` for that address
# does not work either: it exits 1 with "That Email is already taken".
#
# Run through `pretix shell` (Django reads and execs stdin when it is not a
# tty), once per boot, from the override's start command.
#
# Guarded on the password still being the shipped default, rather than on a
# first-boot marker. The generated password in .env is written once and never
# rolled, so re-applying it every boot would be harmless -- but an operator who
# has since changed the password in the web interface would find it reset under
# them on the next redeploy. Checking the default is the narrower statement:
# rotate what upstream published, never what somebody chose.
import os

from pretix.base.models import User

u = User.objects.filter(email=os.environ.get("PRETIX_ADMIN_EMAIL", "")).first()
if u is not None and u.check_password("admin"):
    u.set_password(os.environ["PRETIX_ADMIN_PASSWORD"])
    u.is_staff = True
    u.save(update_fields=["password", "is_staff"])
    print("panelalpha: replaced the shipped default password of " + u.email)
else:
    print("panelalpha: admin password is not the shipped default; left alone")
