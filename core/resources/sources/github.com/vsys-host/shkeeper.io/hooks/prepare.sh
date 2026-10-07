#!/bin/bash
# Generate the session key and the /metrics credentials once, where a redeploy
# will not wipe them (~/project is emptied). The admin login is the
# engine's (`credentials:` in panelalpha.yaml), in ~/.panelalpha/app-credentials.env.
set -e
cd ~/project

say() { echo "[shkeeper] $*" >&2; }

STORE="${HOME}/.panelalpha/shkeeper"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

rnd() { openssl rand -base64 "$1" | tr -d '\n=/+'; }

if [ ! -f "${STORE}/app.env" ]; then
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
    )
    say "secrets written to ${STORE}"
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
