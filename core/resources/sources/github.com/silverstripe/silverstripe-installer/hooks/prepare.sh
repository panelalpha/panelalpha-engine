#!/bin/bash
# Runs on the account after the clone and before the build, as the account
# user, with ~/project as the working directory.
#
# Two jobs, both about things the build must not be left to decide:
#
#  1. the administrator password, generated once and kept in the account's
#     home rather than in ~/project, which ProjectTree::clearContents() empties
#     on every redeploy (engine#173);
#  2. a .env of our own, so the engine does not copy .env.example into place.
#     That file ships SS_DATABASE_SERVER="localhost", SS_DATABASE_USERNAME=
#     "<user>" and SS_ENVIRONMENT_TYPE="dev"; the generated compose names .env
#     in env_file:, and env_file is read when the container is created -- so
#     those placeholders would be in the environment before the entrypoint had
#     a chance to say otherwise, and `dev` prints the database password into
#     the browser on any error.
#
# Nothing here globs docker-compose.*.yml: the engine has already written its
# own docker-compose.override.yml into this directory by the time this runs,
# and a glob would eat it.
#
# Every pipeline below is written so it cannot fail under `pipefail`. A first
# draft used `find ... | grep -v | head -n1` to guess the domain, and on an
# account where nothing matched the empty `grep` exited 1, took the command
# substitution with it, and failed the whole deploy with no output at all.
set -euo pipefail

secrets_dir="$HOME/.panelalpha/silverstripe"
admin_env="$secrets_dir/admin.env"

mkdir -p "$secrets_dir"
chmod 700 "$secrets_dir"

if [ ! -s "$admin_env" ]; then
    # 24 random bytes, base64, stripped to the alphanumerics: ~30 characters,
    # and no stage of the pipeline is a reader that can be killed by SIGPIPE.
    password="$(head -c 24 /dev/urandom | base64 | tr -dc 'A-Za-z0-9')"

    (
        umask 077
        cat > "$admin_env" <<EOF
# Generated once by the PanelAlpha SilverStripe recipe. Kept outside ~/project
# because a redeploy empties that directory. Delivered to the container as a
# second env_file:, never written into the application tree.
#
# The login is admin@<the site's main domain>. It is not recorded here because
# this hook cannot know it: nothing in the account names the domain yet when
# the hook runs -- the compose file that carries SERVERNAME, and the ~/<domain>
# directory, are both written later -- so the install stage derives it from
# SERVERNAME inside the container and prints it into the deploy log.
PA_SS_ADMIN_PASSWORD=$password
EOF
    )
fi
chmod 600 "$admin_env"

echo "[panelalpha] SilverStripe administrator password: $admin_env (0600)" >&2

# Our .env: no credentials in it, only the two settings that have to be true
# before the first request. The database reaches SilverStripe through the
# process environment instead -- see files/.panelalpha/env.sh.
cat > .env <<'EOF'
# Written by the PanelAlpha SilverStripe recipe, replacing the copy of
# .env.example the engine would otherwise make. Database credentials are NOT
# here: the account's own MySQL is provisioned by the engine and exported into
# the entrypoint's shell by .panelalpha/env.sh, so nothing secret is stored in
# this directory.
SS_ENVIRONMENT_TYPE=live
SS_DATABASE_CLASS=MySQLDatabase
EOF
chmod 644 .env
