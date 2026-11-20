# Sets the engine's password on the admin an older deploy of this recipe
# created as admin / admin (docker-init.sh's default before ADMIN_PASSWORD
# came from the engine), while it still has that password, through
# Flask-AppBuilder's own security manager. Run by superset-init after
# docker-init.sh. A password changed in Superset is never touched.
import os

from werkzeug.security import check_password_hash

from superset.app import create_app

password = os.environ.get("ADMIN_PASSWORD") or ""
app = create_app()
with app.app_context():
    from superset import security_manager

    user = security_manager.find_user(username="admin")
    if not password:
        print("[panelalpha] superset: no ADMIN_PASSWORD; the admin login is left as it is")
    elif user is None or not check_password_hash(user.password or "", "admin"):
        print("[panelalpha] superset: the admin's password is not the default; left alone")
    else:
        security_manager.reset_password(user.id, password)
        print("[panelalpha] superset: the admin's password is the one the engine generated")
