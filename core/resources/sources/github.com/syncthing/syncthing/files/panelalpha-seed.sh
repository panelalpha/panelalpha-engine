#!/bin/sh
# Sets the GUI login from ~/.panelalpha/syncthing/admin.env, once: if config.xml
# already holds a password (seeded, or changed by the user) it is left alone.
set -eu
: "${SYNCTHING_GUI_USER:?missing}" "${SYNCTHING_GUI_PASSWORD:?missing}"
CFG="${STHOMEDIR}/config.xml"

if [ -f "$CFG" ] && grep -q '<password>..*</password>' "$CFG"; then
    echo "syncthing: GUI password present, nothing to seed"
else
    # Creates keys + config on first run; the password is read from stdin (bcrypt-hashed).
    printf '%s\n' "$SYNCTHING_GUI_PASSWORD" \
        | /bin/syncthing generate --no-port-probing --gui-user "$SYNCTHING_GUI_USER" --gui-password -
    echo "syncthing: GUI login '$SYNCTHING_GUI_USER' set"
fi

grep -q '<user>..*</user>' "$CFG" && grep -q '<password>\$2[aby]\$' "$CFG" \
    || { echo "syncthing: GUI authentication is not configured" >&2; exit 1; }
