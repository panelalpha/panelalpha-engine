# Sets the engine's login on LibreTime's built-in admin while it still has the
# default password `admin`. Run by `libretime-api shell` in the migrate one-shot,
# after the migration, through LibreTime's own User model (cc_subjs, md5 hash as
# its set_password writes it). A password changed in LibreTime is never touched.
import os

from libretime_api.core.models import User

name = os.environ.get("LT_ADMIN_USERNAME") or "admin"
password = os.environ.get("LT_ADMIN_PASSWORD") or ""
if not password:
    print("[panelalpha] libretime: no LT_ADMIN_PASSWORD; the admin login is left as it is")
else:
    # A new database gets admin/admin (legacy/migrations/sql/data.sql).
    user = User.objects.filter(username=name).first() or User.objects.filter(username="admin").first()
    if user is None or not user.check_password("admin"):
        print("[panelalpha] libretime: the admin no longer has the default password; left alone")
    else:
        user.username = name
        user.set_password(password)
        user.save(update_fields=["username", "password"])
        print("[panelalpha] libretime: the admin's login is the one the engine generated")
