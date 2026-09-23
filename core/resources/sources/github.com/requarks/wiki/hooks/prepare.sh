#!/bin/bash
set -e
cd ~/project

# Three values the compose file cannot supply for itself. The repository ships
# no .env, so this file is both the credential store and what compose reads for
# interpolation.
#
# Written once and never rewritten: the PostgreSQL volume outlives the
# checkout, so a regenerated POSTGRES_PASSWORD would lock Wiki.js out of its own
# database, and a regenerated admin password would be one the `users` table
# never learns -- files/panelalpha-setup.sh only runs the wizard while the wiki
# is still unconfigured.
#
# Nothing else needs a secret here: Wiki.js generates its own sessionSecret and
# its RSA keypair during /finalize and keeps them in the `settings` table, on
# the same volume as everything else.
if [ ! -f .env ]; then
    cat > .env <<EOF
POSTGRES_PASSWORD=$(openssl rand -hex 16)
WIKI_ADMIN_EMAIL=admin@example.com
WIKI_ADMIN_PASSWORD=$(openssl rand -base64 18 | tr -d '/+=' | cut -c1-20)
EOF
    chmod 600 .env
fi
