#!/bin/bash
set -e
cd ~/project

# 1. .env, written before anything reads the checkout.
#
#    Two things depend on this file existing by now, and both of them run
#    later in the same deploy:
#
#      EnvSidecars       reads .env.example and then .env, live values last,
#                        and turns `DB_CONNECTION=mysql` into a mariadb:11
#                        sidecar with a volume. Winter's .env.example says
#                        mysql, so the first deploy of this repository started
#                        a database server for an application that migrates
#                        onto a 300 KB SQLite file in under a second. On a
#                        3.7 GB host with the whole account capped at 1800 MB
#                        that is the difference between comfortable and tight.
#
#      ProjectEnvironment copies .env.example to .env only when there is no
#                        .env — `if ($baseContents !== null && !fileExists)`.
#                        So writing one here is not a race with it; it is how
#                        you win.
#
#    APP_KEY is generated here, on the host, and not by `artisan key:generate`
#    in the install stage — because by then it is too late. The generated
#    service loads this file through `env_file:`, and Compose reads it when the
#    container is *created*: an `APP_KEY=` line makes APP_KEY a real, empty
#    environment variable, and Laravel's Dotenv is immutable, so it never
#    overwrites one. key:generate wrote a perfectly good key into .env and
#    every request still answered
#
#      Illuminate\Encryption\MissingAppKeyException: No application encryption
#      key has been specified.
#
#    base64:<32 raw bytes> is the only shape Laravel's Encrypter accepts for
#    config/app.php's AES-256-CBC. Not hex, not a bare string.
#
# 2. Durable state. ~/project is /app and is emptied before every deploy, so
#    the key, the SQLite database, storage/app (uploads, media) and the admin
#    password record live in ~/.panelalpha/winter, mounted at /pa-data by the
#    compose override. A key regenerated per deploy would invalidate every
#    session and encrypted value; a database kept in storage/ was simply gone.
STORE="${HOME}/.panelalpha/winter"
mkdir -p "${STORE}/storage-app"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
if [ ! -s "${STORE}/app.env" ]; then
    (
        umask 077
        # An older deploy's key, when this checkout was not wiped.
        if grep -q '^APP_KEY=base64:' .env 2>/dev/null; then
            grep -m1 '^APP_KEY=' .env > "${STORE}/app.env"
        else
            printf 'APP_KEY=base64:%s\n' "$(openssl rand -base64 32)" > "${STORE}/app.env"
        fi
    )
fi
chmod 600 "${STORE}/app.env"

# Guarded on the file, so an operator's edits survive a rebuild that does not
# wipe. After a wipe it is rewritten, with the stored key.
if [ ! -f .env ]; then
    WINTER_APP_KEY=$(sed -n 's/^APP_KEY=//p' "${STORE}/app.env")
    sed -e 's|^DB_CONNECTION=.*|DB_CONNECTION=sqlite|' \
        -e 's|^DB_DATABASE=.*|DB_DATABASE=/pa-data/database.sqlite|' \
        -e 's|^APP_DEBUG=.*|APP_DEBUG=false|' \
        -e "s|^APP_KEY=.*|APP_KEY=${WINTER_APP_KEY}|" \
        .env.example > .env
    unset WINTER_APP_KEY
    # 644, the mode ProjectEnvironment gives the .env it writes itself. Not
    # 600: EnvSidecars reads this file from the engine's own process, as a
    # different user, and a .env it cannot open is a .env that says mysql —
    # which brings the sidecar back.
    chmod 644 .env
    echo "[winter] wrote .env: SQLite at ~/.panelalpha/winter/database.sqlite"
fi

# 3. The SQLite file itself. Laravel 9 will not create it:
#
#      Illuminate\Database\SQLiteConnector: Database file at path [...] does
#      not exist. Ensure this is an absolute path to the database.
#
#    and `winter:up` is the first thing to open it. Empty is a valid SQLite
#    database; the migration builds it. A database an older deploy left in
#    storage/ (a rebuild that did not wipe) is moved over instead.
if [ ! -f "${STORE}/database.sqlite" ]; then
    if [ -s storage/database.sqlite ]; then
        cp -p storage/database.sqlite "${STORE}/database.sqlite"
    else
        : > "${STORE}/database.sqlite"
        echo "[winter] created an empty database.sqlite for winter:up"
    fi
fi
chmod 600 "${STORE}/database.sqlite"

# 4. storage/app, seeded once from the checkout so its directory skeleton is
#    there; the override mounts it over /app/storage/app.
if [ -z "$(ls -A "${STORE}/storage-app")" ]; then
    cp -a storage/app/. "${STORE}/storage-app/"
fi
