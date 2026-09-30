# One-shot before the app is published: create_app() makes the schema and the
# passwordless "admin"; give it the generated password so /set-password is closed.
import os
import sys

sys.path.insert(0, "/shkeeper.io")  # the image's WORKDIR; this script lives in /pa
from shkeeper import create_app, db
from shkeeper.models import User

app = create_app()
with app.app_context():
    admin = User.query.filter_by(username="admin").first()
    if admin is None:
        raise SystemExit("[panelalpha/seed] admin was not created")
    if not admin.passhash:
        admin.passhash = User.get_password_hash(os.environ["SHKEEPER_ADMIN_PASSWORD"])
        db.session.commit()
        print("[panelalpha/seed] password set for 'admin'", flush=True)
    else:
        print("[panelalpha/seed] 'admin' already has a password; not touched", flush=True)
# the app's scheduler threads would keep the process alive
os._exit(0)
