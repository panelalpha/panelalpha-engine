#!/bin/bash
set -e

# Everything the recipe generates lives in ~/.panelalpha/jetlog: ~/project is
# re-cloned and wiped on every redeploy, so a secret or the DB
# written there would be lost. ~/.panelalpha is the one account-owned dir that
# survives a rebuild.
PA_DIR="${HOME}/.panelalpha/jetlog"
DATA_DIR="${PA_DIR}/data"
ENV_FILE="${PA_DIR}/jetlog.env"
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
    # JETLOG_ADMIN_USER / JETLOG_ADMIN_PASSWORD: the engine's (`credentials:`),
    # written before this hook.
    set -a; . "${HOME}/.panelalpha/app-credentials.env"; set +a

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
        -e PW="${JETLOG_ADMIN_PASSWORD}" -e PU="${JETLOG_ADMIN_USER}" \
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
c.execute("INSERT INTO users (username, password_hash, is_admin) VALUES (?, ?, 1)", [os.environ["PU"], h])
c.commit(); c.close()
'
elif [ -f "${PA_DIR}/credentials.txt" ]; then
    # Once, on an account the old recipe seeded: its password was in
    # credentials.txt (not dotenv, so the engine could not adopt it). Set the
    # admin to the engine's login, then drop the old file so this never repeats.
    set -a; . "${HOME}/.panelalpha/app-credentials.env"; set +a
    docker run --rm \
        --user "$(id -u):$(id -g)" \
        -e PW="${JETLOG_ADMIN_PASSWORD}" -e PU="${JETLOG_ADMIN_USER}" \
        -v "${DATA_DIR}:/seed" \
        --entrypoint python "${IMAGE}" -c '
import os, sqlite3
from argon2 import PasswordHasher
h = PasswordHasher().hash(os.environ["PW"])
c = sqlite3.connect("/seed/jetlog.db")
c.execute("UPDATE users SET password_hash = ? WHERE username = ?", [h, os.environ["PU"]])
c.commit(); c.close()
'
    rm -f "${PA_DIR}/credentials.txt"
    echo "[jetlog] admin password handed over to the engine's login" >&2
fi
