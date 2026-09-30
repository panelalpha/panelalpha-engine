#!/bin/bash
# Generate the secrets once, where a redeploy will not wipe them (~/project is
# emptied on every deploy, engine#173), and seed .env. The admin login is the
# engine's (`credentials:` in panelalpha.yaml), in ~/.panelalpha/app-credentials.env.
set -e
cd ~/project

say() { echo "[timetracker] $*" >&2; }

STORE="${HOME}/.panelalpha/timetracker"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"

rnd() { openssl rand -base64 "$1" | tr -d '\n=/+'; }

if [ ! -f "${STORE}/db.env" ] || [ ! -f "${STORE}/app.env" ]; then
    PG_PASSWORD="$(rnd 24)"
    (
        umask 077
        printf 'POSTGRES_PASSWORD=%s\n' "${PG_PASSWORD}" > "${STORE}/db.env"
        # SETTINGS_ENCRYPTION_KEY is a Fernet key (urlsafe base64 of 32 bytes).
        cat > "${STORE}/app.env" <<ENV_EOF
# Written once: the database holds this password, sessions are signed with
# SECRET_KEY and stored integration secrets are encrypted with the Fernet key.
SECRET_KEY=$(openssl rand -hex 32)
SETTINGS_ENCRYPTION_KEY=$(openssl rand -base64 32 | tr '+/' '-_')
DATABASE_URL=postgresql+psycopg2://timetracker:${PG_PASSWORD}@db:5432/timetracker
ENV_EOF
    )
    say "secrets written to ${STORE}"
else
    say "reusing the secrets in ${STORE}"
fi

# Defaults the panel's env vars can override; nothing secret here.
touch .env
write_default() {
    grep -q "^$1=" .env 2>/dev/null || printf '%s=%s\n' "$1" "$2" >> .env
}
write_default TZ UTC
write_default CURRENCY EUR
write_default ENABLE_TELEMETRY false
