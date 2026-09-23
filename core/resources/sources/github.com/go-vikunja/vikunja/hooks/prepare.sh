#!/bin/bash
set -e
cd ~/project

# The one value the compose file cannot state for itself. The repository ships
# no .env, and the generated app service reads one through `env_file:`, so this
# file is both the credential store and the way in.
#
# Written once: a redeploy must not roll the secret out from under the sessions
# and API tokens signed with it, and the database volume outlives the checkout.
#
# service.secret signs every JWT and API token. Left unset, Vikunja calls
# generateServiceSecretIfEmpty() and makes a fresh one on each boot, so a
# restart logs everyone out and invalidates their tokens. `od` rather than
# openssl: this runs in the account's container, and coreutils is the smaller
# assumption. (On a checkout older than the service.secret rename the key is
# VIKUNJA_SERVICE_JWTSECRET, which is still read and migrated onto this one.)
#
# The other value Vikunja cannot start without -- service.publicurl -- is not
# here, because it cannot be: this hook runs before detection, so the generated
# compose file the engine writes the account's public URL into does not exist
# yet, and neither does the account's own ~/<domain>/ directory. The `init`
# service picks it up at container start instead; see files/panelalpha-init.sh.
if [ ! -f .env ]; then
    printf 'VIKUNJA_SERVICE_SECRET=%s\n' \
        "$(head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n')" > .env
    chmod 600 .env
fi

# Fetch the helper image now rather than during the build. `docker compose
# up -d` pulls the images it does not have while it builds the ones it does not
# have either, and this build is an xgo cross-compile whose link step is the
# largest single allocation the account makes: it died with
# `ResourceExhausted: ... cannot allocate memory` inside a 2500 MB account with
# `Image alpine:3 Pulling` in the same output. Eight megabytes, seconds, and
# nothing else is running yet. Never fatal: if it fails, compose pulls it the
# old way.
docker pull alpine:3 >/dev/null 2>&1 || true
