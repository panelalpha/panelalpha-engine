# Run by start.sh through `manage.py shell`: Django's own user model. The admin
# scripts/init.py creates on a new database is admin / rssbox; it gets the
# engine's username and password only while it still has that password, so a
# password changed in RSSBox is never touched.
import os

from django.contrib.auth import get_user_model

User = get_user_model()
name = os.environ.get("RSSBOX_ADMIN_USERNAME") or "admin"
user = User.objects.filter(username="admin").first()
if user is not None and user.check_password("rssbox"):
    if name != "admin" and User.objects.filter(username=name).exists():
        raise SystemExit(f"a user {name} already exists")
    user.username = name
    user.set_password(os.environ["RSSBOX_ADMIN_PASSWORD"])
    user.save()
    print("[panelalpha] rssbox: the admin's login is the one the engine generated")
