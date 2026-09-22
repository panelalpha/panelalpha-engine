#!/bin/sh
# One-shot owner seed, run before the web and worker serve. Idempotent: adds the
# owner only when the user database is empty, so a redeploy never resets it.
set -e

CONFIG="${GRAMPS_API_CONFIG:-/app/config/config.cfg}"
OWNER_USER="${GRAMPSWEB_OWNER_USER:-owner}"
OWNER_EMAIL="${GRAMPSWEB_OWNER_EMAIL:-owner@localhost}"

cd /app/src

# Bring the user DB schema up to date (and create it if missing). The image's
# own entrypoint does this too, but this service overrides that entrypoint.
python3 -m gramps_webapi --config "${CONFIG}" user migrate || true

# Count existing users through the app. get_number_users() with no filter counts
# every role, so any account at all -- not just an owner -- means "already
# seeded". Prints -1 if the app cannot be booted, which is treated as "do not
# touch" rather than "seed blindly".
EXISTING="$(python3 - <<'PY'
import sys
try:
    from gramps_webapi.app import create_app
    from gramps_webapi.auth import user_db, get_number_users
    app = create_app()
    with app.app_context():
        user_db.create_all()
        print(get_number_users())
except Exception as exc:  # noqa: BLE001
    print("could not read user DB:", exc, file=sys.stderr)
    print(-1)
PY
)"

case "${EXISTING}" in
    0)
        if [ -z "${GRAMPSWEB_OWNER_PASSWORD}" ]; then
            echo "[gramps] no owner password supplied; cannot seed owner" >&2
            exit 1
        fi
        python3 -m gramps_webapi --config "${CONFIG}" user add \
            "${OWNER_USER}" "${GRAMPSWEB_OWNER_PASSWORD}" \
            --fullname "Site Owner" --email "${OWNER_EMAIL}" --role 5
        echo "[gramps] owner '${OWNER_USER}' created (role 5)"
        ;;
    -1)
        echo "[gramps] WARNING: user DB unreadable; leaving it alone" >&2
        ;;
    *)
        echo "[gramps] ${EXISTING} user(s) already present; owner left untouched"
        ;;
esac
