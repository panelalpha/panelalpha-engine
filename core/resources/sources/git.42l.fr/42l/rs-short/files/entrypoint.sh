#!/bin/sh
# Renders config.toml at start: the account domain arrives only as $PUBLIC_URL,
# and the two secrets are generated once into the persisted db volume so links,
# admin keys and sessions survive a redeploy.
set -eu

DB_DIR=/run_dir/db
SECRETS="$DB_DIR/secrets.env"
CONFIG=/run_dir/config.toml

mkdir -p "$DB_DIR"

if [ ! -f "$SECRETS" ]; then
    # cookie_key: base64 of 64 random bytes (rs-short needs decoded >= 64 bytes)
    COOKIE_KEY="$(head -c 64 /dev/urandom | base64 | tr -d '\n')"
    # phishing_password: 48 hex chars, URL-safe, well over the 16-char minimum
    PHISHING_PASSWORD="$(head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n')"
    ( umask 077; printf 'COOKIE_KEY=%s\nPHISHING_PASSWORD=%s\n' \
        "$COOKIE_KEY" "$PHISHING_PASSWORD" > "$SECRETS" )
fi
. "$SECRETS"

# Public base URL for the short links = the account domain, no trailing slash.
BASE_URL="${PUBLIC_URL:-${URL:-${BASE_URL:-}}}"
BASE_URL="$(printf '%s' "$BASE_URL" | sed 's:/*$::')"
[ -n "$BASE_URL" ] || BASE_URL="http://localhost:8080"

cat > "$CONFIG" <<EOF
config_version = 3

[general]
listening_address = "0.0.0.0:8080"
database_path = "/run_dir/db/db.sqlite"
instance_hostname = "$BASE_URL"
hoster_name = "PanelAlpha"
hoster_hostname = "panelalpha.com"
hoster_tos = "$BASE_URL"
contact = "mailto:admin@localhost"
theme = "light"
cookie_key = "$COOKIE_KEY"
captcha_difficulty = 3

[phishing]
verbose_console = false
verbose_suspicious = true
verbose_level = "notice"
suspicious_click_count = 25
suspicious_click_timeframe = 12
phishing_password = "$PHISHING_PASSWORD"
EOF

exec /run_dir/rs-short
