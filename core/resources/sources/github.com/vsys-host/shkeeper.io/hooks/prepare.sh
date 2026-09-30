#!/bin/bash
# Generate the session key, the /metrics credentials and the admin password
# once, where a redeploy will not wipe them (~/project is emptied, engine#173).
set -e
cd ~/project

say() { echo "[shkeeper] $*" >&2; }

STORE="${HOME}/.panelalpha/shkeeper"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

rnd() { openssl rand -base64 "$1" | tr -d '\n=/+'; }

if [ ! -f "${STORE}/app.env" ] || [ ! -f "${STORE}/admin.env" ]; then
    ADMIN_PASSWORD="$(rnd 18)"
    METRICS_PASSWORD="$(rnd 18)"
    (
        umask 077
        cat > "${STORE}/app.env" <<ENV_EOF
# Written once. SECRET_KEY signs sessions (without it every restart logs
# everyone out); /metrics defaults to shkeeper/shkeeper without these.
SECRET_KEY=$(openssl rand -hex 32)
METRICS_USERNAME=metrics
METRICS_PASSWORD=${METRICS_PASSWORD}
ENV_EOF
        printf 'SHKEEPER_ADMIN_PASSWORD=%s\n' "${ADMIN_PASSWORD}" > "${STORE}/admin.env"
        cat > "${STORE}/credentials.txt" <<NOTE_EOF
SHKeeper on this account
========================

The administrator was given this password on the first deploy, before the
site was reachable, so the first-visitor /set-password page is closed.

ADMIN LOGIN
  URL:      <this account's URL>/login
  Username: admin
  Password: ${ADMIN_PASSWORD}

PROMETHEUS /metrics (HTTP Basic)
  Username: metrics
  Password: ${METRICS_PASSWORD}

After the first login SHKeeper asks whether to encrypt wallet keys; choose
it and keep that password safe. No coin is enabled: each coin needs its own
SHKeeper wallet server and a node, which this account does not run. To use
one you run elsewhere, set e.g. BTC_WALLET=enabled, BTC_API_SERVER_HOST,
BTC_SERVER_PORT, BTC_USERNAME and BTC_PASSWORD in the panel's environment.
NOTE_EOF
    )
    say "secrets and admin credentials written to ${STORE}"
else
    say "reusing the secrets in ${STORE}"
fi

# Panel-overridable defaults. BTC, LTC and DOGE are on unless disabled and
# would point at backends this account does not run; every other coin is opt-in.
touch .env
write_default() {
    grep -q "^$1=" .env 2>/dev/null || printf '%s=%s\n' "$1" "$2" >> .env
}
write_default BTC_WALLET disabled
write_default LTC_WALLET disabled
write_default DOGE_WALLET disabled
