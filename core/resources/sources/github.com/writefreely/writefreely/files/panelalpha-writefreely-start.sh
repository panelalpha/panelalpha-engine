#!/bin/sh
# Entrypoint, as root: write a minimal config on first boot, generate keys,
# create or migrate the SQLite database, then run WriteFreely as `daemon`.
set -e
cd /go
C=/data/config.ini
if [ ! -f "$C" ]; then
  cat > "$C" <<INI
[server]
port = 8080
bind = 0.0.0.0
keys_parent_dir = /data

[database]
type = sqlite3
filename = /data/writefreely.db

[app]
site_name = WriteFreely
host = ${WF_HOST}
theme = write
single_user = false
open_registration = true
min_username_len = 3
max_blogs = 1
federation = true
public_stats = true
INI
fi
# The public address follows the project's domain.
sed -i "s#^host *=.*#host = ${WF_HOST}#" "$C"
[ -f /data/keys/email.aes256 ] || cmd/writefreely/writefreely -c "$C" keys generate
[ -f /data/writefreely.db ] || cmd/writefreely/writefreely -c "$C" db init
cmd/writefreely/writefreely -c "$C" db migrate
chown -R daemon:daemon /data
exec su -s /bin/sh daemon -c "exec cmd/writefreely/writefreely -c $C"
