#!/bin/bash
set -e
cd ~/project

# The one thing the compose file cannot supply for itself, and a security fix
# rather than a convenience: pretix's first migration creates admin@localhost
# with the password `admin` (pretixbase/0001_initial.py, `initial_user`). A
# pretix that has only been migrated is open to anyone who has read its
# repository, and PRETIX_REGISTRATION defaults to false so that account is also
# the only way in. files/panelalpha-admin-password.py replaces that password
# with this one on boot.
#
# The repository ships no .env, so .env is what the generated compose service
# reads PRETIX_ADMIN_* from through env_file:. The password itself lives in
# ~/.panelalpha/pretix/admin.env, generated once, because ~/project (.env
# included) is emptied on every deploy while the data volume outlives it: a
# password regenerated there would be recorded but never set -- the script only
# touches the account while its password is still `admin`.
#
# Everything else pretix needs a secret for it generates itself. SECRET_KEY is
# written to /data/.secret on first boot (settings.py) and read back from there
# afterwards, which is the same volume the database lives on -- there is
# nothing for this hook to add.
STORE="${HOME}/.panelalpha/pretix"
mkdir -p "${STORE}"
chmod 700 "${HOME}/.panelalpha" "${STORE}"
if [ ! -s "${STORE}/admin.env" ]; then
    (
        umask 077
        # A rebuild that did not wipe still has the password an older deploy set.
        if grep -q '^PRETIX_ADMIN_PASSWORD=.' .env 2>/dev/null; then
            grep -E '^PRETIX_ADMIN_(EMAIL|PASSWORD)=' .env > "${STORE}/admin.env"
        else
            printf 'PRETIX_ADMIN_EMAIL=admin@localhost\nPRETIX_ADMIN_PASSWORD=%s\n' \
                "$(openssl rand -base64 18 | tr -d '/+=' | cut -c1-20)" > "${STORE}/admin.env"
        fi
    )
fi
chmod 600 "${STORE}/admin.env"
cp "${STORE}/admin.env" .env
chmod 600 .env
