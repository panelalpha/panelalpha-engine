#!/bin/bash
# Runs on the account after the clone and before the build.
set -e
cd ~/project

say() { echo "[panelalpha] plainpad: $*"; }

# ---------------------------------------------------------------------------
# 1. The workstation compose file
# ---------------------------------------------------------------------------
# docker-compose.yml at the repository root is the maintainer's laptop stack:
# a php-fpm built from docker/php-fpm, an nginx, mysql 8 with
# MYSQL_ROOT_PASSWORD=secret, phpMyAdmin and Mailpit, every one of them
# publishing a port from a root .env that does not exist here. None of it is
# the application. The engine writes its own docker-compose.yml over this one
# anyway, but it reads the file first -- so it is moved aside rather than left
# to be read. docker-compose.override.yml is this recipe's own and stays.
for f in docker-compose.yml docker-compose.yaml compose.yml compose.yaml; do
    if [ -f "$f" ]; then
        mv -f "$f" "${f%.*}.workstation.${f##*.}"
        say "set aside $f (the maintainer's laptop stack, not the application)"
    fi
done

# ---------------------------------------------------------------------------
# 2. The web installer
# ---------------------------------------------------------------------------
# server/public/setup.php writes .env from a form and then calls
# POST /api.php/v1, which runs `migrate:fresh --seed`. It guards itself only on
# `.env` not existing, so between the clone and the moment the engine writes
# server/.env it is a public page that hands the first visitor a database of
# their choosing and an admin account. The engine installs this application
# itself -- migrate runs in the install stage, before Apache binds -- so the
# installer has no job here and is deleted rather than left to race.
if [ -f server/public/setup.php ]; then
    rm -f server/public/setup.php
    say "removed server/public/setup.php (the engine installs the application itself)"
fi

# ---------------------------------------------------------------------------
# 3. The admin password
# ---------------------------------------------------------------------------
# database/seeders/UsersSeeder.php creates admin@example.org with the password
# `12345678`, printed in the repository's own README and in the seeder's own
# output. A deploy that stops there is a public HTTPS note-taking application
# whose credentials are on GitHub. The engine generates one per account
# (`credentials:` in panelalpha.yaml) and writes ~/.panelalpha/app-credentials.env
# before this hook runs.
creds=~/.panelalpha/app-credentials.env
hash_file=server/.panelalpha-admin.hash
key_store=~/.panelalpha/plainpad-app-key
key_file=server/.panelalpha-app-key

# The install stage runs inside the container, where only ~/project/server is
# mounted -- it cannot read ~/.panelalpha. So the *hash*, never the password,
# is handed across in a file the install script consumes and deletes. bcrypt
# cost 10 is config/hashing.php's own default, which is what Illuminate's
# BcryptHasher::check() will verify with password_verify().
password=$(set -a; . "$creds"; printf '%s' "${PLAINPAD_ADMIN_PASSWORD:-}")
if [ -n "$password" ]; then
    umask 077
    PA_PLAINPAD_PASSWORD="$password" php -r \
        'echo password_hash(getenv("PA_PLAINPAD_PASSWORD"), PASSWORD_BCRYPT, ["cost" => 10]), "\n";' \
        > "$hash_file"
    chmod 600 "$hash_file"
else
    say "no password in ~/.panelalpha/app-credentials.env; the install stage will leave the account alone" >&2
fi

# ---------------------------------------------------------------------------
# 4. APP_KEY
# ---------------------------------------------------------------------------
# The engine generates one for a project whose `.env.example` is at the
# repository root -- ProjectEnvironment::apply() runs withGeneratedSecrets()
# over it -- but Plainpad's is at server/.env.example, and the nested copy is
# taken verbatim (ProjectEnvironment::materializeNestedEnvExamples()). So
# server/.env arrives with the literal `APP_KEY={KEY}` the template ships.
#
# The laravel manifest's `key:generate` fixes that on the *first* deploy and
# only then -- it is an install-stage command, deliberately, because rotating
# the key later would throw away every encrypted value. But every deploy
# re-clones ~/project, so the second deploy gets a fresh server/.env with
# `{KEY}` in it again and nothing to replace it: from then on the application
# runs with a key that is not a key, one resolve of the encrypter away from a
# 500. Measured on a redeploy of this recipe before this existed.
#
# So the key is generated once, here, kept where the checkout cannot destroy
# it, and handed to the install stage the same way the password hash is.
if [ ! -f "$key_store" ]; then
    umask 077
    php -r 'echo "base64:", base64_encode(random_bytes(32)), "\n";' > "$key_store"
    chmod 600 "$key_store"
    say "generated an APP_KEY; it is in ~/.panelalpha/plainpad-app-key"
fi
chmod 600 "$key_store" 2>/dev/null || true
umask 077
cp "$key_store" "$key_file"
chmod 600 "$key_file"
