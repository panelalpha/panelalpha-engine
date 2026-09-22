#!/bin/bash
# Runs in the account shell after the clone and after overrides/ and files/ are
# in place, before `docker compose up`. Two jobs the compose file cannot do for
# itself: keep this account's secrets somewhere a redeploy will not delete, and
# render config.toml (which upstream expects the operator to create) from them.
set -e
cd ~/project

say() { echo "[libredesk] $*" >&2; }

# ~/.panelalpha/libredesk/ survives a redeploy; ~/project is emptied every
# deploy (engine#173). A secret written under ~/project would be regenerated on
# every rebuild -- a new DB password locks the app out of the postgres volume
# that still holds the old one, a new encryption_key breaks stored encrypted
# data, and a new admin password would silently change the owner's login.
STORE_DIR="${HOME}/.panelalpha/libredesk"
SECRETS="${STORE_DIR}/secrets.env"
DB_ENV="${STORE_DIR}/db.env"
ADMIN_ENV="${STORE_DIR}/admin.env"
NOTE="${STORE_DIR}/credentials.txt"

mkdir -p "${STORE_DIR}"
chmod 700 "${HOME}/.panelalpha" "${STORE_DIR}"

if [ ! -f "${SECRETS}" ]; then
    # No '/', '+', '=' or '#': read back cleanly from an unquoted env file and
    # from a TOML value. The Aa1! suffix guarantees the System user password
    # meets libredesk's strength rule (>=10 chars, upper+lower+digit+special).
    PG_PASSWORD="$(openssl rand -base64 24 | tr -d '\n=/+#')"
    ADMIN_PASSWORD="$(openssl rand -base64 24 | tr -d '\n=/+#')Aa1!"
    # encryption_key must be exactly 32 chars (openssl rand -hex 16).
    ENCRYPTION_KEY="$(openssl rand -hex 16)"
    (
        umask 077
        cat > "${SECRETS}" <<EOF
# Source of truth for this account's libredesk secrets. Written once on the
# first deploy and never regenerated; a rebuild reuses these values, which is
# what keeps the database, encrypted data and the admin login working.
POSTGRES_PASSWORD=${PG_PASSWORD}
ENCRYPTION_KEY=${ENCRYPTION_KEY}
SYSTEM_USER_PASSWORD=${ADMIN_PASSWORD}
EOF
    )
    say "secrets written to ${STORE_DIR}"
else
    say "reusing the secrets in ${STORE_DIR}"
fi

# shellcheck disable=SC1090
. "${SECRETS}"

# Derive the two env files the compose services read directly. Rewritten every
# deploy from secrets.env (same values), so they stay in sync with it.
(
    umask 077
    # Consumed by the postgres container on first init; baked into pgdata.
    printf 'POSTGRES_PASSWORD=%s\n' "${POSTGRES_PASSWORD}" > "${DB_ENV}"
    # Consumed by the `install` one-shot; --install reads this env to create the
    # System user (internal/user: IsStrongPassword is enforced).
    printf 'LIBREDESK_SYSTEM_USER_PASSWORD=%s\n' "${SYSTEM_USER_PASSWORD}" > "${ADMIN_ENV}"
)

# Render config.toml at the project root (mounted read-only into the app and
# install containers). ~/project is wiped each deploy, so it is regenerated
# every time from the persisted secrets. Settings changed in the UI live in the
# DB, not here. env=prod, secure cookies on (the engine terminates TLS).
umask 077
cat > config.toml <<EOF
[app]
log_level = "info"
env = "prod"
check_updates = false
encryption_key = "${ENCRYPTION_KEY}"

[app.server]
address = "0.0.0.0:9000"
socket = ""
disable_secure_cookies = false
session_lifetime = "9h"
read_timeout = "60s"
write_timeout = "60s"
max_body_size = 104857600
read_buffer_size = 65536
keepalive_timeout = "10s"

[auth]
local_login_enabled = true

[upload]
provider = "fs"

[upload.fs]
upload_path = 'uploads'
expiry = "1h"

[upload.s3]
url = ""
access_key = ""
secret_key = ""
region = "ap-south-1"
bucket = "bucket-name"
bucket_path = ""
expiry = "30m"

[db]
host = "db"
port = 5432
user = "libredesk"
password = "${POSTGRES_PASSWORD}"
database = "libredesk"
ssl_mode = "disable"
max_open = 30
max_idle = 30
max_lifetime = "300s"

[redis]
address = "redis:6379"
user = ""
password = ""
db = 0

[message]
outgoing_queue_workers = 10
incoming_queue_workers = 10
message_outgoing_scan_interval = "50ms"
incoming_queue_size = 5000
outgoing_queue_size = 5000

[notification]
concurrency = 2
queue_size = 2000

[automation]
worker_count = 10

[ai_agent]
worker_count = 10
queue_size = 1000
max_steps = 6
max_history_messages = 30

[autoassigner]
autoassign_interval = "5m"

[webhook]
workers = 5
queue_size = 10000
timeout = "15s"

[ssrf]
enabled = false
allowed_cidrs = []

[conversation]
unsnooze_interval = "5m"
draft_retention_duration = "360h"
continuity_scan_interval = "5m"

[sla]
evaluation_interval = "5m"

[rate_limit.widget]
enabled = true
requests_per_minute = 100

[rate_limit.auth]
enabled = true
requests_per_minute = 30

[rate_limit.public]
enabled = true
requests_per_minute = 100

[rate_limit.media]
enabled = true
requests_per_minute = 300
EOF
chmod 600 config.toml

# Onboarding note with the admin login (never surfaced anywhere else).
cat > "${NOTE}" <<EOF
Libredesk on this account
=========================

Libredesk is an omnichannel customer support desk (shared inbox, live chat,
help center). The agent/admin console and its API sit behind a login; the
help center and live-chat widget are public by design once you publish them.

ADMIN LOGIN (the System user, seeded once on the first deploy)
  URL:      <this account's URL>/
  Email:    System
  Password: ${SYSTEM_USER_PASSWORD}

  The password is set only when the schema is first installed and is reused on
  every redeploy; it is never reset by a rebuild. Change it from the console, or
  with: docker exec -it <app container> ./libredesk --set-system-user-password

SECRETS
  The database password, the config encryption_key and the admin password live
  in ${STORE_DIR} (0600) and are reused on every redeploy. Do not delete this
  directory -- losing it strands the postgres volume and the encrypted data.

OPTIONAL
  SSO (Google / Microsoft / any OIDC) is supported and configured from the
  console. Email (inbound/outbound), the live-chat widget and the help center
  are set up in the console after first login. The public app URL used in
  email/help-center links is set under General Settings in the console.
EOF

say "prepare complete"
