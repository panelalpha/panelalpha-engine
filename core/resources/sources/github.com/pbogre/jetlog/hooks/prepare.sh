#!/bin/bash
set -e

# Everything the recipe generates lives in ~/.panelalpha/jetlog: ~/project is
# re-cloned and wiped on every redeploy (engine#173), so a secret or the DB
# written there would be lost. ~/.panelalpha is the one account-owned dir that
# survives a rebuild.
PA_DIR="${HOME}/.panelalpha/jetlog"
DATA_DIR="${PA_DIR}/data"
ENV_FILE="${PA_DIR}/jetlog.env"
CRED_FILE="${PA_DIR}/credentials.txt"
IMAGE="pbogre/jetlog:latest"

mkdir -p "${DATA_DIR}"
chmod 700 "${PA_DIR}"

# Pre-pull once so `compose up` starts fast and the seed helper below can run.
docker pull "${IMAGE}"

# First deploy only. The env file's presence is the "already initialised" flag;
# it is account-owned in ~/.panelalpha (never inside the /data mount, which the
# container chowns), so this check is reliable on every later redeploy. An
# existing jetlog.db holds the customer's flights and is never touched again.
if [ ! -f "${ENV_FILE}" ]; then
    # SECRET_KEY signs the JWTs; generated once and reused so tokens survive a
    # redeploy. PUID/PGID are the account's own ids so the container chowns the
    # bind mount back to files the account (and SFTP) can read.
    SECRET_KEY=$(openssl rand -hex 32)
    ADMIN_PASSWORD=$(openssl rand -base64 24 | tr -d '/+=' | cut -c1-24)

    umask 077
    {
        printf 'SECRET_KEY=%s\n' "${SECRET_KEY}"
        printf 'PUID=%s\n' "$(id -u)"
        printf 'PGID=%s\n' "$(id -g)"
    } > "${ENV_FILE}"
    chmod 600 "${ENV_FILE}"

    # Rotate the shipped admin:admin default before the app is ever exposed.
    # Pre-seeding jetlog.db with just the users table (holding a strong admin)
    # makes the container take its "database exists" path, so create_first_user()
    # — which would insert admin:admin — never runs; it creates the flights
    # table and loads airports/airlines itself. Run as the account uid so the
    # seeded file is account-owned from the start. argon2 is the image's own
    # hasher, so the hash matches what jetlog verifies against.
    docker run --rm \
        --user "$(id -u):$(id -g)" \
        -e PW="${ADMIN_PASSWORD}" \
        -v "${DATA_DIR}:/seed" \
        --entrypoint python "${IMAGE}" -c '
import os, sqlite3
from argon2 import PasswordHasher
h = PasswordHasher().hash(os.environ["PW"])
c = sqlite3.connect("/seed/jetlog.db")
c.execute("""CREATE TABLE users (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    username      TEXT NOT NULL UNIQUE COLLATE NOCASE,
    password_hash TEXT NOT NULL,
    is_admin      BIT NOT NULL DEFAULT 0,
    last_login    DATETIME,
    created_on    DATETIME NOT NULL DEFAULT current_timestamp
)""")
c.execute("INSERT INTO users (username, password_hash, is_admin) VALUES (?, ?, 1)", ["admin", h])
c.commit(); c.close()
'

    umask 077
    cat > "${CRED_FILE}" <<EOF
Jetlog admin account (generated $(date -u +%FT%TZ))
url:      set by PanelAlpha (your project's public domain)
username: admin
password: ${ADMIN_PASSWORD}

Change this password from Settings after first login.
EOF
    chmod 600 "${CRED_FILE}"
fi
