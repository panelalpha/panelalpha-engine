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
#    Guarded on the file, not on its contents: regenerating APP_KEY on a
#    redeploy would invalidate every session and encrypted value the account
#    has written, and an operator's own edits have to survive too.
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
#    with `printenv APP_KEY` in the container printing an empty line and
#    `grep ^APP_KEY /app/.env` printing the key. Measured; that was the second
#    thing wrong with this deploy after `migrate --force`.
#
#    base64:<32 raw bytes> is the only shape Laravel's Encrypter accepts for
#    config/app.php's AES-256-CBC. Not hex, not a bare string.
if [ ! -f .env ]; then
    WINTER_APP_KEY="base64:$(openssl rand -base64 32)"
    sed -e 's|^DB_CONNECTION=.*|DB_CONNECTION=sqlite|' \
        -e 's|^DB_DATABASE=.*|DB_DATABASE=/app/storage/database.sqlite|' \
        -e 's|^APP_DEBUG=.*|APP_DEBUG=false|' \
        -e "s|^APP_KEY=.*|APP_KEY=${WINTER_APP_KEY}|" \
        .env.example > .env
    unset WINTER_APP_KEY
    # 644, the mode ProjectEnvironment gives the .env it writes itself. Not
    # 600: EnvSidecars reads this file from the engine's own process, as a
    # different user, and a .env it cannot open is a .env that says mysql —
    # which brings the sidecar back.
    chmod 644 .env
    echo "[winter] wrote .env: SQLite at storage/database.sqlite"
fi

# 2. The SQLite file itself. Laravel 9 will not create it:
#
#      Illuminate\Database\SQLiteConnector: Database file at path
#      [/app/storage/database.sqlite] does not exist. Ensure this is an
#      absolute path to the database.
#
#    and `winter:up` is the first thing to open it, so without this the
#    install stage fails and the container restart-loops exactly as it did
#    before this recipe existed. Empty is a valid SQLite database; the
#    migration builds it.
#
#    storage/ is in the checkout already. Kept 664 rather than 600 because the
#    engine walks the tree as a different user while working out the document
#    root, and a file it cannot stat stops the deploy.
mkdir -p storage
if [ ! -f storage/database.sqlite ]; then
    : > storage/database.sqlite
    echo "[winter] created an empty storage/database.sqlite for winter:up"
fi
chmod 664 storage/database.sqlite
