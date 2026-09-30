#!/bin/bash
set -e
cd ~/project

# The one value the compose file cannot state for itself. The repository ships
# no .env, and the generated app service reads one through `env_file:`, so .env
# is the way in -- but ~/project, .env included, is emptied on every deploy. The
# value lives in ~/.panelalpha/vikunja/secret.env, generated once, and .env is
# copied from it each time.
#
# Generated once: a redeploy must not roll the secret out from under the
# sessions and API tokens signed with it, and the database volume outlives the
# checkout.
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
STORE="${HOME}/.panelalpha/vikunja"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
# An account deployed before the store existed keeps the secret its .env has,
# when the checkout was not wiped.
if [ ! -s "${STORE}/secret.env" ] && grep -q '^VIKUNJA_SERVICE_SECRET=.' .env 2>/dev/null; then
    ( umask 077; grep '^VIKUNJA_SERVICE_SECRET=' .env | head -1 > "${STORE}/secret.env" )
fi
if [ ! -s "${STORE}/secret.env" ]; then
    ( umask 077
      printf 'VIKUNJA_SERVICE_SECRET=%s\n' \
          "$(head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n')" > "${STORE}/secret.env" )
fi
chmod 600 "${STORE}/secret.env"
cp "${STORE}/secret.env" .env
chmod 600 .env

# The version mage stamps into the binary. Without RELEASE_VERSION it runs
# `git describe`, and the engine leaves .git out of the build context
# (engine#413), so the build dies with "not a git repository". Read here,
# where .git is still present; the override passes it as a build arg.
RELEASE_VERSION=$(git -c safe.directory="$PWD" describe --tags --always --abbrev=10 2>/dev/null || true)
printf 'RELEASE_VERSION=%s\n' "${RELEASE_VERSION:-dev}" >> .env

# Fetch the helper image now rather than during the build. `docker compose
# up -d` pulls the images it does not have while it builds the ones it does not
# have either, and this build is an xgo cross-compile whose link step is the
# largest single allocation the account makes: it died with
# `ResourceExhausted: ... cannot allocate memory` inside a 2500 MB account with
# `Image alpine:3 Pulling` in the same output. Eight megabytes, seconds, and
# nothing else is running yet. Never fatal: if it fails, compose pulls it the
# old way.
docker pull alpine:3 >/dev/null 2>&1 || true
