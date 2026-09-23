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
# The repository ships no .env, so this file is both the credential store and
# what the generated compose service reads PRETIX_ADMIN_* from through
# env_file:. Written once and never rewritten: the data volume outlives the
# checkout, and a regenerated password would be one nobody was ever told --
# the script only touches the account while its password is still `admin`.
#
# Everything else pretix needs a secret for it generates itself. SECRET_KEY is
# written to /data/.secret on first boot (settings.py) and read back from there
# afterwards, which is the same volume the database lives on -- there is
# nothing for this hook to add.
if [ ! -f .env ]; then
    cat > .env <<EOF
PRETIX_ADMIN_EMAIL=admin@localhost
PRETIX_ADMIN_PASSWORD=$(openssl rand -base64 18 | tr -d '/+=' | cut -c1-20)
EOF
    chmod 600 .env
fi
