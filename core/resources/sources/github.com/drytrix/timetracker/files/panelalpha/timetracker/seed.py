# Runs before the published app on every boot: upstream's own startup steps
# (/app/start.py: wait for DB, migrate, default rows), then closes first run.
import os
import sys

sys.path.insert(0, "/app")
os.chdir("/app")
os.environ["FLASK_APP"] = "app"
import start  # noqa: E402  upstream docker/start-fixed.py


def say(msg):
    print(f"[panelalpha/seed] {msg}", flush=True)


if not start.wait_for_database():
    sys.exit("database not reachable")
app = start.run_migrations()
if not app or not start.verify_core_tables(app):
    sys.exit("migrations failed")
start.ensure_default_data(app)

with app.app_context():
    from app import db
    from app.models import Settings, User
    from app.utils.installation import get_installation_config

    # upstream creates the admin with no password; the first login would set it
    name = os.environ["ADMIN_USERNAMES"].split(",")[0].strip().lower()
    admin = User.query.filter_by(username=name).first()
    if admin is None:
        sys.exit(f"admin '{name}' was not created")
    if not admin.has_password:
        admin.set_password(os.environ["TT_ADMIN_PASSWORD"])
        db.session.commit()
        say(f"password set for '{name}'")
    else:
        say(f"'{name}' already has a password; not touched")

    # /setup is unauthenticated until completed; finish it with defaults once
    inst = get_installation_config()
    if not inst.is_setup_complete():
        settings = Settings.get_settings()
        settings.allow_self_register = False
        db.session.commit()
        inst.mark_setup_complete(telemetry_enabled=False)
        say("first-run wizard completed, self-registration off")
