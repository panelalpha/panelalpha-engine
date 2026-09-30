#!/bin/sh
# Writes lightnvr.ini with the generated admin password before LightNVR first
# starts, so the built-in admin/admin account is never created.
set -eu
: "${LIGHTNVR_ADMIN_PASSWORD:?missing}"
# LightNVR truncates [web] password to 31 characters without a warning.
[ "${#LIGHTNVR_ADMIN_PASSWORD}" -le 31 ] || { echo "lightnvr: admin password longer than 31 characters" >&2; exit 1; }
INI=/etc/lightnvr/lightnvr.ini
DB=/var/lib/lightnvr/data/database/lightnvr.db

if [ -s "$DB" ]; then
    echo "lightnvr: database exists, the admin account is already created"
    exit 0
fi

mkdir -p /etc/lightnvr /var/lib/lightnvr/data/database
if [ ! -f "$INI" ]; then
    cat > "$INI" <<'INI'
; LightNVR configuration, written once by the PanelAlpha recipe
; (upstream docker-entrypoint.sh defaults, admin password generated).
[general]
pid_file = /var/run/lightnvr.pid
log_file = /var/log/lightnvr/lightnvr.log
log_level = 2

[storage]
path = /var/lib/lightnvr/data/recordings
max_size = 0
retention_days = 30
auto_delete_oldest = true
record_mp4_directly = false
mp4_path = /var/lib/lightnvr/data/recordings/mp4
mp4_directory_format = year_month_day
mp4_segment_duration = 900
mp4_retention_days = 30

[database]
path = /var/lib/lightnvr/data/database/lightnvr.db

[web]
port = 8080
bind_ip = 0.0.0.0
root = /var/lib/lightnvr/www
auth_enabled = true
username = admin

[streams]
max_streams = 32

[models]
path = /var/lib/lightnvr/data/models

[api_detection]
url = http://localhost:9001/detect

[memory]
buffer_size = 1024
use_swap = true
swap_file = /var/lib/lightnvr/data/swap
swap_size = 134217728

[hardware]
hw_accel_enabled = false
hw_accel_device =

[go2rtc]
binary_path = /bin/go2rtc
config_dir = /etc/lightnvr/go2rtc
api_port = 1984
webrtc_enabled = true
webrtc_ice_servers = stun:stun.l.google.com:19302

[mqtt]
enabled = false

[onvif]
discovery_enabled = false
INI
fi

# First run only: set [web] auth on and password to the generated one.
awk -v pw="$LIGHTNVR_ADMIN_PASSWORD" '
    /^\[/ { if (web && !done) { print "password = " pw; done = 1 } web = ($0 == "[web]") }
    web && /^[ \t]*(password|auth_enabled)[ \t]*=/ { next }
    { print }
    web && /^\[web\]$/ { print "auth_enabled = true" }
    END { if (web && !done) print "password = " pw }
' "$INI" > "$INI.tmp"
mv "$INI.tmp" "$INI"
chmod 600 "$INI"
grep -qx "password = $LIGHTNVR_ADMIN_PASSWORD" "$INI" \
    || { echo "lightnvr: failed to set the admin password" >&2; exit 1; }
echo "lightnvr: admin password seeded into lightnvr.ini"
